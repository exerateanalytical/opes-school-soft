<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain;

/**
 * Where a mark sits in the approval chain (docs/specs/01-assessment.md §7.1,
 * §7.2) — deliberately NOT the same axis as MarkState.
 *
 * v1 had one status column trying to be both, which makes "a validated
 * absence" and "a draft 14" inexpressible. `state` says what happened in the
 * classroom; `workflow_state` says who has signed it off. Neither is derivable
 * from the other, and conflating them is the defect this enum exists to
 * prevent.
 *
 *     subject teacher enters       → draft → submitted
 *     head of department verifies  → validated  (or → draft with a reason)
 */
enum WorkflowState: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Validated = 'validated';

    /**
     * §7.4. A draft mark is freely editable. Editing a SUBMITTED mark is
     * allowed but returns it to draft and clears submitted_*. Editing a
     * VALIDATED mark is refused outright — it must be returned first, by
     * someone holding the validating role, and after publication it can only
     * move through Amendment (§15).
     */
    public function isEditable(): bool
    {
        return $this !== self::Validated;
    }

    /** Editing a submitted mark un-submits it rather than silently amending a batch under review. */
    public function revertsToDraftOnEdit(): bool
    {
        return $this === self::Submitted;
    }

    /**
     * The legal transitions, and only these:
     *
     *   draft     → submitted   (teacher submits for validation)
     *   submitted → validated   (HoD validates)
     *   submitted → draft       (HoD returns with a reason)
     *   validated → draft       (HoD reopens; §7.4 "returned first")
     *
     * draft → validated is absent on purpose: nothing is validated that was
     * never submitted, or the chain has no meaning.
     */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft => $target === self::Submitted,
            self::Submitted => $target === self::Validated || $target === self::Draft,
            self::Validated => $target === self::Draft,
        };
    }

    /** §7.4: publication requires every non-pending mark in scope to be validated. */
    public function satisfiesValidationGate(): bool
    {
        return $this === self::Validated;
    }
}
