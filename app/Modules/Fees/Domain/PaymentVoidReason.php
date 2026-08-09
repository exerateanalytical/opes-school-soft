<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain;

/**
 * docs/specs/04-fees.md §11.5 `payment_voids.reason_type`.
 */
enum PaymentVoidReason: string
{
    case KeyingError = 'keying_error';
    case DuplicateCapture = 'duplicate_capture';
    case WrongStudent = 'wrong_student';
    case FundsNotReceived = 'funds_not_received';
    case CashierError = 'cashier_error';
    case FraudInvestigation = 'fraud_investigation';
}
