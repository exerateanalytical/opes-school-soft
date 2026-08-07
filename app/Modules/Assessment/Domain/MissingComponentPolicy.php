<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain;

/**
 * What a framework does with a `pending` component at forced publication —
 * and NOTHING else (docs/specs/01-assessment.md §6.4).
 *
 * It never touches the four resolved states. That containment is the safety fix
 * for v1's C4 warning: `redistribute` must not become the mechanism by which a
 * student who simply missed the exam is awarded their CA alone as a final mark.
 */
enum MissingComponentPolicy: string
{
    /** Publication refuses and returns the pending list. Safest. */
    case BlockPublication = 'block_publication';

    /** The component becomes `absent_unjustified`: ratio 0, weight retained. */
    case Zero = 'zero';

    /**
     * `exempt` ONLY where a justified-absence attendance record covers the
     * component's assessment date; otherwise it falls through to `zero`.
     */
    case Redistribute = 'redistribute';
}
