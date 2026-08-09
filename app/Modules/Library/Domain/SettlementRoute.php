<?php

declare(strict_types=1);

namespace App\Modules\Library\Domain;

/**
 * docs/specs/06-assets-stores.md §10.6 - snapshotted at levy so a member
 * who converts (student hired as staff) does not reroute historic fines.
 */
enum SettlementRoute: string
{
    case StudentReceivable = 'student_receivable';
    case StaffPayrollDeduction = 'staff_payroll_deduction';
    case CashImmediate = 'cash_immediate';
}
