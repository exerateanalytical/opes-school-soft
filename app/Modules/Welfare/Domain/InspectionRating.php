<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Domain;

/**
 * The verdict of a hostel walk-through, worst-first ordering for reports.
 * `poor` and `critical` are the states the warden's follow-up list keys on
 * (resolved_at NULL = still open).
 */
enum InspectionRating: string
{
    case Good = 'good';
    case Fair = 'fair';
    case Poor = 'poor';
    case Critical = 'critical';

    /** Ratings that put an inspection on the follow-up list. */
    public function needsFollowUp(): bool
    {
        return $this === self::Poor || $this === self::Critical;
    }
}
