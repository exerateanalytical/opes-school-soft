<?php

declare(strict_types=1);

namespace App\Modules\Activities\Domain;

/**
 * Guardian consent for an excursion, per membership (gap-analysis row 15's
 * activity-consent slice, held as columns on the membership for the MVP
 * rather than a separate Consent module).
 *
 * `pending` is the state EnrolStudent stamps on every excursion
 * membership; RecordConsent moves it to granted or declined and records
 * WHICH guardian decided, when, and who keyed it - never silently back to
 * pending.
 */
enum ConsentStatus: string
{
    case Pending = 'pending';
    case Granted = 'granted';
    case Declined = 'declined';

    /** A decision a guardian can actually hand down (not the waiting state). */
    public function isDecision(): bool
    {
        return $this !== self::Pending;
    }
}
