<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Domain;

/**
 * The sanction catalogue (docs/plans/phase-08.md F3 migration 260010).
 *
 * `consigne` (the Francophone Saturday-detention) and `detention` are both
 * carried because the two subsystems use different words for the same
 * punishment family and the MINESEC bulletin's "consignes" line counts BOTH
 * (07-students §9.7 counts sanctions of type detention; the Francophone
 * report prints them as consignes). Keeping the two values distinct lets a
 * bilingual school record whichever word the incident book used.
 */
enum SanctionType: string
{
    case Warning = 'warning';
    case Detention = 'detention';
    case Consigne = 'consigne';
    case Suspension = 'suspension';
    case Exclusion = 'exclusion';
    case CommunityService = 'community_service';
    case GuardianSummons = 'guardian_summons';

    /**
     * Whether applying this sanction changes the enrollment lifecycle and
     * must therefore go through the Students door — never a direct write.
     */
    public function suspendsEnrollment(): bool
    {
        return $this === self::Suspension;
    }

    /**
     * Position on the escalation ladder, mildest first. Types off the ladder
     * (community service, guardian summons) are alternatives at their
     * severity peers' level, not rungs — see SanctionLadder.
     */
    public function ladderRank(): int
    {
        return match ($this) {
            self::Warning => 0,
            self::Detention, self::Consigne, self::CommunityService => 1,
            self::GuardianSummons => 2,
            self::Suspension => 3,
            self::Exclusion => 4,
        };
    }
}
