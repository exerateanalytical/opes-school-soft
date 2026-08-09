<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain;

/**
 * The supplier-payment ability names, as string constants.
 *
 * Same contract as ProcurementPermission / SupplierInvoicePermission: the
 * Phase 5 wiring package (F5) adds the `Identity\Domain\Permission` enum
 * cases + role seeds + lang labels for these values in one place; this
 * class exists so the payment Actions and screens gate on the SAME strings
 * without parallel packages editing that shared enum concurrently.
 *
 * SEGREGATION OF DUTIES (§11.14): record / approve / void are distinct
 * permissions, and the Actions additionally enforce the identity checks
 * (creator ≠ approver, approver ≠ payer, recorder ≠ voider) at runtime -
 * holding several permissions does not bypass them.
 */
final class SupplierPaymentPermission
{
    /** Record a payment draft and execute an approved one. */
    public const RECORD = 'procurement.payment_record';

    /** Approve a drafted payment; release retentions; export batches. */
    public const APPROVE = 'procurement.payment_approve';

    /** Void a paid payment (§4.7 immutability - the only correction). */
    public const VOID = 'procurement.payment_void';

    private function __construct() {}
}
