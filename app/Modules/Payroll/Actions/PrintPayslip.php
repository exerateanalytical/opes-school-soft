<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Modules\Payroll\Domain\PayrollPermission;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Reporting\Actions\RenderDocument;
use App\Modules\Reporting\Domain\AmountInWords;
use App\Modules\Reporting\Domain\DocumentLanguage;
use App\Modules\Reporting\Domain\RenderedDocument;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/10-documents.md §11.1 / 05-hr-payroll.md §14.1 - the payslip for
 * ONE `payroll_items` row.
 *
 * The receipt pattern (see the 330001 seed migration): the immutable
 * "snapshot" is the approved run's payroll_items/payroll_lines row set, so
 * `snapshotId` is the payroll item's own id and a second print of the same
 * item is automatically a DUPLICATA. RenderDocument re-renders the clean
 * artefact from these same rows and compares hashes before it will reprint,
 * which is what holds THIS method to determinism - hence no `now()`, no
 * locale-dependent formatting and no ordering that is not explicit below.
 *
 * A payslip is issued only from an APPROVED (or later) run. A draft
 * calculation is a working figure; handing a member of staff a legal pay
 * document for a figure that may still change is the one thing this refuses.
 */
final class PrintPayslip
{
    /** 05-hr-payroll §9: the run states at which the figures are final. */
    private const ISSUABLE_STATUSES = ['approved', 'paid', 'closed'];

    public function __construct(private readonly RenderDocument $render) {}

