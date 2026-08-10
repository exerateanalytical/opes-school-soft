<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Domain\ExpensePermission;
use App\Modules\Accounting\Domain\ExpenseStatus;
use App\Modules\Accounting\Models\Expense;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/02-accounting.md §21.3 - the CHECKER half of maker-checker.
 *
 * Two independent controls, both enforced here rather than in a menu:
 *
 *  1. `expense.approve` is a DIFFERENT permission from `expense.record`, so
 *     the right to sign is granted separately from the right to spend;
 *  2. the submitter may never be the approver. This is checked at runtime,
 *     against the identity frozen on the row, so holding BOTH permissions
 *     does not let one person walk a voucher through on their own. A
 *     permission check alone would not catch that - the Administrator role
 *     holds everything.
 *
 * The threshold itself was resolved and frozen at submission (SubmitExpense);
 * anything that arrives here in `submitted` is by construction above it, so
 * this Action never re-reads the setting. Rejection is recorded with a
 * reason and returns the voucher to `rejected` - deleting a submitted
 * expense is refused by the table's own trigger.
 */
final class ApproveExpense
{
    public function __construct(
        private readonly WriteAuditEntry $audit,
    ) {}

    public function handle(int $expenseId, Actor $actor): Expense
    {
        Gate::authorize(ExpensePermission::APPROVE);

        return DB::transaction(function () use ($expenseId, $actor): Expense {
            /** @var Expense $expense */
            $expense = Expense::query()->lockForUpdate()->findOrFail($expenseId);

            if ($expense->status !== ExpenseStatus::Submitted) {
                throw new DomainException(sprintf(
                    'Expense %s is %s; only a submitted expense awaits approval.',
                    $expense->expense_no,
                    $expense->status->value,
                ));
            }

            $this->assertNotSelfApproval($expense, $actor);

            $expense->forceFill([
                'status' => ExpenseStatus::Approved->value,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'rejection_reason' => null,
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Accounting',
                auditableType: Expense::class,
                auditableId: (int) $expense->getKey(),
                before: ['status' => ExpenseStatus::Submitted->value],
                after: ['status' => ExpenseStatus::Approved->value, 'approved_by' => $actor->id],
                actor: $actor,
            );

            return $expense->refresh();
        });
    }

    public function reject(int $expenseId, string $reason, Actor $actor): Expense
    {
        Gate::authorize(ExpensePermission::APPROVE);

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'rejection_reason' => 'Say why the voucher is refused; an unexplained refusal cannot be answered.',
            ]);
        }

        return DB::transaction(function () use ($expenseId, $reason, $actor): Expense {
            /** @var Expense $expense */
            $expense = Expense::query()->lockForUpdate()->findOrFail($expenseId);

            if ($expense->status !== ExpenseStatus::Submitted) {
                throw new DomainException(sprintf(
                    'Expense %s is %s; only a submitted expense can be rejected.',
                    $expense->expense_no,
                    $expense->status->value,
                ));
            }

            $this->assertNotSelfApproval($expense, $actor);

            $expense->forceFill([
                'status' => ExpenseStatus::Rejected->value,
                'rejection_reason' => $reason,
                'approved_by' => null,
                'approved_at' => null,
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Accounting',
                auditableType: Expense::class,
                auditableId: (int) $expense->getKey(),
                before: ['status' => ExpenseStatus::Submitted->value],
                after: ['status' => ExpenseStatus::Rejected->value, 'rejection_reason' => $reason],
                actor: $actor,
            );

            return $expense->refresh();
        });
    }

    /**
     * Maker ≠ checker. Compared against BOTH `submitted_by` and
     * `created_by`: submitting under someone else's draft would otherwise
     * let the author sign their own spending by proxy.
     */
    private function assertNotSelfApproval(Expense $expense, Actor $actor): void
    {
        if ($actor->id === null) {
            throw new DomainException('An unattributed actor cannot approve an expense; maker-checker needs two named people.');
        }

        if ($expense->submitted_by === $actor->id || $expense->created_by === $actor->id) {
            throw new DomainException(sprintf(
                'Expense %s was recorded or submitted by you; above the %s FCFA threshold it needs a second pair of eyes (02-accounting 21.3).',
                $expense->expense_no,
                number_format($expense->approval_threshold_applied, 0, '.', ' '),
            ));
        }
    }
}
