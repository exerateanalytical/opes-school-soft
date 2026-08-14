<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Livewire;

use App\Modules\Payroll\Actions\ApprovePayrollRun;
use App\Modules\Payroll\Actions\GenerateStatutoryDeclarations;
use App\Modules\Payroll\Actions\PayrollPreflightCheck;
use App\Modules\Payroll\Actions\PreparePayrollPayment;
use App\Modules\Payroll\Actions\PrintPayslip;
use App\Modules\Payroll\Actions\ReversePayrollRun;
use App\Modules\Payroll\Domain\PayrollPermission;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Reporting\Support\PdfExport;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;

/**
 * A single payroll run's detail page: `/payroll/runs/{run}`, gated
 * `payroll.view`. Header totals, the per-staff breakdown (payroll_items),
 * a per-staff payslip PDF download and a whole-run "payroll register"
 * export - plus the SAME lifecycle actions Index.php already exposes
 * (preflight / approve / prepare payment / generate declarations /
 * reverse), scoped to this one run instead of a table row.
 *
 * DB::table only for cross-module reads (payroll_items, payroll_lines,
 * payroll_components, employer_profiles, staff_members) - PayrollRun
 * itself is this module's own model, so route-model-binding it is fine
 * (mirrors Students\Livewire\Students\Show).
 */
#[Layout('layouts.app')]
final class Show extends Component
{
    public PayrollRun $run;

    // ── Prepare payment form (mirrors Index.php's per-row form) ─────────
    public bool $showPayForm = false;

    public string $payMethod = 'bank';

    public int $payTreasuryAccountId = 0;

    public string $payValueDate = '';

    // ── Reverse run form ──────────────────────────────────────────────
    public bool $showReverseForm = false;

    public string $reverseReason = '';

    public function mount(PayrollRun $run): void
    {
        Gate::authorize(PayrollPermission::VIEW);

        $this->run = $run;
    }

    private function actor(): \App\Support\Audit\Actor
    {
        /** @var \App\Modules\Identity\Models\User $user */
        $user = auth()->user();

        return $user->toAuditActor();
    }

    public function preflightRun(PayrollPreflightCheck $preflight): void
    {
        Gate::authorize(PayrollPermission::RUN);

        try {
            $result = $preflight->handle($this->run);
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

    public function approveRun(ApprovePayrollRun $approve): void
    {
        Gate::authorize(PayrollPermission::APPROVE);

        try {
            $approve->handle((int) $this->run->getKey(), $this->actor());
        } catch (DomainException $e) {
            $this->addError('approve', $e->getMessage());

            return;
        }

        $this->run->refresh();
        session()->flash('status', 'Payroll run approved.');
    }

    public function togglePayForm(): void
    {
        Gate::authorize(PayrollPermission::PAY);

        $this->showPayForm = ! $this->showPayForm;

        if ($this->showPayForm && $this->payValueDate === '') {
            $this->payValueDate = Carbon::now()->toDateString();
        }
    }

    public function preparePayment(PreparePayrollPayment $prepare): void
    {
        Gate::authorize(PayrollPermission::PAY);

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
                (int) $this->run->getKey(),
                $this->payMethod,
                $this->payTreasuryAccountId,
                $this->payValueDate,
                $this->actor(),
            );
        } catch (DomainException|ValidationException $e) {
            $this->addError('payValueDate', $e->getMessage());

            return;
        }

        $this->run->refresh();
        $this->reset(['showPayForm', 'payMethod', 'payTreasuryAccountId', 'payValueDate']);
        session()->flash('status', 'Payment prepared for disbursement.');
    }

    public function generateDeclarations(GenerateStatutoryDeclarations $generate): void
    {
        Gate::authorize(PayrollPermission::DECLARATION_FILE);

        try {
            $count = $generate->handle($this->run->payroll_month->toDateString(), $this->actor());
        } catch (DomainException|ValidationException $e) {
            $this->addError('declarations', $e->getMessage());

            return;
        }

        session()->flash('status', $count.' statutory declaration(s) generated or updated.');
    }

    public function toggleReverseForm(): void
    {
        Gate::authorize(PayrollPermission::REVERSE);

        $this->showReverseForm = ! $this->showReverseForm;
        $this->reverseReason = '';
    }

    public function reverseRun(ReversePayrollRun $reverse): void
    {
        Gate::authorize(PayrollPermission::REVERSE);

        try {
            $reverse->handle((int) $this->run->getKey(), $this->reverseReason, $this->actor());
        } catch (DomainException|ValidationException $e) {
            $this->addError('reverseReason', $e->getMessage());

            return;
        }

        $this->run->refresh();
        $this->reset(['showReverseForm', 'reverseReason']);
        session()->flash('status', 'Payroll run reversed.');
    }

