<?php

declare(strict_types=1);

namespace App\Modules\Library\Domain;

/** docs/specs/06-assets-stores.md §10.3. */
enum MemberType: string
{
    case Student = 'student';
    case Staff = 'staff';
    case External = 'external';

    /**
     * §10.6: the settlement route is derived from the member type AT LEVY
     * and snapshotted onto the fine.
     */
    public function settlementRoute(): SettlementRoute
    {
        return match ($this) {
            self::Student => SettlementRoute::StudentReceivable,
            self::Staff => SettlementRoute::StaffPayrollDeduction,
            self::External => SettlementRoute::CashImmediate,
        };
    }
}
