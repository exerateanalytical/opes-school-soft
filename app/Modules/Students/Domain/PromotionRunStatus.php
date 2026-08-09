<?php

declare(strict_types=1);

namespace App\Modules\Students\Domain;

/**
 * docs/specs/07-students.md §10.2 — lifecycle of a PromotionRun.
 *
 * `applied` is terminal by design: cancelling an applied run is NOT supported
 * (§10.6), the corrective path is a per-student manual enrollment correction,
 * because reversing 45 next-year enrollments after invoicing has run against
 * them is a data-integrity problem 04-fees cannot absorb.
 */
enum PromotionRunStatus: string
{
    case Evaluating = 'evaluating';
    case Evaluated = 'evaluated';
    case UnderReview = 'under_review';
    case Applying = 'applying';
    case Applied = 'applied';
    case Cancelled = 'cancelled';

    /** States from which a (re-)evaluation may run. */
    public function allowsEvaluation(): bool
    {
        return match ($this) {
            self::Evaluating, self::Evaluated, self::UnderReview => true,
            self::Applying, self::Applied, self::Cancelled => false,
        };
    }

    /**
     * States the §10.6 step-2 conditional UPDATE accepts. `under_review` is
     * included because an override moves the run there, and a reviewed list
     * is precisely the one the conseil signed off on.
     */
    public function allowsApply(): bool
    {
        return $this === self::Evaluated || $this === self::UnderReview;
    }

    public function label(): string
    {
        return match ($this) {
            self::Evaluating => 'Evaluating',
            self::Evaluated => 'Evaluated',
            self::UnderReview => 'Under review',
            self::Applying => 'Applying',
            self::Applied => 'Applied',
            self::Cancelled => 'Cancelled',
        };
    }
}
