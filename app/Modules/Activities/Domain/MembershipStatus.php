<?php

declare(strict_types=1);

namespace App\Modules\Activities\Domain;

/**
 * Same two-state lifecycle as a transport allocation: NULL-unique
 * `active_key` on the table permits any number of ended rows per student
 * per activity and at most one live one, so a race on double-enrol loses
 * with a duplicate-key error instead of a silent second membership.
 */
enum MembershipStatus: string
{
    case Active = 'active';
    case Ended = 'ended';
}
