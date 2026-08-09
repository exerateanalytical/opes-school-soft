<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain;

use App\Modules\Procurement\Models\PurchaseRequisition;

/**
 * docs/specs/03-tax-procurement.md §4.1 - the outcome of ApproveRequisition.
 *
 * `warn` budget enforcement must surface its warning WITHOUT failing the
 * approval, so the Action returns the requisition and the warning set
 * together; the UI shows the warnings, the operator proceeds. Under `block`
 * the Action throws instead and this object never exists.
 */
final readonly class RequisitionApprovalResult
{
    /**
     * @param  list<string>  $warnings
     */
    public function __construct(
        public PurchaseRequisition $requisition,
        public array $warnings,
    ) {}
}
