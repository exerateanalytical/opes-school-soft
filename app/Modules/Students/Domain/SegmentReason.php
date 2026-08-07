<?php

declare(strict_types=1);

namespace App\Modules\Students\Domain;

/**
 * 07-students 5.1. Why a segment exists. `Initial` is reserved for the segment
 * created with the enrollment (5.2: "the first segment's starts_on =
 * Enrollment.enrolled_on; reason = 'initial'"), so a second `initial` segment
 * on one enrollment is always a bug.
 */
enum SegmentReason: string
{
    case Initial = 'initial';
    case ClassTransfer = 'class_transfer';
    case StreamChange = 'stream_change';
    case GroupRebalance = 'group_rebalance';
    case Correction = 'correction';

    /** 5.1: `reason_text` is mandatory for a correction. */
    public function requiresReasonText(): bool
    {
        return $this === self::Correction;
    }
}
