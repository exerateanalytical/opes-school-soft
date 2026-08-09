<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * The Payroll ability names, as string constants.
 *
 * Same contract as HR's HrPermission, Procurement's ProcurementPermission
 * and Assets' AssetPermission: the Phase 11 wiring package (F5) adds the
 * `Identity\Domain\Permission` enum cases + role seeds + lang labels for
 * these values in ONE place; this class exists so the Payroll Actions and
 * screens gate on the SAME strings without parallel packages editing that
 * shared enum concurrently (docs/plans/phase-11.md).
 */
final class PayrollPermission
{
    /** Read access to runs, payslips and the compliance calendar. */
    public const VIEW = 'payroll.view';

    /** Calculate a run. */
    public const RUN = 'payroll.run';

    /** Approve a calculated run (segregated from the calculator). */
    public const APPROVE = 'payroll.approve';

    /** Reverse an approved run (contrepassation). */
    public const REVERSE = 'payroll.reverse';

    /** Employer profile, statutory rates, components, formulas. */
    public const CONFIGURE = 'payroll.configure';

    /** Prepare and export disbursement files. */
    public const PAY = 'payroll.pay';

    /** Per-contract RP risk-class override (05-hr-payroll 3.2). */
    public const OVERRIDE_RISK_CLASS = 'payroll.override_risk_class';

    /** Override the CNPS-liable default for vacataires (05-hr-payroll 5.5). */
    public const CLASSIFY_NON_EMPLOYEE = 'payroll.classify_non_employee';

    /** File statutory declarations (05-hr-payroll 11). */
    public const DECLARATION_FILE = 'declaration.file';

    private function __construct() {}
}
