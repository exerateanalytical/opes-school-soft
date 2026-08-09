<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain;

/**
 * The supplier-invoice ability names, as string constants.
 *
 * Same contract as ProcurementPermission (which the F2 package owns): the
 * Phase 5 wiring package (F5) adds the `Identity\Domain\Permission` enum
 * cases + role seeds + lang labels for these values in one place; this
 * class exists so the invoice Actions and screens gate on the SAME strings
 * without three parallel packages editing that shared enum concurrently.
 * Values follow the two-segment `module.action` convention.
 *
 * SEGREGATION OF DUTIES (§4.5): create / approve / pay are three distinct
 * permissions across two modules, and the Actions additionally enforce
 * creator ≠ approver at runtime - holding both permissions does not bypass
 * the identity check.
 */
final class SupplierInvoicePermission
{
    /** Read access to the supplier-invoice screens and reports. */
    public const VIEW = 'procurement.invoice_view';

    /** Capture, edit-while-draft, match and cancel supplier invoices. */
    public const CREATE = 'procurement.invoice_create';

    /** Approve a matched invoice; post an approved one. */
    public const APPROVE = 'procurement.invoice_approve';

    /** Approve a mode-`none` direct invoice (§4.4), reason mandatory. */
    public const APPROVE_UNMATCHED = 'procurement.invoice_approve_unmatched';

    /** Override a §4.4 match exception, reason recorded on the invoice. */
    public const OVERRIDE_MATCH = 'procurement.invoice_override_match';

    /** Approve despite `withholding_unresolved` (§6.4.7), reason stored. */
    public const WAIVE_WITHHOLDING = 'procurement.invoice_waive_withholding';

    private function __construct() {}
}
