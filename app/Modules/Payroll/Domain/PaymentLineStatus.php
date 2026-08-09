<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * docs/specs/05-hr-payroll.md 8.8. Only a `failed` line releases its
 * payroll item for re-disbursement (the generated live_item_key UNIQUE).
 */
enum PaymentLineStatus: string
{
    case Pending = 'pending';
    case Exported = 'exported';
    case Confirmed = 'confirmed';
    case Failed = 'failed';
}