    public function handle(int $payrollItemId, ?string $language = null): RenderedDocument
    {
        Gate::authorize(PayrollPermission::VIEW);

        $item = DB::table('payroll_items as pi')
            ->join('payroll_runs as pr', 'pr.id', '=', 'pi.payroll_run_id')
            ->join('staff_members as sm', 'sm.id', '=', 'pi.staff_member_id')
            ->where('pi.id', $payrollItemId)
            ->first([
                'pi.id', 'pi.payroll_run_id', 'pi.staff_contract_id', 'pi.payroll_month',
                'pi.is_cancelled', 'pi.days_worked', 'pi.days_in_period',
                'pi.gross', 'pi.sbt', 'pi.taxable_base', 'pi.irpp_amount',
                'pi.total_employee_deductions', 'pi.total_employer_charges', 'pi.net',
                'pi.ytd_sbt', 'pi.ytd_irpp_withheld',
                'pr.status as run_status', 'pr.employer_profile_id',
                'sm.staff_no', 'sm.first_name', 'sm.last_name',
            ]);

        if ($item === null) {
            throw new DomainException("Payroll item {$payrollItemId} does not exist.");
        }

        // The query builder hands back a bare stdClass. Projecting it to an
        // array once, here, is what lets every read below state the type it
        // expects instead of trusting a dynamic property (PHPStan level 8).
        $row = (array) $item;

        if ((bool) $item->is_cancelled) {
            throw new DomainException(
                "Payroll item {$payrollItemId} is cancelled; a cancelled line has no payslip to issue."
            );
        }

        if (! in_array((string) $item->run_status, self::ISSUABLE_STATUSES, true)) {
            throw new DomainException(sprintf(
                'Payroll run for item %d is [%s]; a payslip is issued only from an approved run, because a '
                .'draft calculation is a working figure and a payslip is a legal pay document (05-hr-payroll §9).',
                $payrollItemId,
                (string) $item->run_status,
            ));
        }

        $lang = DocumentLanguage::tryFrom($language ?? '') ?? DocumentLanguage::En;
        $staffName = trim($this->str($row, 'first_name').' '.$this->str($row, 'last_name'));
        $month = substr($this->str($row, 'payroll_month'), 0, 7);

        return $this->render->handle(
            templateCode: 'PAYSLIP',
            subjectType: 'PayrollItem',
            subjectId: $payrollItemId,
            subjectLabel: 'Payslip '.$month.' - '.$staffName,
            snapshotId: $payrollItemId,
            language: $language,
            data: $this->payload($row, $staffName, $lang),
            // The PSLIP series is payroll-month scoped: the number belongs to
            // the month being paid, not to the month the button was clicked.
            seriesScopeValue: $month,
        );
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function payload(array $item, string $staffName, DocumentLanguage $lang): array
    {
        $employer = DB::table('employer_profiles')
            ->where('id', $this->int($item, 'employer_profile_id'))
            ->first(['cnps_employer_number', 'dipe_number', 'niu']);

        $contract = DB::table('staff_contracts')
            ->where('id', $this->int($item, 'staff_contract_id'))
            ->first(['contract_type', 'contract_role']);

        $payment = DB::table('payroll_payment_lines as ppl')
            ->join('payroll_payments as pp', 'pp.id', '=', 'ppl.payroll_payment_id')
            ->where('ppl.payroll_item_id', $this->int($item, 'id'))
            ->orderBy('pp.id')
            ->first(['pp.payment_method', 'pp.value_date']);

        // 14.1's print blocks: earnings, employee deductions, employer charges
        // and informational lines are FOUR blocks, not one signed list -
        // netting an employer charge against pay would understate the net.
        $blocks = [
            'earning' => [],
            'employee_deduction' => [],
            'employer_charge' => [],
            'informational' => [],
        ];

        $lines = DB::table('payroll_lines as pl')
            ->join('payroll_components as pc', 'pc.id', '=', 'pl.payroll_component_id')
            ->where('pl.payroll_item_id', $this->int($item, 'id'))
            // print_order is the payslip's declared layout; calculation_order
            // and the line id are the deterministic tie-breakers, so the same
            // item always renders the same bytes.
            ->orderBy('pc.print_order')
            ->orderBy('pc.calculation_order')
            ->orderBy('pl.id')
            ->get([
                'pc.name', 'pc.name_fr', 'pc.type',
                'pl.base_amount', 'pl.applied_rate_bp', 'pl.amount',
            ]);

        foreach ($lines as $line) {
            $type = (string) $line->type;

            if (! array_key_exists($type, $blocks)) {
                continue;
            }

            $blocks[$type][] = [
                'name' => $lang === DocumentLanguage::Fr && is_string($line->name_fr) && $line->name_fr !== ''
                    ? $line->name_fr
                    : (string) $line->name,
                'base_amount' => (int) $line->base_amount,
                // Basis points, printed as a percentage with two decimals -
                // no float arithmetic (00-core §7.1).
                'rate' => $line->applied_rate_bp === null
                    ? null
                    : sprintf('%d.%02d %%', intdiv((int) $line->applied_rate_bp, 100), (int) $line->applied_rate_bp % 100),
                'amount' => (int) $line->amount,
            ];
        }

        $chrome = $this->render->captureSchoolChrome(includeStateHeader: false);
        $legalName = is_array($chrome['fiscal'] ?? null) ? ($chrome['fiscal']['legal_name'] ?? null) : null;

        return [
            'school' => $chrome,
            'payslip' => [
                'employer' => [
                    // The legal name on the fiscal identity is what a CNPS or
                    // DGI inspector reconciles against; the trading name in the
                    // letterhead above is not necessarily the same string.
                    'name' => is_string($legalName) && $legalName !== '' ? $legalName : ($chrome['name'] ?? null),
                    'cnps_employer_number' => $employer->cnps_employer_number ?? null,
                    'dipe_number' => $employer->dipe_number ?? null,
                    'niu' => $employer->niu ?? null,
                ],
                'employee' => [
                    'name' => $staffName,
                    'staff_no' => $this->str($item, 'staff_no'),
                    'position' => $contract->contract_role ?? null,
                    'contract_type' => $contract->contract_type ?? null,
                ],
                'period' => substr($this->str($item, 'payroll_month'), 0, 7),
                'days_worked' => $this->str($item, 'days_worked'),
                'days_in_period' => $this->str($item, 'days_in_period'),
                'earnings' => $blocks['earning'],
                'employee_deductions' => $blocks['employee_deduction'],
                'employer_charges' => $blocks['employer_charge'],
                'informational' => $blocks['informational'],
                'gross' => $this->int($item, 'gross'),
                'sbt' => $this->int($item, 'sbt'),
                'taxable_base' => $this->int($item, 'taxable_base'),
                'irpp' => $this->int($item, 'irpp_amount'),
                'total_deductions' => $this->int($item, 'total_employee_deductions'),
                'total_employer_charges' => $this->int($item, 'total_employer_charges'),
                'net' => $this->int($item, 'net'),
                'net_words' => AmountInWords::render($this->int($item, 'net'), $lang),
                'payment_method' => $payment->payment_method ?? null,
                'payment_date' => $payment->value_date ?? null,
                'ytd_sbt' => $this->int($item, 'ytd_sbt'),
                'ytd_irpp' => $this->int($item, 'ytd_irpp_withheld'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function int(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function str(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * The run, for callers that want to check issuability before offering a
     * print button (the Payroll run detail screen does exactly that).
     */
    public static function isIssuable(PayrollRun $run): bool
    {
        return in_array($run->status->value, self::ISSUABLE_STATUSES, true);
    }
}
