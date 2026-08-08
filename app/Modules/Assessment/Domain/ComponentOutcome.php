<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain;

use App\Support\Score\Score;

/**
 * What the pipeline actually did with one component — the `AppliedPolicyNote`
 * of docs/specs/01-assessment.md §6.4, so a printed card can be explained two
 * years later without re-deriving it from marks that have since changed.
 */
final readonly class ComponentOutcome
{
    public function __construct(
        public string $componentCode,
        public MarkState $originalState,
        /** The state the component was graded AS, after any policy application. */
        public MarkState $effectiveState,
        public Score $effectiveMaximum,
        public int $weight,
        /** False when the weight was removed and the survivors renormalised (§6.4). */
        public bool $weightRetained,
        /** Non-null only where $originalState was Pending and a policy was applied. */
        public ?MissingComponentPolicy $appliedPolicy = null,
    ) {}

    public function wasPolicyApplied(): bool
    {
        return $this->appliedPolicy !== null;
    }
}
