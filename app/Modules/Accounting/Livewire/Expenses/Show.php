<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Livewire\Expenses;

use App\Modules\Accounting\Domain\ExpensePermission;
use App\Modules\Reporting\Support\PdfExport;
use App\Support\Money\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;

/**
 * docs/specs/02-accounting.md §21.3 - one expense voucher in full: header,
 * charge lines with their analytic and tax attribution, the ledger entry it
 * produced, and a print-preview of the voucher itself (the document the
 * payee signs and the auditor asks for).
 *
 * READ-ONLY, exactly like SupplierInvoices\Show: submit / approve / post all
 * stay on the register (Expenses\Index) where the maker-checker queue lives.
 * Gated on the SAME permission as that register, `ledger.view`.
 */
#[Layout('layouts.app')]
final class Show extends Component
{
    public int $expenseId;

    public function mount(int $expense): void
    {
        Gate::authorize(ExpensePermission::VIEW);

        $this->expenseId = $expense;

        if (! DB::table('expenses')->where('id', $expense)->exists()) {
            abort(404);
        }
    }

    public function exportPdf(): Response
    {
        Gate::authorize(ExpensePermission::VIEW);

        $expense = $this->expense();

        $rows = [];

        foreach ($this->lines() as $line) {
            $analytic = self::asString($line->analytic_label);

            $rows[] = [
                self::asString($line->line_no),
                self::asString($line->label),
                self::asString($line->account_code).' '.self::asString($line->account_name),
                $analytic === '' ? '—' : $analytic,
                Money::of(self::asInt($line->amount))->format(false),
            ];
        }

        return PdfExport::download(
            title: 'Expense Voucher '.$expense->expense_no,
            headers: ['#', 'Label', 'Account', 'Analytic', 'Amount'],
            rows: $rows,
            filename: 'expense-'.str_replace('/', '-', $expense->expense_no).'.pdf',
        );
    }

    /**
     * @return object{id:int, expense_no:string, expense_date:string, payee_type:string, payee_id:?int, payee_name:string, description:string, treasury_account_id:int, treasury_code:string, treasury_name:string, total_amount:int, currency:string, status:string, attachment_ref:?string, created_by:int, submitted_by:?int, submitted_at:?string, approved_by:?int, approved_at:?string, posted_by:?int, posted_at:?string, requires_approval:int, approval_threshold_applied:int, journal_entry_id:?int, piece_no:?string, entry_label:?string, rejection_reason:?string, notes:?string}
     */
    private function expense(): object
    {
        $row = DB::table('expenses as e')
            ->join('chart_of_accounts as t', 't.id', '=', 'e.treasury_account_id')
            ->leftJoin('journal_entries as je', 'je.id', '=', 'e.journal_entry_id')
            ->where('e.id', $this->expenseId)
            ->firstOrFail([
                'e.id', 'e.expense_no', 'e.expense_date',
                'e.payee_type', 'e.payee_id', 'e.payee_name', 'e.description',
                'e.treasury_account_id', 't.code as treasury_code', 't.name as treasury_name',
                'e.total_amount', 'e.currency', 'e.status', 'e.attachment_ref',
                'e.created_by', 'e.submitted_by', 'e.submitted_at',
                'e.approved_by', 'e.approved_at', 'e.posted_by', 'e.posted_at',
                'e.requires_approval', 'e.approval_threshold_applied',
                'e.journal_entry_id', 'je.piece_no', 'je.label as entry_label',
                'e.rejection_reason', 'e.notes',
            ]);

        // The query builder hands back an untyped stdClass; the shape is
        // re-established HERE, once, so the callers and the view work
        // against the declared shape rather than against `mixed`.
        return (object) [
            'id' => self::asInt($row->id),
            'expense_no' => self::asString($row->expense_no),
            'expense_date' => self::asString($row->expense_date),
            'payee_type' => self::asString($row->payee_type),
            'payee_id' => self::asNullableInt($row->payee_id),
            'payee_name' => self::asString($row->payee_name),
            'description' => self::asString($row->description),
            'treasury_account_id' => self::asInt($row->treasury_account_id),
            'treasury_code' => self::asString($row->treasury_code),
            'treasury_name' => self::asString($row->treasury_name),
            'total_amount' => self::asInt($row->total_amount),
            'currency' => self::asString($row->currency),
            'status' => self::asString($row->status),
            'attachment_ref' => self::asNullableString($row->attachment_ref),
            'created_by' => self::asInt($row->created_by),
            'submitted_by' => self::asNullableInt($row->submitted_by),
            'submitted_at' => self::asNullableString($row->submitted_at),
            'approved_by' => self::asNullableInt($row->approved_by),
            'approved_at' => self::asNullableString($row->approved_at),
            'posted_by' => self::asNullableInt($row->posted_by),
            'posted_at' => self::asNullableString($row->posted_at),
            'requires_approval' => self::asInt($row->requires_approval),
            'approval_threshold_applied' => self::asInt($row->approval_threshold_applied),
            'journal_entry_id' => self::asNullableInt($row->journal_entry_id),
            'piece_no' => self::asNullableString($row->piece_no),
            'entry_label' => self::asNullableString($row->entry_label),
            'rejection_reason' => self::asNullableString($row->rejection_reason),
            'notes' => self::asNullableString($row->notes),
        ];
    }

    private static function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function asNullableInt(mixed $value): ?int
    {
        return $value === null ? null : self::asInt($value);
    }

    private static function asString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function asNullableString(mixed $value): ?string
    {
        return $value === null ? null : self::asString($value);
    }

    /**
     * @return Collection<int, \stdClass>
     */
    private function lines(): Collection
    {
        return DB::table('expense_lines as l')
            ->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
            ->leftJoin('analytic_values as av', 'av.id', '=', 'l.analytic_value_id')
            ->leftJoin('tax_codes as tc', 'tc.id', '=', 'l.tax_code_id')
            ->where('l.expense_id', $this->expenseId)
            ->orderBy('l.line_no')
            ->get([
                'l.line_no', 'l.label', 'l.amount',
                'a.code as account_code', 'a.name as account_name', 'a.account_class',
                DB::raw("CONCAT_WS(' — ', av.code, av.name) as analytic_label"),
                'tc.code as tax_code',
            ]);
    }

    private function userName(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        $name = DB::table('users')->where('id', $userId)->value('name');

        return $name === null ? null : (string) $name;
    }

    /**
     * The ledger lines this voucher produced - the proof, not a restatement.
     *
     * @return Collection<int, \stdClass>
     */
    private function entryLines(int $entryId): Collection
    {
        return DB::table('journal_entry_lines as jl')
            ->join('chart_of_accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('jl.journal_entry_id', $entryId)
            ->orderBy('jl.sequence')
            ->get(['jl.sequence', 'a.code as account_code', 'a.name as account_name', 'jl.label', 'jl.debit', 'jl.credit']);
    }

    public function render(): mixed
    {
        $expense = $this->expense();

        /** @var Collection<int, \stdClass> $entryLines */
        $entryLines = $expense->journal_entry_id === null
            ? new Collection
            : $this->entryLines((int) $expense->journal_entry_id);

        return view('livewire.accounting.expenses.show', [
            'expense' => $expense,
            'lines' => $this->lines(),
            'entryLines' => $entryLines,
            'createdByName' => $this->userName($expense->created_by),
            'submittedByName' => $this->userName($expense->submitted_by),
            'approvedByName' => $this->userName($expense->approved_by),
            'postedByName' => $this->userName($expense->posted_by),
        ]);
    }
}
