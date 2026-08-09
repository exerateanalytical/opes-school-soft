<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain;

/**
 * docs/specs/03-tax-procurement.md §4.7 `fee_bearer` - whose money the
 * operator commission was. Only a SCHOOL-borne fee enters the books
 * (Dr 6317 / Cr treasury, mirroring 04-fees §15.6); a supplier-borne fee
 * is between the operator and the supplier and never touches the ledger.
 */
enum SupplierFeeBearer: string
{
    case School = 'school';
    case Supplier = 'supplier';
}
