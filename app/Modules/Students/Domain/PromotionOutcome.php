<?php

declare(strict_types=1);

namespace App\Modules\Students\Domain;

/**
 * docs/specs/07-students.md §10.5 — the seven-value verdict of the promotion
 * engine, richer than the four-value `decision` vocabulary Phase 7's rollover
 * grid writes. Both live on ONE promotion_decisions row; `legacyDecision()`
 * is the mapping that keeps the rollover wizard's step 6 reading the same
 * answer the engine computed.
 */
enum PromotionOutcome: string
{
    case Promote = 'promote';
    case Repeat = 'repeat';
    case ConditionalPromote = 'conditional_promote';
    case Graduate = 'graduate';
    case Exclude = 'exclude';
    case ManualReview = 'manual_review';
    case Indeterminate = 'indeterminate';

    /**
     * The Phase 7 `decision` value this outcome converges on, or NULL for the
     * two genuinely undecided states — which is exactly what makes the
     * rollover's "refuses while any enrollment is undecided" guard fire.
     */
    public function legacyDecision(): ?string
    {
        return match ($this) {
            self::Promote, self::ConditionalPromote => 'promoted',
            self::Repeat => 'repeat',
            self::Graduate => 'graduated',
            self::Exclude => 'withdrawn',
            self::ManualReview, self::Indeterminate => null,
        };
    }

    /** The states apply refuses to act on (§10.6 step 3 and its review twin). */
    public function isUndecided(): bool
    {
        return $this === self::ManualReview || $this === self::Indeterminate;
    }

    /** Outcomes that create a next-year enrollment at apply (§10.6 step 5). */
    public function createsNextYearEnrollment(): bool
    {
        return match ($this) {
            self::Promote, self::ConditionalPromote, self::Repeat => true,
            self::Graduate, self::Exclude, self::ManualReview, self::Indeterminate => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Promote => 'Promote',
            self::Repeat => 'Repeat',
            self::ConditionalPromote => 'Conditional promote',
            self::Graduate => 'Graduate',
            self::Exclude => 'Exclude',
            self::ManualReview => 'Manual review',
            self::Indeterminate => 'Indeterminate',
        };
    }
}
