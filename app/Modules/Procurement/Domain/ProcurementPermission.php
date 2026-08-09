<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain;

/**
 * The Procurement ability names, as string constants.
 *
 * Phase 5's wiring package (F5) owns `Identity\Domain\Permission` and adds
 * the enum cases + role seeds + lang labels for these values in one place;
 * this class exists so the Procurement Actions and screens built in the
 * parallel F2 package gate on the SAME strings without editing that shared
 * enum concurrently. Values follow the enum's two-segment `module.action`
 * convention (the values double as translation keys, and Laravel reads a
 * dot as a nested-array step).
 *
 * Spatie resolves abilities by name at runtime, so a permission row + grant
 * is all a holder needs; the enum case is the compile-time face F5 adds.
 */
final class ProcurementPermission
{
    /** Read access to every procurement screen; drafting a requisition. */
    public const VIEW = 'procurement.view';

    /** Create/edit/archive suppliers and categories; edit settings. */
    public const SUPPLIER_MANAGE = 'procurement.supplier_manage';

    /** Override the §3.2 exact-duplicate hard block, reason mandatory. */
    public const SUPPLIER_OVERRIDE_DUPLICATE = 'procurement.supplier_override_duplicate';

    /** Approve or reject a submitted requisition (§4.1). */
    public const REQUISITION_APPROVE = 'procurement.requisition_approve';

    /** Create/send/amend/close/cancel POs; record goods receipts (§4.2/4.3). */
    public const ORDER_MANAGE = 'procurement.order_manage';

    /** Approve a PO within the matching §4.2 threshold band. */
    public const ORDER_APPROVE = 'procurement.order_approve';

    private function __construct() {}
}
