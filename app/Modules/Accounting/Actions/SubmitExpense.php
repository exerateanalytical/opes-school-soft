<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Domain\ExpensePermission;
use App\Modules\Accounting\Domain\ExpenseStatus;
use App\Modules\Accounting\Models\Expense;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\SchoolProfile\Actions\ReadSetting;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/02-accounting.md §21.3 - submit a drafted expense for
 * sign-off.
 *
 * This is where the CONFIGURABLE THRESHOLD is read and, crucially, FROZEN
 * onto the row (`requires_approval`, `approval_threshold_applied`). Reading
 * it again at approval time would let a change of setting retroactively
 * decide whether a voucher ever needed a second signature, which is exactly
 * the question an auditor asks. Below the threshold the expense lands
 * straight in `approved` - a 2 000 FCFA taxi receipt does not need the
 * bursar - and `approved_by` stays NULL to say so honestly rather than
 * naming the submitter as their own approver.
 *
 * The attachment becomes MANDATORY here, not at draft: AUDCIF Art. 17 and
 * L15 require the pièce, but the desk keys the voucher before the phone
 * photo arrives, so the gate is at submission.
 */
final class SubmitExpense
{
    /** Setting key holding the maker-checker threshold in whole FCFA. */
    public const THRESHOLD_SETTING = 'accounting.expense_approval_threshold';

    /**
     * The fallback when the school has never configured one. Deliberately
     * ZERO - i.e. everything needs a checker - because the safe default for
     * an unset control is the strict one.
     */
    public const THRESHOLD_DEFAULT = 0;

    public function __construct(
        private readonly ReadSetting $settings,
        private readonly WriteAuditEntry $audit,
    ) {}

    public function handle(int $expenseId, Actor $actor): Expense
    {
        Gate::authorize(ExpensePermission::RECORD);

        return DB::transaction(function () use ($expenseId, $actor): Expense {
            /** @var Expense $expense */
            $expense = Expense::query()->lockForUpdate()->findOrFail($expenseId);

            if ($expense->status !== ExpenseStatus::Draft) {
                throw new DomainException(sprintf(
                    'Expense %s is %s; only a draft can be submitted.',
                    $expense->expense_no,
                    $expense->status->value,
                ));
            }

            if (trim((string) $expense->attachment_ref) === '') {
                throw ValidationException::withMessages([
                    'attachment_ref' => 'Attach the receipt before submitting; the pièce justificative is not optional (AUDCIF Art. 17, L15).',
                ]);
            }

            $lineCount = (int) DB::table('expense_lines')->where('expense_id', $expense->getKey())->count();

            if ($lineCount === 0) {
                throw new DomainException('An expense with no charge lines cannot be submitted.');
            }

            $threshold = $this->threshold();
            $requiresApproval = $expense->total_amount > $threshold;

            $expense->forceFill([
                'status' => $requiresApproval ? ExpenseStatus::Submitted->value : ExpenseStatus::Approved->value,
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
                'requires_approval' => $requiresApproval,
                'approval_threshold_applied' => $threshold,
                // Below the threshold there IS no approver. Naming the
                // submitter here would forge a signature.
                'approved_by' => null,
                'approved_at' => $requiresApproval ? null : now(),
                'rejection_reason' => null,
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Accounting',
                auditableType: Expense::class,
                auditableId: (int) $expense->getKey(),
                before: ['status' => ExpenseStatus::Draft->value],
                after: [
                    'status' => $expense->status->value,
                    'requires_approval' => $requiresApproval,
                    'approval_threshold_applied' => $threshold,
                ],
                actor: $actor,
            );

            return $expense->refresh();
        });
    }

    /** The maker-checker threshold in whole FCFA, never negative. */
    public function threshold(): int
    {
        $configured = $this->settings->handle(self::THRESHOLD_SETTING, self::THRESHOLD_DEFAULT);

        $value = is_numeric($configured) ? (int) $configured : self::THRESHOLD_DEFAULT;

        return max(0, $value);
    }
}
