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

/**
 * docs/specs/02-accounting.md §21.3 - put an APPROVED expense in the ledger.
 *
 * The entry is obtained from PostFromEvent on `expense.recorded` and from
 * nowhere else. This Action never writes a journal line, never calls
 * DraftJournalEntry, never touches `journal_entry_lines`: a second posting
 * path is the one defect this architecture will not tolerate (§11.1). What
 * lands in the books - which journal, which debit and credit shape, whether
 * the payee is lettered as a partner - is decided by the school's posting
 * RULE for this event, which is configuration, not code (00-core §16).
 *
 * The shape the rule is expected to express, per §21.3:
 *
 *   Dr 6xx (or 2xx capex) per line ... total
 *   Cr 57 Caisse / 52x Banque / 552x MoMo ... total
 *
 * If no rule is configured, PostFromEvent raises and NOTHING is written -
 * the expense stays `approved` and the screen surfaces the message. That is
 * the correct failure: silently inventing a rule is how a school ends up
 * with charges in the wrong account for a whole exercice.
 *
 * `posted_at`/`journal_entry_id` are stamped inside the SAME transaction as
 * the posting, so an expense can never claim a `posted` status without an
 * entry - the `ck_expenses_posted_has_entry` CHECK is the second lock on
 * that same door.
 */
final class PostExpense
{
    public function __construct(
        private readonly PostFromEvent $postFromEvent,
        private readonly WriteAuditEntry $audit,
    ) {}

    public function handle(int $expenseId, Actor $actor): Expense
    {
        Gate::authorize(ExpensePermission::POST);

        return DB::transaction(function () use ($expenseId, $actor): Expense {
            /** @var Expense $expense */
            $expense = Expense::query()->lockForUpdate()->findOrFail($expenseId);

            if ($expense->status !== ExpenseStatus::Approved) {
                throw new DomainException(sprintf(
                    'Expense %s is %s; only an approved expense can be posted.',
                    $expense->expense_no,
                    $expense->status->value,
                ));
            }

            if ($expense->journal_entry_id !== null) {
                throw new DomainException(sprintf(
                    'Expense %s is already carried by journal entry #%d.',
                    $expense->expense_no,
                    $expense->journal_entry_id,
                ));
            }

            $entry = $this->postFromEvent->handle(
                event: 'expense.recorded',
                payload: $this->payload($expense),
                date: $expense->expense_date->toDateString(),
                actor: $actor,
                reference: $expense->expense_no,
                // The economic date of a petty-cash payment is the day the
                // money left the tin. When that day sits in a hard-locked
                // period the entry lands in the first OPEN one and keeps
                // this value date (02-accounting C4).
                valueDate: $expense->expense_date->toDateString(),
            );

            $expense->forceFill([
                'status' => ExpenseStatus::Posted->value,
                'journal_entry_id' => (int) $entry->getKey(),
                'posted_by' => $actor->id,
                'posted_at' => now(),
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Accounting',
                auditableType: Expense::class,
                auditableId: (int) $expense->getKey(),
                before: ['status' => ExpenseStatus::Approved->value],
                after: [
                    'status' => ExpenseStatus::Posted->value,
                    'journal_entry_id' => (int) $entry->getKey(),
                ],
                actor: $actor,
            );

            return $expense->refresh();
        });
    }

    /**
     * The `expense.recorded` payload, matching PostingEvent::ExpenseRecorded's
     * declared schema exactly - SavePostingRule validates every rule
     * expression against that schema, so a mismatch here would surface as a
     * rule that cannot be saved rather than as a silent bad entry.
     *
     * The partner tuple is present but usually null: an unregistered market
     * trader has no third-party account, which is the whole reason this
     * document exists instead of a supplier invoice.
     *
     * @return array<string, mixed>
     */
    private function payload(Expense $expense): array
    {
        $lines = [];

        $rows = DB::table('expense_lines')
            ->where('expense_id', $expense->getKey())
            ->orderBy('line_no')
            ->get(['label', 'account_id', 'amount']);

        foreach ($rows as $row) {
            /** @var object{label: string, account_id: int|string, amount: int|string} $row */
            $lines[] = [
                'amount' => (int) $row->amount,
                'expense_account_id' => (int) $row->account_id,
                'label' => $row->label,
            ];
        }

        $partnerType = $expense->payee_type->partnerType();

        return [
            'expense' => [
                'total' => $expense->total_amount,
                'reference' => $expense->expense_no,
                'description' => $expense->description,
                'payee_label' => $expense->payee_name,
                'partner' => ($partnerType === null || $expense->payee_id === null)
                    ? null
                    : ['type' => $partnerType, 'id' => $expense->payee_id],
                'treasury_account_id' => $expense->treasury_account_id,
                'lines' => $lines,
            ],
        ];
    }
}
