<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain;

/**
 * The three acts of the approval chain (docs/specs/01-assessment.md §7.2).
 *
 * A decision is not the same thing as a state: `submit` is what a teacher
 * DOES, `submitted` is where the marks then SIT. Keeping the verb separate
 * from the noun is what lets `mark_approvals` record who decided what, and
 * when, rather than only where the batch ended up.
 *
 * Framework-free by rule (00-core §6.2 rule 1) — the permission names are
 * carried as plain strings so this enum stays importable from the Domain
 * without dragging Identity's enum into a pure namespace.
 */
enum ApprovalDecision: string
{
    /** Teacher sends the grid up. draft → submitted. */
    case Submit = 'submit';

    /** Head of department verifies. submitted → validated. */
    case Validate = 'validate';

    /** Head of department returns it with a reason. submitted|validated → draft. */
    case Reject = 'reject';

    /**
     * The permission the actor must hold.
     *
     * §7.2's flow is two-person by design: entering and approving are
     * different rights, and the Vice-Principal deliberately holds
     * `marks.validate` WITHOUT `marks.enter` so an approver cannot also be the
     * author.
     */
    public function requiredPermission(): string
    {
        return match ($this) {
            self::Submit => 'marks.enter',
            self::Validate, self::Reject => 'marks.validate',
        };
    }

    /** Where the marks in scope must already sit for this decision to be legal. */
    public function requiredCurrentState(): WorkflowState
    {
        return match ($this) {
            self::Submit => WorkflowState::Draft,
            self::Validate, self::Reject => WorkflowState::Submitted,
        };
    }

    public function resultingState(): WorkflowState
    {
        return match ($this) {
            self::Submit => WorkflowState::Submitted,
            self::Validate => WorkflowState::Validated,
            self::Reject => WorkflowState::Draft,
        };
    }

    /** The `mark_approvals.status` the batch header carries afterwards. */
    public function resultingBatchStatus(): string
    {
        return match ($this) {
            self::Submit => 'submitted',
            self::Validate => 'validated',
            self::Reject => 'returned',
        };
    }

    /**
     * §7.3: a return with no reason is how a teacher loses an afternoon with
     * nothing to act on, so the reason is mandatory on rejection only.
     */
    public function requiresReason(): bool
    {
        return $this === self::Reject;
    }

    public function isLegalFrom(WorkflowState $current): bool
    {
        return $current->canTransitionTo($this->resultingState());
    }
}
