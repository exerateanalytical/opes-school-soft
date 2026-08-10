<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\YearEnd;

use App\Modules\Accounting\Domain\AccountingPeriodStatus;
use App\Modules\Accounting\Domain\FiscalYearStatus;
use App\Modules\Accounting\Domain\YearEndChecklistStatus;
use App\Modules\Accounting\Domain\YearEndItemStatus;
use App\Modules\Accounting\Domain\YearEndStep;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\ResultAppropriation;
use App\Modules\Accounting\Models\YearEndChecklist;
use App\Modules\Accounting\Models\YearEndChecklistItem;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/02-accounting.md §17.2/§17.3 - the PREFLIGHT. Run it, and it
 * tells you exactly what stands between this exercice and a close.
 *
 * Two distinct things come back, and conflating them is the mistake this
 * Action exists to avoid:
 *
 *  1. the CHECKLIST - §17.2's thirteen steps, each with its own status,
 *     sign-off, waiver and evidence. This is the auditor's document;
 *  2. the BLOCKERS - the structural facts that make a step impossible right
 *     now: no accounting period covers 31 December, the next exercice does
 *     not exist, the trial balance does not validate. A blocker is not a
 *     checklist item and cannot be waived; it is fixed or the close waits.
 *
 * The checklist is CREATED here on first run (idempotent: the row is unique
 * per fiscal year and missing items are back-filled, so a build that adds a
 * step to §17.2 later picks it up on the next evaluation without losing a
 * single existing sign-off).
 *
 * YE-4 lives here too: the automated items - trial balance, closing entry,
 * appropriation, à-nouveaux - have their status RECOMPUTED from reality on
 * every evaluation. They cannot be ticked by a human and they cannot drift:
 * if the closing entry is reversed, the item goes back to pending the next
 * time anyone looks.
 *
 * @phpstan-type Blocker array{code: string, message: string}
 */
final class EvaluateYearEndChecklist
{
    public function __construct(private readonly ValidateYearEndTrialBalance $validator) {}

    /**
     * @return array{
     *     fiscal_year: FiscalYear,
     *     checklist: YearEndChecklist,
     *     items: list<YearEndChecklistItem>,
     *     validation: array<string, mixed>,
     *     blockers: list<Blocker>,
     *     next_fiscal_year: FiscalYear|null,
     *     can_close: bool,
     * }
     */
    public function handle(int $fiscalYearId, Actor $actor): array
    {
        Gate::authorize(Permission::LedgerView->value);

        /** @var FiscalYear $fiscalYear */
        $fiscalYear = FiscalYear::query()->findOrFail($fiscalYearId);

        $validation = $this->validator->handle($fiscalYearId);

        $checklist = DB::transaction(function () use ($fiscalYear, $validation, $actor): YearEndChecklist {
            $checklist = $this->ensureChecklist((int) $fiscalYear->getKey());

            $this->refreshAutomatedItems($checklist, $fiscalYear, $validation, $actor);
            $this->refreshHeaderStatus($checklist, $actor);

            return $checklist;
        });

        /** @var list<YearEndChecklistItem> $items */
        $items = $checklist->items()->get()->all();

        $nextFiscalYear = $this->nextFiscalYear($fiscalYear);

        $blockers = $this->blockers($fiscalYear, $nextFiscalYear, $validation, $items);

        return [
            'fiscal_year' => $fiscalYear,
            'checklist' => $checklist,
            'items' => $items,
            'validation' => $validation,
            'blockers' => $blockers,
            'next_fiscal_year' => $nextFiscalYear,
            'can_close' => $blockers === [] && $this->allMandatorySettled($items),
        ];
    }

    /** The exercice the à-nouveaux lands in: the one starting the day after. */
    public function nextFiscalYear(FiscalYear $fiscalYear): ?FiscalYear
    {
        return FiscalYear::query()
            ->whereDate('starts_on', $fiscalYear->ends_on->copy()->addDay()->toDateString())
            ->first();
    }

    private function ensureChecklist(int $fiscalYearId): YearEndChecklist
    {
        /** @var YearEndChecklist|null $existing */
        $existing = YearEndChecklist::query()
            ->where('fiscal_year_id', $fiscalYearId)
            ->lockForUpdate()
            ->first();

        $checklist = $existing ?? YearEndChecklist::query()->create([
            'fiscal_year_id' => $fiscalYearId,
            'status' => YearEndChecklistStatus::NotStarted->value,
        ]);

        /** @var list<string> $present */
        $present = $checklist->items()->pluck('code')->all();

        foreach (YearEndStep::ordered() as $step) {
            if (in_array($step->value, $present, true)) {
                continue;
            }

            $checklist->items()->create([
                'sequence' => $step->sequence(),
                'code' => $step->value,
                'title' => $step->title(),
                'title_fr' => $step->titleFr(),
                'is_mandatory' => $step->isMandatory(),
                'is_automated' => $step->isAutomated(),
                'status' => YearEndItemStatus::Pending->value,
            ]);
        }

        return $checklist;
    }

