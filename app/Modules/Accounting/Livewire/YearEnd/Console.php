<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Livewire\YearEnd;

use App\Modules\Accounting\Actions\YearEnd\CloseFiscalYear;
use App\Modules\Accounting\Actions\YearEnd\CompleteYearEndChecklistItem;
use App\Modules\Accounting\Actions\YearEnd\EvaluateYearEndChecklist;
use App\Modules\Accounting\Actions\YearEnd\PostOpeningBalances;
use App\Modules\Accounting\Actions\YearEnd\PostResultAppropriation;
use App\Modules\Accounting\Actions\YearEnd\PostYearEndClosingEntry;
use App\Modules\Accounting\Actions\YearEnd\RecordResultAppropriation;
use App\Modules\Accounting\Actions\YearEnd\ValidateYearEndTrialBalance;
use App\Modules\Accounting\Actions\YearEnd\WaiveYearEndChecklistItem;
use App\Modules\Accounting\Actions\YearEnd\YearEndBalances;
use App\Modules\Accounting\Domain\YearEndStep;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\ResultAppropriation;
use App\Modules\Accounting\Models\YearEndChecklistItem;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Reporting\Support\PdfExport;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The Year-End Console - docs/specs/02-accounting.md §21.3 names it as one
 * of the five accounting screens, and §17.2/§18 are what it drives. Until
 * now the close Actions had no UI at all, which is the same as not existing
 * for the person who has to run a close in December.
 *
 * The screen is a mirror, not a brain: every guard, every refusal and every
 * status transition lives in the Actions (they are reachable from tinker, a
 * console command or a job and must refuse identically there). The component
 * catches DomainException and shows the message VERBATIM - those sentences
 * are the spec's own plain-language refusals and rewriting them here would
 * be a second, worse copy. This is exactly the convention
 * Operations\Livewire\RolloverWizard established.
 *
 * Authorisation is checked in mount() AND inside every Action - a Livewire
 * component can be reached without its route.
 */
#[Layout('layouts.app')]
final class Console extends Component
{
    #[Url(as: 'fy')]
    public string $fiscalYearId = '';

    /** The waiver being written, keyed by checklist item id. */
    public string $waivingItemId = '';

    public string $waiverReason = '';

    // --- Step 10, the appropriation form ----------------------------------

    public string $decisionBody = '';

    public string $decisionDate = '';

    public string $resolutionReference = '';

    /** @var array<int, array{account_id: string, amount: string, label: string}> */
    public array $appropriationLines = [];

    public string $statusMessage = '';

    public string $errorMessage = '';

    /** Which §17.9 check has its failing rows expanded. */
    public string $expandedCheck = '';

    public function mount(): void
    {
        Gate::authorize(Permission::LedgerView->value);

        if ($this->fiscalYearId === '') {
            $latest = FiscalYear::query()->orderByDesc('starts_on')->first();

            $this->fiscalYearId = $latest === null ? '' : (string) $latest->getKey();
        }

        if ($this->appropriationLines === []) {
            $this->appropriationLines = [['account_id' => '', 'amount' => '', 'label' => '']];
        }
    }

    // ---------------------------------------------------------------- steps

    public function runClosingEntry(PostYearEndClosingEntry $action): void
    {
        $this->runStep(function () use ($action): string {
            $entry = $action->handle($this->fiscalYear()->id, $this->actor());

            return sprintf('Closing entry posted: %s (%s).', (string) $entry->piece_no, $entry->label);
        });
    }

    public function runAppropriation(PostResultAppropriation $action): void
    {
        $this->runStep(function () use ($action): string {
            $entry = $action->handle($this->fiscalYear()->id, $this->actor());

            return sprintf('Result appropriation posted: %s.', (string) $entry->piece_no);
        });
    }

    public function runOpeningBalances(PostOpeningBalances $action): void
    {
        $this->runStep(function () use ($action): string {
            $entry = $action->handle($this->fiscalYear()->id, $this->actor());

            return sprintf('A-nouveaux posted into the next exercice: %s.', (string) $entry->piece_no);
        });
    }

    public function closeYear(CloseFiscalYear $action): void
    {
        $this->runStep(function () use ($action): string {
            $year = $action->handle($this->fiscalYear()->id, $this->actor());

            return sprintf('Fiscal year %s is closed.', $year->code);
        });
    }

    public function saveAppropriation(RecordResultAppropriation $action): void
    {
        $this->runStep(function () use ($action): string {
            $lines = [];

            foreach ($this->appropriationLines as $index => $line) {
                if ($line['account_id'] === '' && $line['amount'] === '') {
                    continue;
                }

                $lines[] = [
                    'account_id' => (int) $line['account_id'],
                    'amount' => (int) $line['amount'],
                    'label' => $line['label'] === '' ? ('Affectation #'.($index + 1)) : $line['label'],
                ];
            }

            $appropriation = $action->handle(
                fiscalYearId: $this->fiscalYear()->id,
                decisionBody: $this->decisionBody,
                decisionDate: $this->decisionDate === '' ? $this->fiscalYear()->ends_on->toDateString() : $this->decisionDate,
                resolutionReference: $this->resolutionReference,
                lines: $lines,
                actor: $this->actor(),
            );

            return sprintf(
                'Appropriation of %s recorded as a draft (%d line(s)). Post it to empty compte 13.',
                (string) $appropriation->result_amount,
                count($lines),
            );
        });
    }

    public function addAppropriationLine(): void
    {
        $this->appropriationLines[] = ['account_id' => '', 'amount' => '', 'label' => ''];
    }

