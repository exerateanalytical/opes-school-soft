<?php

declare(strict_types=1);

namespace App\Modules\Alumni\Domain;

/**
 * The kinds of touch point an alumni office logs. `Other` is deliberate:
 * a fixed enum with no escape valve is how "attended the 50th anniversary
 * gala" ends up recorded as a donation.
 */
enum EngagementType: string
{
    case Donation = 'donation';
    case Visit = 'visit';
    case Talk = 'talk';
    case Mentorship = 'mentorship';
    case Other = 'other';

    public function label(): string
    {
        return __('alumni.engagement_type.'.$this->value);
    }
}
