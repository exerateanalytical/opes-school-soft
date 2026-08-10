<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * The expense document's life (docs/specs/02-accounting.md §21.3):
 * draft → submitted → approved → posted, with `rejected` as the terminal
 * refusal an approver may issue instead of approving.
 *
 * The spec names four states; `Rejected` is added because "approved" has to
 * have an opposite that is recorded rather than implied by a deleted row -
 * deleting a submitted expense is refused by the table's own trigger.
 *
 * `approved` is skipped only when the expense falls BELOW the configurable
 * maker-checker threshold, in which case SubmitExpense lands it straight in
 * `approved` and records why (see ApproveExpense's docblock).
 */
enum ExpenseStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Posted = 'posted';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::Approved => 'Approved',
            self::Posted => 'Posted',
            self::Rejected => 'Rejected',
        };
    }

    /** Pill tone for the screens' x-status-pill component. */
    public function tone(): string
    {
        return match ($this) {
            self::Draft, self::Submitted => 'amber',
            self::Approved, self::Posted => 'ok',
            self::Rejected => 'red',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
