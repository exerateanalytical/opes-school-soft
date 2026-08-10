<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Domain\ExpensePayeeType;
use App\Modules\Accounting\Domain\ExpensePermission;
use App\Modules\Accounting\Domain\ExpenseStatus;
use App\Modules\Accounting\Models\Expense;
use App\Modules\Accounting\Models\ExpenseLine;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use App\Support\Clock\BusinessDate;
use App\Support\Sequence\SequenceAllocator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/02-accounting.md §21.3 - record an expense DRAFT: who was paid,
 * out of which float, against which charge accounts.
 *
 * Nothing touches the ledger here. A draft is a piece of paper; it becomes
 * an accounting fact only when PostExpense hands `expense.recorded` to
 * PostFromEvent. That separation is what makes the maker-checker of §21.3
 * meaningful - if recording posted, there would be nothing left to check.
 *
 * `expense_no` comes from the row-locked `DEP.<year>` series via
 * SequenceAllocator INSIDE this transaction (00-core §12). Gaps are
 * permitted for this series - a rolled-back draft burning a number is
 * harmless, and `max()+1` is never acceptable.
 *
 * Account validation is done here rather than by a CHECK because
 * `chart_of_accounts.account_class` is a GENERATED column and MySQL cannot
 * reference another table's column in a CHECK constraint:
 *
 *  - the treasury account must be a postable class-5 account (57 Caisse /
 *    52x Banque / 552x Mobile Money) - the convention
 *    `supplier_payments.treasury_account_id` already established;
 *  - every line account must be postable and in class 6 (operating charge)
 *    or class 2 (capex, §21.3's "the line targets class 2").
 */
final class RecordExpense
{
    public function __construct(
        private readonly SequenceAllocator $sequences,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param array{
     *     payee_type: string,
     *     payee_id?: int|null,
     *     payee_name?: string,
     *     description: string,
     *     treasury_account_id: int,
     *     expense_date?: string,
     *     attachment_ref?: string|null,
     *     notes?: string|null,
     *     idempotency_key?: string|null,
     *     lines: list<array{account_id: int, amount: int, label?: string, analytic_value_id?: int|null, tax_code_id?: int|null}>,
     * } $input
     */
    public function handle(array $input, Actor $actor): Expense
    {
        Gate::authorize(ExpensePermission::RECORD);

        $payeeType = ExpensePayeeType::from($input['payee_type']);
        $expenseDate = $input['expense_date'] ?? BusinessDate::today();
        $description = trim($input['description']);
        $payeeName = trim($input['payee_name'] ?? '');

        if ($description === '') {
            throw ValidationException::withMessages([
                'description' => 'An expense needs a description; "divers" is not a description (02-accounting 21.3).',
            ]);
        }

        if ($input['lines'] === []) {
            throw ValidationException::withMessages([
                'lines' => 'An expense must carry at least one charge line.',
            ]);
        }

        foreach ($input['lines'] as $index => $line) {
            if ($line['amount'] <= 0) {
                throw ValidationException::withMessages([
                    'lines.'.$index.'.amount' => 'Every expense line is strictly positive; a refund is not an expense.',
                ]);
            }
        }

        return DB::transaction(function () use ($input, $actor, $payeeType, $expenseDate, $description, $payeeName): Expense {
            $idempotencyKey = $input['idempotency_key'] ?? null;

            if ($idempotencyKey !== null) {
                /** @var Expense|null $existing */
                $existing = Expense::query()->where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            $this->assertTreasuryAccount($input['treasury_account_id']);

            $resolvedPayeeName = $this->resolvePayeeName($payeeType, $input['payee_id'] ?? null, $payeeName);

            $total = 0;

            foreach ($input['lines'] as $line) {
                $this->assertChargeAccount($line['account_id']);
                $total += $line['amount'];
            }

            $year = Carbon::parse($expenseDate)->year;
            $number = $this->sequences->allocate('DEP.'.$year);

            $expense = new Expense([
                'expense_no' => sprintf('DEP/%d/%06d', $year, $number),
                'expense_date' => $expenseDate,
                'payee_type' => $payeeType->value,
                'payee_id' => $payeeType === ExpensePayeeType::Other ? null : ($input['payee_id'] ?? null),
                'payee_name' => $resolvedPayeeName,
                'description' => $description,
                'treasury_account_id' => $input['treasury_account_id'],
                'total_amount' => $total,
                'currency' => 'XAF',
                'status' => ExpenseStatus::Draft->value,
                'attachment_ref' => $input['attachment_ref'] ?? null,
                'created_by' => $actor->id,
                'notes' => $input['notes'] ?? null,
                'idempotency_key' => $idempotencyKey,
            ]);

            $expense->save();

            $lineNo = 0;

            foreach ($input['lines'] as $line) {
                $lineNo++;

                (new ExpenseLine([
                    'expense_id' => (int) $expense->getKey(),
                    'line_no' => $lineNo,
                    'label' => trim($line['label'] ?? '') === '' ? $description : trim((string) $line['label']),
                    'account_id' => $line['account_id'],
                    'analytic_value_id' => $line['analytic_value_id'] ?? null,
                    'tax_code_id' => $line['tax_code_id'] ?? null,
                    'amount' => $line['amount'],
                ]))->save();
            }

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Accounting',
                auditableType: Expense::class,
                auditableId: (int) $expense->getKey(),
                after: [
                    'expense_no' => $expense->expense_no,
                    'total_amount' => $total,
                    'payee_name' => $resolvedPayeeName,
                    'treasury_account_id' => $input['treasury_account_id'],
                ],
                actor: $actor,
            );

            return $expense->refresh();
        });
    }

    /**
     * The float that paid: postable, not archived, class 5.
     */
    private function assertTreasuryAccount(int $accountId): void
    {
        /** @var object{account_class: int|string|null, is_postable: int, is_archived: int}|null $account */
        $account = DB::table('chart_of_accounts')
            ->where('id', $accountId)
            ->first(['account_class', 'is_postable', 'is_archived']);

        if ($account === null) {
            throw ValidationException::withMessages([
                'treasury_account_id' => 'That treasury account does not exist.',
            ]);
        }

        if ((int) $account->is_postable !== 1 || (int) $account->is_archived === 1) {
            throw ValidationException::withMessages([
                'treasury_account_id' => 'That treasury account is archived or is a heading, not a postable account.',
            ]);
        }

        if ((int) $account->account_class !== 5) {
            throw ValidationException::withMessages([
                'treasury_account_id' => 'An expense is paid out of a class-5 treasury account (57 Caisse, 52x Banque, 552x Mobile Money).',
            ]);
        }
    }

    /**
     * A charge line: postable, not archived, class 6 (operating) or class 2
     * (capex - §21.3's capitalised purchase).
     */
    private function assertChargeAccount(int $accountId): void
    {
        /** @var object{code: string, account_class: int|string|null, is_postable: int, is_archived: int}|null $account */
        $account = DB::table('chart_of_accounts')
            ->where('id', $accountId)
            ->first(['code', 'account_class', 'is_postable', 'is_archived']);

        if ($account === null) {
            throw ValidationException::withMessages([
                'lines' => 'One of the charge accounts does not exist.',
            ]);
        }

        if ((int) $account->is_postable !== 1 || (int) $account->is_archived === 1) {
            throw ValidationException::withMessages([
                'lines' => sprintf('Account %s is archived or is a heading, not a postable account.', $account->code),
            ]);
        }

        if (! in_array((int) $account->account_class, [2, 6], true)) {
            throw ValidationException::withMessages([
                'lines' => sprintf(
                    'Account %s is neither a class-6 charge nor a class-2 capex account; an expense line must be one of the two (02-accounting 21.3).',
                    $account->code,
                ),
            ]);
        }
    }

    /**
     * The payee label stored on the document. A supplier or staff payee is
     * looked up via DB::table - Accounting never imports another module's
     * Model (00-core §6.2) - and the name is DENORMALISED so the printed
     * voucher survives a later rename.
     */
    private function resolvePayeeName(ExpensePayeeType $type, ?int $payeeId, string $given): string
    {
        $table = $type->referenceTable();

        if ($table === null) {
            if ($given === '') {
                throw ValidationException::withMessages([
                    'payee_name' => 'Name the payee; "cash" is not a payee (AUDCIF Art. 17).',
                ]);
            }

            return $given;
        }

        if ($payeeId === null) {
            throw ValidationException::withMessages([
                'payee_id' => sprintf('Choose the %s who was paid.', $type->label()),
            ]);
        }

        $name = DB::table($table)->where('id', $payeeId)->value('name');

        if ($name === null) {
            throw ValidationException::withMessages([
                'payee_id' => sprintf('No %s carries that identifier.', $type->label()),
            ]);
        }

        return (string) $name;
    }
}
