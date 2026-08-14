<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Livewire\Budgets;

use App\Modules\Accounting\Actions\ApplyBudgetPhasing;
use App\Modules\Accounting\Actions\ApproveBudget;
use App\Modules\Accounting\Actions\BudgetVsActual;
use App\Modules\Accounting\Actions\ReviseBudget;
use App\Modules\Accounting\Actions\SaveBudget;
use App\Modules\Accounting\Actions\SaveBudgetLine;
use App\Modules\Accounting\Domain\BudgetStatus;
use App\Modules\Accounting\Domain\PhasingProfile;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\AnalyticValue;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\BudgetLine;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Reporting\Support\ExcelExport;
use App\Modules\Reporting\Support\PdfExport;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Budgets and Budget vs Actual (docs/specs/02-accounting.md §16).
 *
 * Two tabs, one screen, because they are one job: the editor is where the
 * phased figures come from and the variance grid is the only place anybody
 * finds out whether they were any good.
 *
 * Every figure on the variance tab is a real aggregate from
 * `Actions\BudgetVsActual`, which reads actuals through
 * `JournalEntry::postedLedger()`. Nothing here is a placeholder and nothing
 * here writes to the ledger - the budget module never posts.
 *
 * Reads are gated on `ledger.view`; every write goes through the budget
 * Actions, which gate themselves on `ledger.configure` and carry the audit
 * entry. This component performs no query the Actions do not, other than the
 * option lists it renders selects from.
 *
 * Strings are literal English on purpose: `lang/en|fr/opes.php` is under
 * concurrent edit by another agent, so adding keys here would collide. They
 * are the only untranslated strings on the screen and should be lifted into
 * `opes.php` when that file is free.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    public const TABS = ['budgets', 'variance'];

    #[Url]
    public string $tab = 'budgets';

    #[Url]
    public string $fiscalYearId = '';

    #[Url]
    public string $budgetId = '';

    #[Url]
    public string $periodFrom = '';

    #[Url]
    public string $periodTo = '';

    #[Url]
    public string $accountFilterId = '';

    /** Variance tab: show one row per account (false) or per account per period (true). */
    #[Url]
    public bool $byPeriod = false;

    // ── Budget form ──────────────────────────────────────────────────────
    public string $formCode = '';

    public string $formName = '';

    public string $formNotes = '';

    // ── Line form ────────────────────────────────────────────────────────
    public string $lineAccountId = '';

    public string $lineAnalyticValueId = '';

    public string $lineAnnualAmount = '';

    public string $linePhasingProfile = PhasingProfile::Equal->value;

    public string $lineNotes = '';

    /** Manual phasing: 'YYYY-MM-01' => amount as typed. */
    /** @var array<string, string> */
    public array $lineManualAmounts = [];

    public ?int $editingLineId = null;

    public function mount(): void
    {
        Gate::authorize(Permission::LedgerView->value);

        if ($this->fiscalYearId === '') {
            $year = FiscalYear::query()->where('status', 'open')->orderByDesc('starts_on')->first()
                ?? FiscalYear::query()->orderByDesc('starts_on')->first();

            $this->fiscalYearId = $year === null ? '' : (string) $year->id;
        }
    }

    public function selectTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'budgets';
    }

    public function updatedFiscalYearId(): void
    {
        $this->budgetId = '';
        $this->periodFrom = '';
        $this->periodTo = '';
        $this->resetLineForm();
    }

    public function resetFilters(): void
    {
        $this->reset(['periodFrom', 'periodTo', 'accountFilterId', 'byPeriod']);
    }

    // ── Budget CRUD ──────────────────────────────────────────────────────

    public function createBudget(): void
    {
        $this->guardedCall(function (): void {
            $budget = app(SaveBudget::class)->handle(
                budgetId: null,
                fiscalYearId: (int) $this->fiscalYearId,
                code: $this->formCode,
                name: $this->formName,
                notes: $this->formNotes === '' ? null : $this->formNotes,
                actor: $this->actor(),
            );

            $this->budgetId = (string) $budget->getKey();
            $this->formCode = '';
            $this->formName = '';
            $this->formNotes = '';

            session()->flash('budget_status', 'Budget '.$budget->code.' v'.$budget->version.' created as a draft.');
        });
    }

    public function selectBudget(int $budgetId): void
    {
        $this->budgetId = (string) $budgetId;
        $this->resetLineForm();
    }

    public function approve(int $budgetId): void
    {
        $this->guardedCall(function () use ($budgetId): void {
            $budget = app(ApproveBudget::class)->handle($budgetId, $this->actor(), true);

            session()->flash('budget_status', sprintf(
                'Budget %s v%d approved and is now the current budget for its fiscal year — its `block` and `warn` accounts are armed from now on.',
                $budget->code,
                $budget->version,
            ));
        });
    }

    public function close(int $budgetId): void
    {
        $this->guardedCall(function () use ($budgetId): void {
            $budget = app(ApproveBudget::class)->close($budgetId, $this->actor());

            session()->flash('budget_status', 'Budget '.$budget->code.' v'.$budget->version.' closed.');
        });
    }

    public function revise(int $budgetId): void
    {
        $this->guardedCall(function () use ($budgetId): void {
            $revision = app(ReviseBudget::class)->handle($budgetId, $this->actor());

            $this->budgetId = (string) $revision->getKey();

            session()->flash('budget_status', sprintf(
                'Budget %s revised: version %d is a new draft, copied line for line. Version %d stays exactly as it was approved.',
                $revision->code,
                $revision->version,
                $revision->version - 1,
            ));
        });
    }

    // ── Line CRUD ────────────────────────────────────────────────────────

    public function editLine(int $lineId): void
    {
        /** @var BudgetLine|null $line */
        $line = BudgetLine::query()->find($lineId);

        if ($line === null) {
            return;
        }

        $this->editingLineId = (int) $line->getKey();
        $this->lineAccountId = (string) $line->account_id;
        $this->lineAnalyticValueId = $line->analytic_value_id === null ? '' : (string) $line->analytic_value_id;
        $this->lineAnnualAmount = (string) $line->annual_amount;
        $this->lineNotes = (string) ($line->notes ?? '');
        $this->linePhasingProfile = PhasingProfile::Manual->value;

        $this->lineManualAmounts = [];

        foreach ($line->phasings()->get() as $phasing) {
            $this->lineManualAmounts[$phasing->period_month->startOfMonth()->toDateString()] = (string) $phasing->amount;
        }
    }

    public function saveLine(): void
    {
        // A missing selection is an operator state, not an exceptional one:
        // it must produce the same refusal every other form produces, not a
        // framework stack trace on a screen the operator cannot back out of.
        // budgetId is a bound <select> value, so "nothing chosen" is the
        // empty string; casting that to (int) 0 and handing it to
        // SaveBudgetLine made its firstOrFail() throw ModelNotFoundException,
        // which guardedCall() does not catch - it only catches DomainException
        // - so the exception escaped as a 500.
        if ($this->budgetId === '' || ! Budget::query()->whereKey((int) $this->budgetId)->exists()) {
            throw ValidationException::withMessages([
                'budgetId' => __('opes.budgets_screen.select_a_budget_first'),
            ]);
        }

        $this->guardedCall(function (): void {
            $profile = PhasingProfile::tryFrom($this->linePhasingProfile) ?? PhasingProfile::Equal;

            /** @var array<string, int>|null $manual */
            $manual = null;

            if ($profile === PhasingProfile::Manual) {
                $manual = [];

                foreach ($this->lineManualAmounts as $month => $amount) {
                    $manual[$month] = (int) ($amount === '' ? 0 : $amount);
                }
            }

            app(SaveBudgetLine::class)->handle(
                budgetId: (int) $this->budgetId,
                accountId: (int) $this->lineAccountId,
                analyticValueId: $this->lineAnalyticValueId === '' ? null : (int) $this->lineAnalyticValueId,
                annualAmount: (int) ($this->lineAnnualAmount === '' ? 0 : $this->lineAnnualAmount),
                profile: $profile,
                manualAmounts: $manual,
                notes: $this->lineNotes === '' ? null : $this->lineNotes,
                actor: $this->actor(),
            );

            $this->resetLineForm();

            session()->flash('budget_status', 'Budget line saved and phased.');
        });
    }

    public function rephaseLine(int $lineId, string $profile): void
    {
        $this->guardedCall(function () use ($lineId, $profile): void {
            app(ApplyBudgetPhasing::class)->handle(
                $lineId,
                PhasingProfile::tryFrom($profile) ?? PhasingProfile::Equal,
                null,
                $this->actor(),
            );

            session()->flash('budget_status', 'Line re-phased.');
        });
    }

    public function deleteLine(int $lineId): void
    {
        $this->guardedCall(function () use ($lineId): void {
            app(SaveBudgetLine::class)->delete($lineId, $this->actor());
            $this->resetLineForm();

            session()->flash('budget_status', 'Budget line removed.');
        });
    }

    public function resetLineForm(): void
    {
        $this->editingLineId = null;
        $this->lineAccountId = '';
        $this->lineAnalyticValueId = '';
        $this->lineAnnualAmount = '';
        $this->linePhasingProfile = PhasingProfile::Equal->value;
        $this->lineNotes = '';
        $this->lineManualAmounts = [];
    }

    /**
     * Every write funnels through here so a DomainException from an Action -
     * B-1, B-2, B-3, the account-class rule - reaches the operator as the
     * sentence the Action wrote, rather than as a 500.
     */
    private function guardedCall(callable $callback): void
    {
        try {
            $callback();
        } catch (DomainException $exception) {
            $this->addError('budget', $exception->getMessage());
        }
    }

    private function actor(): Actor
    {
        $user = Auth::user();

        return $user === null
            ? Actor::system()
            : new Actor((int) $user->getAuthIdentifier(), (string) ($user->name ?? 'user'));
    }

    // ── Data ─────────────────────────────────────────────────────────────

    private function fiscalYearIdOrNull(): ?int
    {
        return $this->fiscalYearId === '' ? null : (int) $this->fiscalYearId;
    }

    /**
     * @return Collection<int, Budget>
     */
    private function budgets(): Collection
    {
        $fiscalYearId = $this->fiscalYearIdOrNull();

        if ($fiscalYearId === null) {
            return collect();
        }

        /** @var Collection<int, Budget> $budgets */
        $budgets = Budget::query()
            ->where('fiscal_year_id', $fiscalYearId)
            ->withCount('lines')
            ->withSum('lines as budgeted_total', 'annual_amount')
            ->orderBy('code')
            ->orderByDesc('version')
            ->get();

        return $budgets;
    }

    private function selectedBudget(): ?Budget
    {
        if ($this->budgetId === '') {
            return null;
        }

        /** @var Budget|null $budget */
        $budget = Budget::query()->find((int) $this->budgetId);

        return $budget;
    }

    /**
     * @return Collection<int, BudgetLine>
     */
    private function budgetLines(): Collection
    {
        $budget = $this->selectedBudget();

        if ($budget === null) {
            return collect();
        }

        /** @var Collection<int, BudgetLine> $lines */
        $lines = BudgetLine::query()
            ->where('budget_id', $budget->getKey())
            ->with(['account', 'analyticValue', 'phasings'])
            ->withSum('phasings as phased_total', 'amount')
            ->get()
            ->sortBy(fn (BudgetLine $line): string => (string) ($line->account->code ?? ''))
            ->values();

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function periodMonths(): array
    {
        $fiscalYearId = $this->fiscalYearIdOrNull();

        if ($fiscalYearId === null) {
            return [];
        }

        /** @var list<string> $months */
        $months = AccountingPeriod::query()
            ->where('fiscal_year_id', $fiscalYearId)
            ->orderBy('period_month')
            ->pluck('period_month')
            ->map(static fn (mixed $month): string => Carbon::parse((string) $month)->startOfMonth()->toDateString())
            ->values()
            ->all();

        return $months;
    }

    /**
     * @return list<array{code: string, name: string, budget_control: string, period_month: string, budget: int, actual: int, variance: int, variance_pct: float|null, favourable: bool}>
     */
    private function varianceRows(): array
    {
        $fiscalYearId = $this->fiscalYearIdOrNull();

        if ($fiscalYearId === null) {
            return [];
        }

        $rows = app(BudgetVsActual::class)->handle(
            $fiscalYearId,
            $this->budgetId === '' ? null : (int) $this->budgetId,
            $this->periodFrom === '' ? null : $this->periodFrom,
            $this->periodTo === '' ? null : $this->periodTo,
            $this->accountFilterId === '' ? null : (int) $this->accountFilterId,
        );

        if ($this->byPeriod) {
            /** @var list<array{code: string, name: string, budget_control: string, period_month: string, budget: int, actual: int, variance: int, variance_pct: float|null, favourable: bool}> $detail */
            $detail = $rows->map(static fn (array $row): array => [
                'code' => $row['code'],
                'name' => $row['name'],
                'budget_control' => $row['budget_control'],
                'period_month' => $row['period_month'],
                'budget' => $row['budget'],
                'actual' => $row['actual'],
                'variance' => $row['variance'],
                'variance_pct' => $row['variance_pct'],
                'favourable' => $row['favourable'],
            ])->values()->all();

            return $detail;
        }

        /** @var array<string, array{code: string, name: string, budget_control: string, period_month: string, budget: int, actual: int, variance: int, variance_pct: float|null, favourable: bool, type: string}> $byAccount */
        $byAccount = [];

        foreach ($rows as $row) {
            $key = $row['code'];

            $byAccount[$key] ??= [
                'code' => $row['code'],
                'name' => $row['name'],
                'budget_control' => $row['budget_control'],
                'period_month' => 'Total',
                'budget' => 0,
                'actual' => 0,
                'variance' => 0,
                'variance_pct' => null,
                'favourable' => true,
                'type' => $row['type'],
            ];

            $byAccount[$key]['budget'] += $row['budget'];
            $byAccount[$key]['actual'] += $row['actual'];
        }

        /** @var list<array{code: string, name: string, budget_control: string, period_month: string, budget: int, actual: int, variance: int, variance_pct: float|null, favourable: bool}> $totals */
        $totals = [];

        foreach ($byAccount as $row) {
            $variance = $row['actual'] - $row['budget'];

            $totals[] = [
                'code' => $row['code'],
                'name' => $row['name'],
                'budget_control' => $row['budget_control'],
                'period_month' => 'Total',
                'budget' => $row['budget'],
                'actual' => $row['actual'],
                'variance' => $variance,
                'variance_pct' => $row['budget'] === 0 ? null : round(($variance / abs($row['budget'])) * 100, 1),
                'favourable' => $row['type'] === 'revenue' ? $variance >= 0 : $variance <= 0,
            ];
        }

        usort($totals, static fn (array $a, array $b): int => strcmp($a['code'], $b['code']));

        return $totals;
    }

    // ── Export ───────────────────────────────────────────────────────────

    /**
     * @return array{title: string, headers: list<string>, rows: list<list<mixed>>}
     */
    private function exportData(): array
    {
        $rows = $this->varianceRows();
        $year = $this->fiscalYearIdOrNull();
        $yearCode = $year === null ? '' : (string) FiscalYear::query()->whereKey($year)->value('code');

        return [
            'title' => 'Budget vs Actual FY'.$yearCode,
            'headers' => ['Code', 'Account', 'Period', 'Budget (FCFA)', 'Actual (FCFA)', 'Variance (FCFA)', 'Variance %', 'Reading', 'Control'],
            'rows' => array_map(static fn (array $row): array => [
                $row['code'],
                $row['name'],
                $row['period_month'],
                $row['budget'],
                $row['actual'],
                $row['variance'],
                $row['variance_pct'] === null ? 'n/a' : $row['variance_pct'].'%',
                $row['favourable'] ? 'favourable' : 'unfavourable',
                $row['budget_control'],
            ], $rows),
        ];
    }

    public function exportExcel(): StreamedResponse
    {
        Gate::authorize(Permission::LedgerView->value);

        $data = $this->exportData();

        return ExcelExport::download(
            $data['title'],
            $data['headers'],
            $data['rows'],
            str($data['title'])->slug()->value().'.xlsx',
        );
    }

    public function exportPdf(): Response
    {
        Gate::authorize(Permission::LedgerView->value);

        $data = $this->exportData();

        return PdfExport::download(
            $data['title'],
            $data['headers'],
            $data['rows'],
            str($data['title'])->slug()->value().'.pdf',
            'landscape',
        );
    }

    public function render(): mixed
    {
        Gate::authorize(Permission::LedgerView->value);

        return view('livewire.accounting.budgets.index', [
            'fiscalYearOptions' => FiscalYear::query()->orderByDesc('starts_on')->get(),
            'budgets' => $this->budgets(),
            'selectedBudget' => $this->selectedBudget(),
            'budgetLines' => $this->budgetLines(),
            'periodMonths' => $this->periodMonths(),
            'varianceRows' => $this->tab === 'variance' ? $this->varianceRows() : [],
            'accountOptions' => ChartOfAccount::query()
                ->where('is_postable', true)
                ->where('is_archived', false)
                ->whereIn('account_class', [2, 6, 7])
                ->orderBy('code')
                ->get(),
            'analyticValueOptions' => AnalyticValue::query()
                ->where('is_archived', false)
                ->orderBy('code')
                ->get(),
            'phasingProfiles' => PhasingProfile::cases(),
            'statuses' => BudgetStatus::cases(),
        ]);
    }
}