    // ----------------------------------------------------- checklist writes

    public function completeItem(int $itemId, CompleteYearEndChecklistItem $action): void
    {
        $this->runStep(function () use ($itemId, $action): string {
            $item = $action->handle($itemId, $this->actor());

            return sprintf('Step %d signed off.', $item->sequence);
        });
    }

    public function startWaiver(int $itemId): void
    {
        $this->waivingItemId = (string) $itemId;
        $this->waiverReason = '';
        $this->resetMessages();
    }

    public function cancelWaiver(): void
    {
        $this->waivingItemId = '';
        $this->waiverReason = '';
    }

    public function waiveItem(WaiveYearEndChecklistItem $action): void
    {
        $this->runStep(function () use ($action): string {
            $item = $action->handle((int) $this->waivingItemId, $this->waiverReason, $this->actor());

            $this->waivingItemId = '';
            $this->waiverReason = '';

            return sprintf('Step %d waived, with the reason on record.', $item->sequence);
        });
    }

    public function toggleCheck(string $code): void
    {
        $this->expandedCheck = $this->expandedCheck === $code ? '' : $code;
    }

    // --------------------------------------------------------------- export

    /**
     * §17.3: "The waiver list is printed on the closing report. An auditor
     * asked 'what did you skip?' gets one page." The PDF is that page.
     */
    public function exportPdf(EvaluateYearEndChecklist $evaluator): Response
    {
        Gate::authorize(Permission::LedgerView->value);

        $state = $evaluator->handle($this->fiscalYear()->id, $this->actor());

        $rows = [];

        /** @var list<YearEndChecklistItem> $items */
        $items = $state['items'];

        foreach ($items as $item) {
            $rows[] = [
                $item->sequence,
                $item->title,
                $item->status->value,
                $item->completed_at?->format('Y-m-d H:i') ?? '',
                $this->userName($item->completed_by ?? $item->waived_by),
                (string) $item->waiver_reason,
            ];
        }

        return PdfExport::download(
            title: sprintf('Year-end checklist - %s', $this->fiscalYear()->code),
            headers: ['#', 'Step', 'Status', 'At', 'By', 'Waiver reason'],
            rows: $rows,
            filename: sprintf('year-end-checklist-%s.pdf', $this->fiscalYear()->code),
            orientation: 'landscape',
        );
    }

    // ---------------------------------------------------------------- render

    public function render(EvaluateYearEndChecklist $evaluator, YearEndBalances $balances): View
    {
        Gate::authorize(Permission::LedgerView->value);

        $fiscalYear = FiscalYear::query()->find($this->fiscalYearId === '' ? 0 : (int) $this->fiscalYearId);

        $state = null;
        $resultBalance = 0;

        if ($fiscalYear !== null) {
            $state = $evaluator->handle((int) $fiscalYear->getKey(), $this->actor());

            $resultAccountId = ChartOfAccount::query()
                ->where('code', PostYearEndClosingEntry::RESULT_ACCOUNT_CODE)
                ->value('id');

            if ($resultAccountId !== null) {
                $resultBalance = -$balances->accountBalance((int) $fiscalYear->getKey(), (int) $resultAccountId);
            }
        }

        return view('livewire.accounting.year-end.console', [
            'fiscalYears' => FiscalYear::query()->orderByDesc('starts_on')->get(),
            'fiscalYear' => $fiscalYear,
            'state' => $state,
            'resultBalance' => $resultBalance,
            'appropriation' => $fiscalYear === null ? null : ResultAppropriation::query()
                ->where('fiscal_year_id', $fiscalYear->getKey())
                ->with('lines')
                ->first(),
            'equityAccounts' => ChartOfAccount::query()
                ->where('is_postable', true)
                ->where('is_archived', false)
                ->where('account_class', 1)
                ->orderBy('code')
                ->get(),
            'runnableSteps' => [
                YearEndStep::ClosingEntry->value => 'runClosingEntry',
                YearEndStep::ResultAppropriation->value => 'runAppropriation',
                YearEndStep::OpeningBalances->value => 'runOpeningBalances',
            ],
            'unavailableStatus' => ValidateYearEndTrialBalance::STATUS_UNAVAILABLE,
            'failStatus' => ValidateYearEndTrialBalance::STATUS_FAIL,
        ]);
    }

    // --------------------------------------------------------------- helpers

    /**
     * Every write goes through here: one place that catches the Actions'
     * refusals and shows them unedited.
     *
     * @param  callable(): string  $callback
     */
    private function runStep(callable $callback): void
    {
        $this->resetMessages();

        try {
            $this->statusMessage = $callback();
        } catch (DomainException $exception) {
            $this->errorMessage = $exception->getMessage();
        } catch (Throwable $exception) {
            $this->errorMessage = $exception->getMessage();
        }
    }

    private function resetMessages(): void
    {
        $this->statusMessage = '';
        $this->errorMessage = '';
    }

    private function fiscalYear(): FiscalYear
    {
        /** @var FiscalYear $year */
        $year = FiscalYear::query()->findOrFail($this->fiscalYearId === '' ? 0 : (int) $this->fiscalYearId);

        return $year;
    }

    private function userName(?int $userId): string
    {
        if ($userId === null) {
            return '';
        }

        $name = DB::table('users')->where('id', $userId)->value('name');

        return is_string($name) ? $name : '';
    }

    private function actor(): Actor
    {
        $user = Auth::user();

        return $user === null
            ? Actor::system()
            : new Actor((int) $user->getAuthIdentifier(), (string) ($user->name ?? 'user'));
    }
}
