<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain;

/**
 * docs/specs/04-fees.md §8/§8.1. Different reason types are materially
 * different transactions - the reason type resolves the posting account.
 */
enum FeeAdjustmentReasonType: string
{
    case Correction = 'correction';
    case ScholarshipInternal = 'scholarship_internal';
    case ScholarshipDonorFunded = 'scholarship_donor_funded';
    case SiblingDiscount = 'sibling_discount';
    case StaffChild = 'staff_child';
    case Hardship = 'hardship';
    case EarlyPaymentDiscount = 'early_payment_discount';
    case SurchargeLatePayment = 'surcharge_late_payment';
    case Goodwill = 'goodwill';
}