    /**
     * YE-4. An automated item's status is a FUNCTION of the ledger, never a
     * stored opinion about it. A waived item is left alone - a waiver is a
     * deliberate human override and re-deriving over it would erase the
     * reason someone wrote down.
     *
     * @param  array<string, mixed>  $validation
     */
    private function refreshAutomatedItems(
        YearEndChecklist $checklist,
        FiscalYear $fiscalYear,
        array $validation,
        Actor $actor,
    ): void {
        $appropriationPosted = ResultAppropriation::query()
            ->where('fiscal_year_id', $fiscalYear->getKey())
            ->where('status', ResultAppropriation::STATUS_APPROVED)
            ->whereNotNull('journal_entry_id')
            ->first();

        $next = $this->nextFiscalYear($fiscalYear);

        $facts = [
            YearEndStep::TrialBalance->value => [
                'satisfied' => ($validation['passed'] ?? false) === true,
                'evidence_type' => null,
                'evidence_id' => null,
                'validation_result' => $validation,
            ],
            YearEndStep::ClosingEntry->value => [
                'satisfied' => $fiscalYear->closing_entry_id !== null,
                'evidence_type' => 'journal_entry',
                'evidence_id' => $fiscalYear->closing_entry_id,
                'validation_result' => null,
            ],
            YearEndStep::ResultAppropriation->value => [
                'satisfied' => $appropriationPosted !== null,
                'evidence_type' => 'result_appropriation',
                'evidence_id' => $appropriationPosted === null ? null : (int) $appropriationPosted->getKey(),
                'validation_result' => null,
            ],
            YearEndStep::OpeningBalances->value => [
                'satisfied' => $next !== null && $next->opening_entry_id !== null,
                'evidence_type' => 'journal_entry',
                'evidence_id' => $next?->opening_entry_id,
                'validation_result' => null,
            ],
        ];

        /** @var YearEndChecklistItem $item */
        foreach ($checklist->items()->lockForUpdate()->get() as $item) {
            if (! $item->is_automated || $item->status === YearEndItemStatus::Waived) {
                continue;
            }

            $fact = $facts[$item->code] ?? null;

            if ($fact === null) {
                continue;
            }

            $satisfied = $fact['satisfied'] === true;

            $item->forceFill([
                'status' => $satisfied ? YearEndItemStatus::Completed->value : YearEndItemStatus::Pending->value,
                'completed_at' => $satisfied ? ($item->completed_at ?? now()) : null,
                'completed_by' => $satisfied ? $item->completed_by : null,
                'performed_by' => $satisfied ? ($item->performed_by ?? $actor->id) : $item->performed_by,
                'evidence_type' => $satisfied ? $fact['evidence_type'] : null,
                'evidence_id' => $satisfied ? $fact['evidence_id'] : null,
                'validation_result' => $fact['validation_result'] ?? $item->validation_result,
            ])->save();
        }
    }

    private function refreshHeaderStatus(YearEndChecklist $checklist, Actor $actor): void
    {
        /** @var list<YearEndChecklistItem> $items */
        $items = $checklist->items()->get()->all();

        $settled = $this->allMandatorySettled($items);

        $anyTouched = false;

        foreach ($items as $item) {
            if ($item->status !== YearEndItemStatus::Pending) {
                $anyTouched = true;

                break;
            }
        }

        $status = match (true) {
            $settled => YearEndChecklistStatus::Completed,
            $anyTouched => YearEndChecklistStatus::InProgress,
            default => YearEndChecklistStatus::NotStarted,
        };

        $checklist->forceFill([
            'status' => $status->value,
            'started_at' => $status === YearEndChecklistStatus::NotStarted
                ? null
                : ($checklist->started_at ?? now()),
            'completed_at' => $status === YearEndChecklistStatus::Completed ? ($checklist->completed_at ?? now()) : null,
            'completed_by' => $status === YearEndChecklistStatus::Completed
                ? ($checklist->completed_by ?? $actor->id)
                : null,
        ])->save();
    }

    /**
     * @param  list<YearEndChecklistItem>  $items
     */
    public function allMandatorySettled(array $items): bool
    {
        foreach ($items as $item) {
            if ($item->is_mandatory && ! $item->status->isSettled()) {
                return false;
            }
        }

        return $items !== [];
    }

    /**
     * @param  list<YearEndChecklistItem>  $items
     */
    private function isWaived(array $items, YearEndStep $step): bool
    {
        foreach ($items as $item) {
            if ($item->code === $step->value) {
                return $item->status === YearEndItemStatus::Waived;
            }
        }

        return false;
    }