    /**
     * One staff member's payslip PDF for this run. `$payrollItemId` is
     * attacker-controlled (a button's wire:click argument), so it is
     * re-verified against THIS run rather than trusted - the same
     * discipline HR\Livewire\Portal\Show::downloadPayslip applies to
     * `StaffPortalContext`.
     *
     * On an APPROVED run this now returns the OFFICIAL payslip
     * (docs/specs/10-documents.md §11.1) through
     * `Payroll\Actions\PrintPayslip` -> `Reporting\Actions\RenderDocument`:
     * numbered from the PSLIP series, logged, hashed and DUPLICATA-stamped on
     * reprint. The original `PdfExport` table remains the fallback for a run
     * whose figures are not yet final, where there is deliberately no legal
     * document to issue - the button keeps working, it just stops pretending a
     * draft is a payslip.
     */
    public function downloadPayslip(int $payrollItemId): Response
    {
        Gate::authorize(PayrollPermission::VIEW);

        $item = DB::table('payroll_items as pi')
            ->join('staff_members as sm', 'sm.id', '=', 'pi.staff_member_id')
            ->where('pi.id', $payrollItemId)
            ->where('pi.payroll_run_id', $this->run->getKey())
            ->first(['pi.id', 'pi.gross', 'pi.total_employee_deductions', 'pi.net', 'sm.first_name', 'sm.last_name']);

        if ($item === null) {
            abort(404);
        }

        if (PrintPayslip::isIssuable($this->run)) {
            $rendered = app(PrintPayslip::class)->handle($payrollItemId);

            return response($rendered->bytes, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="payslip-'
                    .trim($item->first_name.'-'.$item->last_name).'-'
                    .$this->run->payroll_month->format('Y-m').'.pdf"',
            ]);
        }

        $monthLabel = $this->run->payroll_month->format('F Y');

        $lines = DB::table('payroll_lines as pl')
            ->join('payroll_components as pc', 'pc.id', '=', 'pl.payroll_component_id')
            ->where('pl.payroll_item_id', $item->id)
            ->orderBy('pc.calculation_order')
            ->get(['pc.name', 'pl.amount']);

        $rows = [];

        foreach ($lines as $line) {
            $rows[] = [$line->name, $line->amount];
        }

        $rows[] = ['Gross', $item->gross];
        $rows[] = ['Total deductions', $item->total_employee_deductions];
        $rows[] = ['Net pay', $item->net];

        return PdfExport::download(
            'Payslip - '.$monthLabel,
            ['Component', 'Amount'],
            $rows,
            'payslip-'.trim($item->first_name.'-'.$item->last_name).'-'.$this->run->payroll_month->format('Y-m').'.pdf',
        );
    }

    /**
     * The whole run's payroll register - one row per staff member - as a
     * single PDF. Same PdfExport helper, employer-wide table instead of a
     * per-component breakdown (that belongs on the individual payslip).
     */
    public function downloadRunSummary(): Response
    {
        Gate::authorize(PayrollPermission::VIEW);

        $monthLabel = $this->run->payroll_month->format('F Y');

        $rows = [];

        foreach ($this->staffRows() as $staffRow) {
            $rows[] = [
                $staffRow->staff_name,
                $staffRow->gross,
                $staffRow->total_employee_deductions,
                $staffRow->net,
            ];
        }

        return PdfExport::download(
            'Payroll Register - '.$monthLabel,
            ['Staff', 'Gross', 'Deductions', 'Net'],
            $rows,
            'payroll-register-'.$this->run->payroll_month->format('Y-m').'.pdf',
            'landscape',
        );
    }

    /**
     * @return array<int, \stdClass>
     */
    private function staffRows(): array
    {
        return DB::table('payroll_items as pi')
            ->join('staff_members as sm', 'sm.id', '=', 'pi.staff_member_id')
            ->where('pi.payroll_run_id', $this->run->getKey())
            ->orderBy('sm.last_name')
            ->orderBy('sm.first_name')
            ->get([
                'pi.id as payroll_item_id', 'pi.gross', 'pi.total_employee_deductions', 'pi.net',
                // Needed by the view: a cancelled line has no payslip, so the
                // print control must not be offered for it. isIssuable() is a
                // property of the RUN and cannot answer this per item.
                'pi.is_cancelled',
                'sm.first_name', 'sm.last_name',
                DB::raw("CONCAT(sm.first_name, ' ', sm.last_name) as staff_name"),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{staff_count: int, gross: int, deductions: int, net: int}
     */
    private function totals(): array
    {
        $totals = DB::table('payroll_items')
            ->where('payroll_run_id', $this->run->getKey())
            ->selectRaw('COUNT(*) as staff_count, COALESCE(SUM(gross), 0) as gross, COALESCE(SUM(total_employee_deductions), 0) as deductions, COALESCE(SUM(net), 0) as net')
            ->first();

        return [
            'staff_count' => (int) ($totals->staff_count ?? 0),
            'gross' => (int) ($totals->gross ?? 0),
            'deductions' => (int) ($totals->deductions ?? 0),
            'net' => (int) ($totals->net ?? 0),
        ];
    }

    public function render(): mixed
    {
        $employerProfile = DB::table('employer_profiles')
            ->where('id', $this->run->employer_profile_id)
            ->first(['cnps_employer_number', 'niu']);

        return view('livewire.payroll.show', [
            'staffRows' => $this->staffRows(),
            'totals' => $this->totals(),
            'employerProfile' => $employerProfile,
            // Whether this run's figures are final enough to issue the
            // official numbered payslip (10-documents §11.1).
            'isIssuable' => PrintPayslip::isIssuable($this->run),
            'canRun' => Gate::allows(PayrollPermission::RUN),
            'canApprove' => Gate::allows(PayrollPermission::APPROVE),
            'canPay' => Gate::allows(PayrollPermission::PAY),
            'canReverse' => Gate::allows(PayrollPermission::REVERSE),
            'canFileDeclarations' => Gate::allows(PayrollPermission::DECLARATION_FILE),
        ]);
    }
}
