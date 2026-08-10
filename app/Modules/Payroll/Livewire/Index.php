<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Livewire;

use App\Modules\Payroll\Actions\ApprovePayrollRun;
use App\Modules\Payroll\Actions\CalculatePayrollRun;
use App\Modules\Payroll\Actions\ConfigureEmployerProfile;
use App\Modules\Payroll\Actions\ConfigureStatutoryRate;
use App\Modules\Payroll\Actions\GenerateStatutoryDeclarations;
use App\Modules\Payroll\Actions\PayrollPreflightCheck;
use App\Modules\Payroll\Actions\PreparePayrollPayment;
use App\Modules\Payroll\Actions\ReversePayrollRun;
use App\Modules\Payroll\Actions\SavePayrollComponent;
use App\Modules\Payroll\Domain\CnpsRegime;
use App\Modules\Payroll\Domain\ComponentCalculation;
use App\Modules\Payroll\Domain\ComponentType;
use App\Modules\Payroll\Domain\PayrollPermission;
use App\Modules\Payroll\Domain\RunStatus;
use App\Modules\Payroll\Domain\RunType;
use App\Modules\Payroll\Domain\StatutoryRateCode;
use App\Modules\Payroll\Models\PayrollRun;
use DomainException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Payroll Index at /payroll, gated `payroll.view`: a read-only overview of
 * payroll runs, payments and statutory declarations.
 *
 * KPI strip (total runs / last run's net pay / runs pending approval /
 * staff paid this month), filter bar, tabbed table (Payroll Runs,
 * Payments, Statutory Declarations). Modelled tightly on
 * Welfare\Livewire\Transport\Index.
 *
 * DB::table only - never another module's Models (ModuleBoundaryTest).
 * One paginated query per render plus the KPI aggregates; no unbounded
 * collection reaches the view (00-core 6.2 rule 8, enforced by
 * x-list-screen).
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    /** Which table is showing: runs | payments | declarations. */
    #[Url]
    public string $tab = 'runs';

    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    // ── Start payroll run form ──────────────────────────────────────────
    public bool $showForm = false;

    public string $formPayrollMonth = '';

    public string $formRunType = 'regular';

    // ── Prepare payment form (per-row, approved runs) ───────────────────
    public ?int $payRunId = null;

    public string $payMethod = 'bank';

    public int $payTreasuryAccountId = 0;

    public string $payValueDate = '';

    // ── Reverse run form (per-row, approved/paid runs) ──────────────────
    public ?int $reverseRunId = null;

    public string $reverseReason = '';

    // ── Setup: employer profile form (payroll.configure) ────────────────
    public bool $showEmployerForm = false;

    public string $epCnpsEmployerNumber = '';

    public string $epDipeNumber = '';

    public string $epNiu = '';

    public string $epDgiCentre = '';

    public int $epTdlCommuneId = 0;

    public string $epCnpsRegime = 'general';

    public string $epRpRiskClass = '';

    public int $epCnpsNotificationDocumentId = 0;

    public string $epCnpsNotificationReference = '';

    public string $epEffectiveFrom = '';

    public bool $epRegimeConfirmed = false;

    public bool $epRiskClassConfirmed = false;

    // ── Setup: payroll component form (payroll.configure) ───────────────
    public bool $showComponentForm = false;

    public string $pcCode = '';

    public string $pcName = '';

    public string $pcNameFr = '';

    public string $pcType = 'earning';

    public string $pcCalculation = 'fixed';

    public string $pcStatutoryRateCode = '';

    public string $pcFormulaExpression = '';

    public int $pcCalculationOrder = 100;

    public bool $pcIsTaxable = false;

    public bool $pcIsCnpsLiable = false;

    public bool $pcIsEnabled = true;

    public string $pcEffectiveFrom = '';

    // ── Setup: statutory rate form (payroll.configure) ───────────────────
    public bool $showRateForm = false;

    public string $srCode = 'PVID';

    public string $srEffectiveFrom = '';

    public string $srSourceCitation = '';

    public string $srEmployeeRateBp = '';

    public string $srEmployerRateBp = '';

    public string $srFlatAmount = '';

    public string $srRiskClass = '';

    public string $srCnpsRegime = '';

    public function mount(): void
    {
        Gate::authorize(PayrollPermission::VIEW);
    }

    public function selectTab(string $tab): void
    {
        $this->tab = in_array($tab, ['runs', 'payments', 'declarations'], true)
            ? $tab
            : 'runs';
        $this->status = '';
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['status', 'search']);
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    private function resetPage(): void
    {
        $this->page = 1;
    }

    public function toggleForm(): void
    {
        Gate::authorize(PayrollPermission::RUN);

        $this->showForm = ! $this->showForm;

        if ($this->showForm && $this->formPayrollMonth === '') {
            $this->formPayrollMonth = Carbon::now()->startOfMonth()->format('Y-m');
        }
    }

    public function startRun(CalculatePayrollRun $calculate): void
    {
        Gate::authorize(PayrollPermission::RUN);

        if ($this->formPayrollMonth === '') {
            $this->addError('formPayrollMonth', 'Choose the payroll month.');

            return;
        }

        $runType = RunType::tryFrom($this->formRunType);

        if ($runType === null) {
            $this->addError('formRunType', 'Choose a valid run type.');

            return;
        }

        try {
            $calculate->handle(
                $this->formPayrollMonth.'-01',
                $runType,
                $this->actor(),
            );
        } catch (DomainException $e) {
            $this->addError('formPayrollMonth', $e->getMessage());

            return;
        }

        $this->reset(['showForm', 'formPayrollMonth', 'formRunType']);
        $this->tab = 'runs';
        $this->resetPage();
        session()->flash('status', 'Payroll run calculated.');
    }

    public function approveRun(int $runId, ApprovePayrollRun $approve): void
    {
        Gate::authorize(PayrollPermission::APPROVE);

        try {
            $approve->handle($runId, $this->actor());
        } catch (DomainException $e) {
            $this->addError('approve', $e->getMessage());

            return;
        }

        session()->flash('status', 'Payroll run approved.');
    }

    /**
     * Runs the fifteen preflight checks (05-hr-payroll 9.1) against a
     * draft/calculated run and flashes a summary of the failing checks.
     * The Action itself carries no Gate::authorize call - it is a
     * read-style diagnostic run as part of the RUN workflow, so it is
     * gated here on `payroll.run`.
     */
    public function preflightRun(int $runId, PayrollPreflightCheck $preflight): void
    {
        Gate::authorize(PayrollPermission::RUN);

        try {
            /** @var PayrollRun $run */
            $run = PayrollRun::query()->findOrFail($runId);

            $result = $preflight->handle($run);
        } catch (DomainException|ValidationException $e) {
            $this->addError('preflight', $e->getMessage());

            return;
        }

        if ($result['failed'] === []) {
            session()->flash('status', 'Preflight check passed: no blocking issues found.');

            return;
        }

        $codes = implode(', ', array_map(
            static fn ($code): string => $code->value,
            $result['failed'],
        ));

        session()->flash('status', 'Preflight check found '.count($result['failed']).' blocking issue(s): '.$codes);
    }

    public function togglePayForm(?int $runId): void
    {
        Gate::authorize(PayrollPermission::PAY);

        $this->payRunId = $this->payRunId === $runId ? null : $runId;

        if ($this->payRunId !== null && $this->payValueDate === '') {
            $this->payValueDate = Carbon::now()->toDateString();
        }
    }

    public function preparePayment(PreparePayrollPayment $prepare): void
    {
        Gate::authorize(PayrollPermission::PAY);

        if ($this->payRunId === null) {
            return;
        }

        if ($this->payValueDate === '') {
            $this->addError('payValueDate', 'Choose a value date.');

            return;
        }

        if ($this->payTreasuryAccountId < 1) {
            $this->addError('payTreasuryAccountId', 'Choose a treasury account.');

            return;
        }

        try {
            $prepare->handle(
                $this->payRunId,
                $this->payMethod,
                $this->payTreasuryAccountId,
                $this->payValueDate,
                $this->actor(),
            );
        } catch (DomainException|ValidationException $e) {
            $this->addError('payValueDate', $e->getMessage());

            return;
        }

        $this->reset(['payRunId', 'payMethod', 'payTreasuryAccountId', 'payValueDate']);
        $this->tab = 'payments';
        $this->resetPage();
        session()->flash('status', 'Payment prepared for disbursement.');
    }

    public function generateDeclarations(int $runId, GenerateStatutoryDeclarations $generate): void
    {
        Gate::authorize(PayrollPermission::DECLARATION_FILE);

        try {
            /** @var PayrollRun $run */
            $run = PayrollRun::query()->findOrFail($runId);

            $count = $generate->handle($run->payroll_month->toDateString(), $this->actor());
        } catch (DomainException|ValidationException $e) {
            $this->addError('declarations', $e->getMessage());

            return;
        }

        $this->tab = 'declarations';
        $this->resetPage();
        session()->flash('status', $count.' statutory declaration(s) generated or updated.');
    }

    public function toggleReverseForm(?int $runId): void
    {
        Gate::authorize(PayrollPermission::REVERSE);

        $this->reverseRunId = $this->reverseRunId === $runId ? null : $runId;
        $this->reverseReason = '';
    }

    public function reverseRun(ReversePayrollRun $reverse): void
    {
        Gate::authorize(PayrollPermission::REVERSE);

        if ($this->reverseRunId === null) {
            return;
        }

        try {
            $reverse->handle($this->reverseRunId, $this->reverseReason, $this->actor());
        } catch (DomainException|ValidationException $e) {
            $this->addError('reverseReason', $e->getMessage());

            return;
        }

        $this->reset(['reverseRunId', 'reverseReason']);
        $this->resetPage();
        session()->flash('status', 'Payroll run reversed.');
    }

    public function toggleEmployerForm(): void
    {
        Gate::authorize(PayrollPermission::CONFIGURE);

        $this->showEmployerForm = ! $this->showEmployerForm;

        if ($this->showEmployerForm && $this->epEffectiveFrom === '') {
            $this->epEffectiveFrom = Carbon::now()->startOfMonth()->toDateString();
        }
    }

    public function saveEmployerProfile(ConfigureEmployerProfile $configure): void
    {
        Gate::authorize(PayrollPermission::CONFIGURE);

        $regime = CnpsRegime::tryFrom($this->epCnpsRegime);

        if ($regime === null) {
            $this->addError('epCnpsRegime', 'Choose a valid CNPS regime.');

            return;
        }

        try {
            $configure->handle(
                cnpsEmployerNumber: $this->epCnpsEmployerNumber,
                dipeNumber: $this->epDipeNumber,
                niu: $this->epNiu,
                tdlCommuneId: $this->epTdlCommuneId,
                cnpsRegime: $regime,
                rpRiskClass: $this->epRpRiskClass,
                cnpsNotificationDocumentId: $this->epCnpsNotificationDocumentId,
                cnpsNotificationReference: $this->epCnpsNotificationReference,
                effectiveFrom: $this->epEffectiveFrom,
                regimeConfirmed: $this->epRegimeConfirmed,
                riskClassConfirmed: $this->epRiskClassConfirmed,
                dgiCentre: $this->epDgiCentre !== '' ? $this->epDgiCentre : null,
                actor: $this->actor(),
            );
        } catch (DomainException|ValidationException $e) {
            $this->addError('epCnpsEmployerNumber', $e->getMessage());

            return;
        }

        $this->reset([
            'showEmployerForm', 'epCnpsEmployerNumber', 'epDipeNumber', 'epNiu', 'epDgiCentre',
            'epTdlCommuneId', 'epCnpsRegime', 'epRpRiskClass', 'epCnpsNotificationDocumentId',
            'epCnpsNotificationReference', 'epEffectiveFrom', 'epRegimeConfirmed', 'epRiskClassConfirmed',
        ]);
        $this->epCnpsRegime = 'general';
        session()->flash('status', 'Employer profile configured.');
    }

    public function toggleComponentForm(): void
    {
        Gate::authorize(PayrollPermission::CONFIGURE);

        $this->showComponentForm = ! $this->showComponentForm;

        if ($this->showComponentForm && $this->pcEffectiveFrom === '') {
            $this->pcEffectiveFrom = Carbon::now()->startOfMonth()->toDateString();
        }
    }

    public function saveComponent(SavePayrollComponent $save): void
    {
        Gate::authorize(PayrollPermission::CONFIGURE);

        $type = ComponentType::tryFrom($this->pcType);
        $calculation = ComponentCalculation::tryFrom($this->pcCalculation);

        if ($this->pcCode === '') {
            $this->addError('pcCode', 'Choose a component code.');

            return;
        }

        if ($type === null) {
            $this->addError('pcType', 'Choose a valid component type.');

            return;
        }

        if ($calculation === null) {
            $this->addError('pcCalculation', 'Choose a valid calculation method.');

            return;
        }

        $attributes = [
            'name' => $this->pcName,
            'name_fr' => $this->pcNameFr !== '' ? $this->pcNameFr : null,
            'type' => $type,
            'calculation' => $calculation,
            'statutory_rate_code' => $this->pcStatutoryRateCode !== '' ? $this->pcStatutoryRateCode : null,
            'formula_expression' => $this->pcFormulaExpression !== '' ? $this->pcFormulaExpression : null,
            'calculation_order' => $this->pcCalculationOrder,
            'is_taxable' => $this->pcIsTaxable,
            'is_cnps_liable' => $this->pcIsCnpsLiable,
            'is_enabled' => $this->pcIsEnabled,
            'effective_from' => $this->pcEffectiveFrom !== '' ? $this->pcEffectiveFrom : Carbon::now()->toDateString(),
        ];

        try {
            $save->handle($this->pcCode, $attributes, [], $this->actor());
        } catch (DomainException|ValidationException $e) {
            $this->addError('pcCode', $e->getMessage());

            return;
        }

        $this->reset([
            'showComponentForm', 'pcCode', 'pcName', 'pcNameFr', 'pcStatutoryRateCode',
            'pcFormulaExpression', 'pcEffectiveFrom',
        ]);
        $this->pcType = 'earning';
        $this->pcCalculation = 'fixed';
        $this->pcCalculationOrder = 100;
        $this->pcIsTaxable = false;
        $this->pcIsCnpsLiable = false;
        $this->pcIsEnabled = true;
        session()->flash('status', 'Payroll component saved.');
    }

    public function toggleRateForm(): void
    {
        Gate::authorize(PayrollPermission::CONFIGURE);

        $this->showRateForm = ! $this->showRateForm;

        if ($this->showRateForm && $this->srEffectiveFrom === '') {
            $this->srEffectiveFrom = Carbon::now()->startOfMonth()->toDateString();
        }
    }

    public function saveStatutoryRate(ConfigureStatutoryRate $configure): void
    {
        Gate::authorize(PayrollPermission::CONFIGURE);

        $code = StatutoryRateCode::tryFrom($this->srCode);

        if ($code === null) {
            $this->addError('srCode', 'Choose a valid statutory rate code.');

            return;
        }

        if (trim($this->srSourceCitation) === '') {
            $this->addError('srSourceCitation', 'Cite the source document (CNPS letter or DGI notice).');

            return;
        }

        $regime = $this->srCnpsRegime !== '' ? CnpsRegime::tryFrom($this->srCnpsRegime) : null;

        try {
            $configure->handle(
                code: $code,
                effectiveFrom: $this->srEffectiveFrom,
                sourceCitation: $this->srSourceCitation,
                employeeRateBp: $this->srEmployeeRateBp !== '' ? (int) $this->srEmployeeRateBp : null,
                employerRateBp: $this->srEmployerRateBp !== '' ? (int) $this->srEmployerRateBp : null,
                flatAmount: $this->srFlatAmount !== '' ? (int) $this->srFlatAmount : null,
                riskClass: $this->srRiskClass !== '' ? $this->srRiskClass : null,
                cnpsRegime: $regime,
                actor: $this->actor(),
            );
        } catch (DomainException|ValidationException $e) {
            $this->addError('srSourceCitation', $e->getMessage());

            return;
        }

        $this->reset([
            'showRateForm', 'srEffectiveFrom', 'srSourceCitation', 'srEmployeeRateBp',
            'srEmployerRateBp', 'srFlatAmount', 'srRiskClass', 'srCnpsRegime',
        ]);
        $this->srCode = 'PVID';
        session()->flash('status', 'Statutory rate configured.');
    }

    private function actor(): \App\Support\Audit\Actor
    {
        /** @var \App\Modules\Identity\Models\User $user */
        $user = auth()->user();

        return $user->toAuditActor();
    }

    /**
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function rows(): LengthAwarePaginator
    {
        return match ($this->tab) {
            'payments' => $this->paymentRows(),
            'declarations' => $this->declarationRows(),
            default => $this->runRows(),
        };
    }

    /**
     * Payroll runs with employer profile reference and total net pay,
     * newest month first.
     *
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function runRows(): LengthAwarePaginator
    {
        return DB::table('payroll_runs as pr')
            ->leftJoin('employer_profiles as ep', 'ep.id', '=', 'pr.employer_profile_id')
            ->when($this->status !== '', fn ($q) => $q->where('pr.status', $this->status))
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('pr.run_type', 'like', '%'.$this->search.'%')
                        ->orWhere('ep.cnps_employer_number', 'like', '%'.$this->search.'%');
                });
            })
            ->orderByDesc('pr.payroll_month')
            ->orderByDesc('pr.id')
            ->select([
                'pr.id', 'pr.payroll_month', 'pr.run_type', 'pr.status',
                'pr.approved_at', 'pr.paid_at', 'ep.cnps_employer_number',
            ])
            ->selectSub(
                DB::table('payroll_items')->whereColumn('payroll_run_id', 'pr.id')->selectRaw('COUNT(*)'),
                'staff_count'
            )
            ->selectSub(
                DB::table('payroll_items')->whereColumn('payroll_run_id', 'pr.id')->selectRaw('COALESCE(SUM(net), 0)'),
                'total_net'
            )
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * Payment batches (disbursement runs) against a payroll run.
     *
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function paymentRows(): LengthAwarePaginator
    {
        return DB::table('payroll_payments as pp')
            ->join('payroll_runs as pr', 'pr.id', '=', 'pp.payroll_run_id')
            ->when($this->status !== '', fn ($q) => $q->where('pp.status', $this->status))
            ->when($this->search !== '', function ($q): void {
                $q->where('pp.payment_method', 'like', '%'.$this->search.'%');
            })
            ->orderByDesc('pp.value_date')
            ->orderByDesc('pp.id')
            ->select([
                'pp.id', 'pp.payment_method', 'pp.value_date', 'pp.total_amount',
                'pp.status', 'pp.exported_at', 'pr.payroll_month', 'pr.run_type',
            ])
            ->selectSub(
                DB::table('payroll_payment_lines')->whereColumn('payroll_payment_id', 'pp.id')->selectRaw('COUNT(*)'),
                'lines_count'
            )
            ->selectSub(
                DB::table('payroll_payment_lines')
                    ->whereColumn('payroll_payment_id', 'pp.id')
                    ->where('status', 'failed')
                    ->selectRaw('COUNT(*)'),
                'failed_count'
            )
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * Statutory declarations (DIPE, CNPS, DGI, TDL...) with the staff
     * member named only for `staff_departure` rows.
     *
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function declarationRows(): LengthAwarePaginator
    {
        return DB::table('statutory_declarations as sd')
            ->leftJoin('staff_members as sm', 'sm.id', '=', 'sd.staff_member_id')
            ->when($this->status !== '', fn ($q) => $q->where('sd.status', $this->status))
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('sd.type', 'like', '%'.$this->search.'%')
                        ->orWhere('sd.external_reference', 'like', '%'.$this->search.'%');
                });
            })
            ->orderByDesc(DB::raw('COALESCE(sd.period_month, MAKEDATE(sd.period_year, 1))'))
            ->orderByDesc('sd.id')
            ->select([
                'sd.id', 'sd.type', 'sd.payee', 'sd.period_month', 'sd.period_year',
                'sd.due_date', 'sd.status', 'sd.amount_declared', 'sd.amount_paid',
                'sd.external_reference', 'sm.first_name', 'sm.last_name',
            ])
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * The KPI strip: total payroll runs, last run's total net pay, runs
     * pending approval (calculated but not yet approved), and staff paid
     * in the current payroll month (runs already paid/closed).
     *
     * @return array{total_runs: int, last_run_net_pay: int, pending_approval: int, staff_paid_this_month: int}
     */
    private function kpis(): array
    {
        $monthStart = Carbon::today()->startOfMonth()->toDateString();

        $lastRunId = DB::table('payroll_runs')
            ->whereIn('status', ['approved', 'paid', 'closed'])
            ->orderByDesc('payroll_month')
            ->orderByDesc('id')
            ->value('id');

        $lastRunNetPay = $lastRunId !== null
            ? (int) DB::table('payroll_items')->where('payroll_run_id', $lastRunId)->sum('net')
            : 0;

        $staffPaidThisMonth = (int) DB::table('payroll_items as pi')
            ->join('payroll_runs as pr', 'pr.id', '=', 'pi.payroll_run_id')
            ->where('pr.payroll_month', $monthStart)
            ->whereIn('pr.status', ['paid', 'closed'])
            ->distinct()
            ->count('pi.staff_member_id');

        return [
            'total_runs' => (int) DB::table('payroll_runs')->count(),
            'last_run_net_pay' => $lastRunNetPay,
            'pending_approval' => (int) DB::table('payroll_runs')->where('status', 'calculated')->count(),
            'staff_paid_this_month' => $staffPaidThisMonth,
        ];
    }

    /**
     * Per-tab status filter choices (the WORD carries the meaning, 09-ui 10).
     *
     * @return list<array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return match ($this->tab) {
            'payments' => [
                ['value' => 'prepared', 'label' => 'Prepared'],
                ['value' => 'exported', 'label' => 'Exported'],
                ['value' => 'confirmed', 'label' => 'Confirmed'],
                ['value' => 'partially_failed', 'label' => 'Partially failed'],
            ],
            'declarations' => [
                ['value' => 'not_due', 'label' => 'Not due'],
                ['value' => 'due', 'label' => 'Due'],
                ['value' => 'generated', 'label' => 'Generated'],
                ['value' => 'filed', 'label' => 'Filed'],
                ['value' => 'paid', 'label' => 'Paid'],
                ['value' => 'late', 'label' => 'Late'],
                ['value' => 'rejected', 'label' => 'Rejected'],
            ],
            default => [
                ['value' => 'draft', 'label' => 'Draft'],
                ['value' => 'calculating', 'label' => 'Calculating'],
                ['value' => 'calculated', 'label' => 'Calculated'],
                ['value' => 'approved', 'label' => 'Approved'],
                ['value' => 'paid', 'label' => 'Paid'],
                ['value' => 'closed', 'label' => 'Closed'],
                ['value' => 'cancelled', 'label' => 'Cancelled'],
            ],
        };
    }

    public function render(): mixed
    {
        $tabCounts = [
            'runs' => (int) DB::table('payroll_runs')->count(),
            'payments' => (int) DB::table('payroll_payments')->count(),
            'declarations' => (int) DB::table('statutory_declarations')->count(),
        ];

        return view('livewire.payroll.index', [
            'rows' => $this->rows(),
            'kpis' => $this->kpis(),
            'tabCounts' => $tabCounts,
            'statusOptions' => $this->statusOptions(),
        ]);
    }
}
