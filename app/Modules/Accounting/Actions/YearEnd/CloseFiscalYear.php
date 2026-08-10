<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\YearEnd;

use App\Modules\Accounting\Domain\FiscalYearStatus;
use App\Modules\Accounting\Domain\YearEndItemStatus;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\YearEndChecklist;
use App\Modules\Accounting\Models\YearEndChecklistItem;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/02-accounting.md §17.3 YE-1 - the last act: `closing` becomes
 * `closed`.
 *
 * "`FiscalYear` may not move to `closed` while any mandatory item is
 * `pending` - in-Action, under `FOR UPDATE` on the checklist." That is
 * exactly what happens below, and the refusal NAMES the pending steps
 * rather than saying "checklist incomplete", because the operator's next
 * question is always "which one?".
 *
 * This Action is the counterpart of the existing `ReopenFiscalYear`, whose
 * unconditional DSF block it mirrors on the way in: a year whose DSF is
 * filed is already final, and re-closing it is meaningless.
 *
 * It does NOT hard-lock the periods. That is §17.2 step 13, it is
 * irreversible, and it belongs to the existing `CloseAccountingPeriod`
 * Action (`$hard = true`) invoked per period - deliberately not bundled
 * into a single button that turns an accidental click into a permanent
 * state.
 */
final class CloseFiscalYear
{
    public const PERMISSION = Permission::LedgerConfigure->value;

    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(int $fiscalYearId, Actor $actor): FiscalYear
    {
        Gate::authorize(self::PERMISSION);

        return DB::transaction(function () use ($fiscalYearId, $actor): FiscalYear {
            /** @var FiscalYear $fiscalYear */
            $fiscalYear = FiscalYear::query()->whereKey($fiscalYearId)->lockForUpdate()->firstOrFail();

            if ($fiscalYear->status === FiscalYearStatus::Closed) {
                throw new DomainException(sprintf('Fiscal year %s is already closed.', $fiscalYear->code));
            }

            if ($fiscalYear->status === FiscalYearStatus::Planned) {
                throw new DomainException(sprintf(
                    'Fiscal year %s has never been opened; there is nothing to close.',
                    $fiscalYear->code,
                ));
            }

            if ($fiscalYear->closing_entry_id === null) {
                throw new DomainException(sprintf(
                    'Fiscal year %s has no §18.1 closing entry. Classes 6/7/8 are still live; the exercice cannot be closed.',
                    $fiscalYear->code,
                ));
            }

            /** @var YearEndChecklist|null $checklist */
            $checklist = YearEndChecklist::query()
                ->where('fiscal_year_id', $fiscalYearId)
                ->lockForUpdate()
                ->first();

            if ($checklist === null) {
                throw new DomainException(
                    'No year-end checklist exists for this exercice. Run the preflight (EvaluateYearEndChecklist) first - YE-1 has nothing to check against.'
                );
            }

            /** @var list<YearEndChecklistItem> $pending */
            $pending = $checklist->items()
                ->where('is_mandatory', true)
                ->where('status', YearEndItemStatus::Pending->value)
                ->orderBy('sequence')
                ->get()
                ->all();

            if ($pending !== []) {
                $names = implode('; ', array_map(
                    static fn (YearEndChecklistItem $item): string => sprintf('%d. %s', $item->sequence, $item->title),
                    $pending,
                ));

                throw new DomainException(sprintf(
                    'YE-1: fiscal year %s cannot be closed while these mandatory steps are pending: %s. Complete them, or waive each with a reason.',
                    $fiscalYear->code,
                    $names,
                ));
            }

            $before = ['status' => $fiscalYear->status->value];

            $fiscalYear->forceFill([
                'status' => FiscalYearStatus::Closed->value,
                'closed_at' => now(),
                'closed_by' => $actor->id,
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Accounting',
                auditableType: FiscalYear::class,
                auditableId: (int) $fiscalYear->getKey(),
                before: $before,
                after: ['status' => FiscalYearStatus::Closed->value],
                actor: $actor,
            );

            return $fiscalYear->refresh();
        });
    }
}