    /**
     * The structural refusals. Each names exactly what is wrong and what
     * would fix it - §17.9's "every failure is actionable" applied to the
     * close as a whole rather than to the trial balance alone.
     *
     * @param  array<string, mixed>  $validation
     * @param  list<YearEndChecklistItem>  $items
     * @return list<Blocker>
     *
     * @phpstan-return list<Blocker>
     */
    private function blockers(FiscalYear $fiscalYear, ?FiscalYear $next, array $validation, array $items): array
    {
        $blockers = [];

        if (in_array($fiscalYear->status, [FiscalYearStatus::Planned, FiscalYearStatus::Closed], true)) {
            $blockers[] = [
                'code' => 'fiscal_year_status',
                'message' => sprintf(
                    'Fiscal year %s is %s. Only an open or closing exercice can run the year-end sequence.',
                    $fiscalYear->code,
                    $fiscalYear->status->value,
                ),
            ];
        }

        if ($fiscalYear->dsf_filed_at !== null) {
            $blockers[] = [
                'code' => 'dsf_filed',
                'message' => sprintf(
                    'The DSF for %s was filed on %s; the exercice is final and nothing further may be posted into it.',
                    $fiscalYear->code,
                    $fiscalYear->dsf_filed_at->toDateString(),
                ),
            ];
        }

        // The closing entry is dated `ends_on` (§18.1). Without a period
        // covering that date, DraftJournalEntry cannot resolve one and the
        // whole sequence dies at step 9 - so it is said here, up front.
        $closingPeriod = DB::table('accounting_periods')
            ->where('fiscal_year_id', $fiscalYear->getKey())
            ->whereDate('starts_on', '<=', $fiscalYear->ends_on->toDateString())
            ->whereDate('ends_on', '>=', $fiscalYear->ends_on->toDateString())
            ->first();

        if ($closingPeriod === null) {
            $blockers[] = [
                'code' => 'no_closing_period',
                'message' => sprintf(
                    'No accounting period covers %s, the closing date of %s. The §5.1 twelve-period calendar is incomplete; generate the missing periods before closing.',
                    $fiscalYear->ends_on->toDateString(),
                    $fiscalYear->code,
                ),
            ];
        } elseif ($closingPeriod->status !== AccountingPeriodStatus::Open->value) {
            $blockers[] = [
                'code' => 'closing_period_locked',
                'message' => sprintf(
                    'The period covering %s is %s. The year-end entries post through PostFromEvent, which uses the ordinary posting path and requires an OPEN period; reopen it (OpenAccountingPeriod) for the duration of the close, then soft- and hard-lock as steps 1 and 13.',
                    $fiscalYear->ends_on->toDateString(),
                    (string) $closingPeriod->status,
                ),
            ];
        }

        if ($next === null) {
            $blockers[] = [
                'code' => 'no_next_fiscal_year',
                'message' => sprintf(
                    'No fiscal year starts on %s, so the à-nouveaux (§18.2) has nowhere to land. Create the next exercice first (CreateFiscalYear).',
                    $fiscalYear->ends_on->copy()->addDay()->toDateString(),
                ),
            ];
        } else {
            $openingPeriod = DB::table('accounting_periods')
                ->where('fiscal_year_id', $next->getKey())
                ->whereDate('starts_on', '<=', $next->starts_on->toDateString())
                ->whereDate('ends_on', '>=', $next->starts_on->toDateString())
                ->first();

            if ($openingPeriod === null) {
                $blockers[] = [
                    'code' => 'no_opening_period',
                    'message' => sprintf(
                        'No accounting period of %s covers %s; the à-nouveaux entry cannot be dated into it.',
                        $next->code,
                        $next->starts_on->toDateString(),
                    ),
                ];
            } elseif ($openingPeriod->status !== AccountingPeriodStatus::Open->value) {
                $blockers[] = [
                    'code' => 'opening_period_locked',
                    'message' => sprintf(
                        'The first period of %s is %s; the à-nouveaux must be posted into an open period, before any user entry (§18.2).',
                        $next->code,
                        (string) $openingPeriod->status,
                    ),
                ];
            }
        }

        // A failing §17.9 validation blocks the close - UNLESS step 7 has
        // been waived with a reason by someone holding the permission. That
        // is what YE-2's waiver is FOR, and it is why the waiver list is
        // printed on the closing report: the school closed over a known
        // failure and said so, in writing, rather than the product either
        // pretending the check passed or making the close impossible.
        if (($validation['passed'] ?? false) !== true && ! $this->isWaived($items, YearEndStep::TrialBalance)) {
            $failing = [];

            /** @var list<array{code: string, status: string}> $checks */
            $checks = $validation['checks'] ?? [];

            foreach ($checks as $check) {
                if ($check['status'] === ValidateYearEndTrialBalance::STATUS_FAIL) {
                    $failing[] = $check['code'];
                }
            }

            $blockers[] = [
                'code' => 'trial_balance',
                'message' => sprintf(
                    'The §17.9 trial-balance validation does not pass: %s. Step 7 gates steps 8-13.',
                    implode(', ', $failing),
                ),
            ];
        }

        return $blockers;
    }
}
