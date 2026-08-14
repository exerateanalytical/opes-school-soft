<?php

declare(strict_types=1);

namespace App\Modules\Activities\Domain;

/**
 * The student's role inside an activity. `captain` reads naturally for a
 * sports team, `leader` for a club or event committee; the enum does not
 * police which role goes with which activity type - that is a naming
 * convention, not an invariant.
 */
enum MembershipRole: string
{
    case Member = 'member';
    case Captain = 'captain';
    case Leader = 'leader';
}
