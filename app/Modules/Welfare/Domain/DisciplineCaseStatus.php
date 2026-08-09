<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Domain;

/**
 * Lifecycle of one DisciplineCase (docs/plans/phase-08.md F3).
 *
 * `resolved` and `dismissed` are both terminal but mean different things to
 * the promotion criterion: a dismissed case was found baseless and the read
 * door excludes it from the counts; a resolved case happened and stays
 * counted. Collapsing them would let "we investigated and it was nothing"
 * cost a student their promotion.
 */
enum DisciplineCaseStatus: string
{
    case Open = 'open';
    case UnderInvestigation = 'under_investigation';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';

    public function isTerminal(): bool
    {
        return $this === self::Resolved || $this === self::Dismissed;
    }

    /**
     * The allowed transition graph, enforced by the Actions and not merely
     * the UI (same discipline as Students\EnrollmentStatus).
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Open => [self::UnderInvestigation, self::Resolved, self::Dismissed],
            self::UnderInvestigation => [self::Resolved, self::Dismissed],
            self::Resolved, self::Dismissed => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }
}
