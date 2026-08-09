<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain;

/**
 * docs/specs/04-fees.md §2.4, narrowed for v1: payments are RECORDED
 * MANUALLY at the cash desk. There is NO gateway integration - no MoMo API
 * callback, no bank feed - that is a hard v1 decision, so the method set is
 * the three channels a Cameroonian school actually takes money through.
 *
 * MobileMoney is the §15.6 case: the operator settles NET of commission,
 * so a MoMo payment carries a `fee_amount` and posts 552 net / 6317
 * commission / 4111 gross when the school bears the fee.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case MobileMoney = 'mobile_money';
    case Bank = 'bank';

    /**
     * §2.4 `requires_reference`: a MoMo transaction id or bank transfer
     * reference proves the money against the operator/bank statement; cash
     * proves itself in the drawer.
     */
    public function requiresReference(): bool
    {
        return $this !== self::Cash;
    }
}
