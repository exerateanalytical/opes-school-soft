<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain;

/**
 * The six framework families of docs/specs/01-assessment.md 3.2.
 *
 * Family F (MINEDUB maternelle) is mandatory scope, not an extra: the product
 * is sold to nursery-only schools. It is competency-only - no marks, no
 * coefficients, no rank - which is why the predicates below exist rather than
 * being re-derived from `assessment_mode` at each call site.
 */
enum FrameworkFamily: string
{
    case A = 'A';
    case B = 'B';
    case C = 'C';
    case D = 'D';
    case E = 'E';
    case F = 'F';

    public function label(string $locale = 'en'): string
    {
        return __('opes.framework_family.'.$this->value, [], $locale);
    }

    /**
     * A/B/C are the MINESEC secondary families. 01-assessment 14 requires
     * per-lesson attendance for them, so CreateFramework refuses to build one
     * without it - the bulletin has an attendance block that cannot be filled
     * from daily registers.
     */
    public function isMinesecSecondary(): bool
    {
        return $this === self::A || $this === self::B || $this === self::C;
    }

    /** D/E/F are the MINEDUB primary and nursery families. */
    public function isMinedub(): bool
    {
        return ! $this->isMinesecSecondary();
    }

    /**
     * 01-assessment 3.2 and 8: Family F produces observations, not numbers.
     * Rank, average, mention and Sigma-coefficient must be absent from its
     * payload entirely (T19) - absent, not zero and not hidden by the view.
     */
    public function isCompetencyOnly(): bool
    {
        return $this === self::F;
    }

    /**
     * 00-core blocking gate 5. Evidence indicates Anglophone secondary schools
     * mark internally out of 20 exactly as Francophone ones do, and that GCE
     * letter grades belong to the Board examination only - but that is not
     * confirmed. Nothing is seeded for Family B until an administrator
     * confirms the basis against a specimen report card.
     */
    public function needsScaleConfirmation(): bool
    {
        return $this === self::B;
    }
}
