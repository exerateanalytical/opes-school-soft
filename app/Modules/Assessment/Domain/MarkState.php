<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain;

/**
 * Assessment semantics of a single component mark — orthogonal to the approval
 * chain's workflow_state (docs/specs/01-assessment.md §6.1, §6.4).
 *
 * The distinction between the two absences is worth 8.40 points out of 20 in a
 * single subject (§6.4 worked cases 1 and 2), which is why `state` is a
 * controlled field and never inferred.
 */
enum MarkState: string
{
    /** No mark yet. Blocks publication; never silently treated as anything (§6.4). */
    case Pending = 'pending';

    /** A number was entered. Ratio = score / effectiveMax, weight retained. */
    case Scored = 'scored';

    /** Certified absence: excluded from the numerator, weight REMOVED and the survivors renormalised. */
    case AbsentJustified = 'absent_justified';

    /** Unexcused absence: ratio 0.000000, weight RETAINED — excluding it would reward absence. */
    case AbsentUnjustified = 'absent_unjustified';

    /** Medical or structural exemption: excluded, weight REMOVED, survivors renormalised. */
    case Exempt = 'exempt';

    /** Does this state contribute a ratio, with its weight retained in the denominator? */
    public function retainsWeight(): bool
    {
        return $this === self::Scored || $this === self::AbsentUnjustified;
    }

    /** Does this state vanish, renormalising the surviving weights (§6.4)? */
    public function renormalises(): bool
    {
        return $this === self::AbsentJustified || $this === self::Exempt;
    }

    // -----------------------------------------------------------------------
    // Entry-side predicates.
    //
    // Three of the five cases carry `score IS NULL`, so "the score is null" is
    // never an answer to "what happened here". SaveMark leans on these rather
    // than on the nullability of the column, which is exactly the collapse
    // §6.4 forbids: pending, absent_justified and exempt are all score-less
    // and all mean something different downstream.
    // -----------------------------------------------------------------------

    /** Invariant 4: (state = scored) <=> (score IS NOT NULL). */
    public function requiresScore(): bool
    {
        return $this === self::Scored;
    }

    /** Anything but `pending` — somebody has said what happened. */
    public function isResolved(): bool
    {
        return $this !== self::Pending;
    }

    /** §13.2: a pending component stops the period publishing. */
    public function blocksPublication(): bool
    {
        return $this === self::Pending;
    }

    /**
     * §6.4: certifying an absence or granting an exemption moves a student by
     * whole points, so both are controlled fields — Class Master or above,
     * with a reason, and audited.
     */
    public function requiresReason(): bool
    {
        return $this === self::AbsentJustified || $this === self::Exempt;
    }

    /** What the bulletin prints in place of a number (§6.4). */
    public function printedMarker(): ?string
    {
        return match ($this) {
            self::Scored, self::Pending => null,
            self::AbsentUnjustified => 'AbNJ',
            self::AbsentJustified => 'AbJ',
            self::Exempt => 'Disp.',
        };
    }

    /**
     * The four states a teacher may set from the entry grid (§17's `a`, `j`,
     * `x` shortcuts plus a typed number). `pending` is reachable only by
     * clearing a cell, which SaveMark treats as a distinct operation.
     *
     * @return list<self>
     */
    public static function enterableCases(): array
    {
        return [self::Scored, self::AbsentUnjustified, self::AbsentJustified, self::Exempt];
    }
}
