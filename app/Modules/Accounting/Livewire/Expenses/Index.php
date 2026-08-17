<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Livewire\Expenses;

use App\Modules\Accounting\Actions\ApproveExpense;
use App\Modules\Accounting\Actions\PostExpense;
use App\Modules\Accounting\Actions\RecordExpense;
use App\Modules\Accounting\Actions\SubmitExpense;
use App\Modules\Accounting\Domain\ExpensePayeeType;
use App\Modules\Accounting\Domain\ExpensePermission;
use App\Modules\Accounting\Domain\ExpenseStatus;
use App\Modules\Accounting\Models\Expense;
use App\Support\Audit\Actor;
use App\Support\Clock\BusinessDate;
use DomainException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * docs/specs/02-accounting.md §21.3 "Expense capture" - the register at
 * /accounting/expenses, gated `ledger.view` to read and `expense.record` to
 * write.
 *
 * Chrome follows the house list-screen pattern exactly (x-list-screen +
 * x-kpi-card + x-status-pill), and the record form is the same inline
 * toggle-panel the Visitors gate desk uses - a petty-cash voucher is keyed
 * at a desk with the register still on screen, not on a separate route.
 *
 * Every write goes through the W4-equivalent Actions (RecordExpense,
 * SubmitExpense, ApproveExpense, PostExpense), which re-check their own
 * permission and enforce maker-checker themselves: this screen hides
 * buttons for readability, never for security (rule 17).
 *
 * Account and payee pick-lists are read with DB::table - the chart is
 * Accounting's own, but suppliers/users belong to other modules and are
 * never reached through their Models.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    /** all | draft | submitted | approved | posted | rejected. */
    #[Url]
    public string $tab = 'all';

    #[Url]
    public string $search = '';

    #[Url]
    public string $treasury = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    // ── Record form ─────────────────────────────────────────────────────
    public bool $showForm = false;

    public string $formDate = '';

    public string $formPayeeType = 'other';

    public string $formPayeeName = '';

    public ?int $formPayeeId = null;

    public string $formDescription = '';

    public ?int $formTreasuryAccountId = null;

    public string $formAttachmentRef = '';

    public string $formNotes = '';

    /**
     * The dynamic line grid. Amount stays a string in the form so a blank
     * box is blank rather than a spurious zero; RecordExpense receives ints.
     *
     * @var list<array{account_id: string, label: string, amount: string, analytic_value_id: string, tax_code_id: string}>
     */
    public array $formLines = [];

    // ── Reject dialog ───────────────────────────────────────────────────
    public ?int $rejectingId = null;

    public string $rejectReason = '';

    public function mount(): void
    {
        Gate::authorize(ExpensePermission::VIEW);

        $this->formDate = BusinessDate::today();
        $this->formLines = [$this->blankLine()];
    }

    /**
     * @return array{account_id: string, label: string, amount: string, analytic_value_id: string, tax_code_id: string}
     */
    private function blankLine(): array
    {
        return [
            'account_id' => '',
            'label' => '',
            'amount' => '',
            'analytic_value_id' => '',
            'tax_code_id' => '',
        ];
    }

    public function selectTab(string $tab): void
    {
        $allowed = ['all', 'draft', 'submitted', 'approved', 'posted', 'rejected'];
        $this->tab = in_array($tab, $allowed, true) ? $tab : 'all';
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'treasury', 'from', 'to']);
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTreasury(): void
    {
        $this->resetPage();
    }

    public function updatedFrom(): void
    {
        $this->resetPage();
    }

    public function updatedTo(): void
    {
        $this->resetPage();
    }

    public function toggleForm(): void
    {
        Gate::authorize(ExpensePermission::RECORD);

        $this->showForm = ! $this->showForm;

        if ($this->showForm && $this->formLines === []) {
            $this->formLines = [$this->blankLine()];
        }
    }

    public function addLine(): void
    {
        $this->formLines[] = $this->blankLine();
    }

    public function removeLine(int $index): void
    {
        if (count($this->formLines) <= 1) {
            return;
        }

        /** @var list<array{account_id: string, label: string, amount: string, analytic_value_id: string, tax_code_id: string}> $kept */
        $kept = [];

        foreach ($this->formLines as $position => $line) {
            if ($position !== $index) {
                $kept[] = $line;
            }
        }

        $this->formLines = $kept;
    }

    /** The running total the operator checks against the receipt. */
    public function formTotal(): int
    {
        $total = 0;

        foreach ($this->formLines as $line) {
            $amount = trim($line['amount']);

            if ($amount !== '' && is_numeric($amount)) {
                $total += (int) $amount;
            }
        }

        return $total;
    }

    public function saveExpense(RecordExpense $record): void
    {
        Gate::authorize(ExpensePermission::RECORD);

        /** @var list<array{account_id: int, amount: int, label?: string, analytic_value_id?: int|null, tax_code_id?: int|null}> $lines */
        $lines = [];

        foreach ($this->formLines as $index => $line) {
            $accountId = trim($line['account_id']);
            $amount = trim($line['amount']);

            if ($accountId === '' && $amount === '') {
                continue;
            }

            if ($accountId === '' || ! is_numeric($amount)) {
                $this->addError('formLines.'.$index.'.amount', 'Each line needs an account and an amount.');

                return;
            }

            $lines[] = [
                'account_id' => (int) $accountId,
                'amount' => (int) $amount,
                'label' => trim($line['label']),
                'analytic_value_id' => trim($line['analytic_value_id']) === '' ? null : (int) $line['analytic_value_id'],
                'tax_code_id' => trim($line['tax_code_id']) === '' ? null : (int) $line['tax_code_id'],
            ];
        }

        if ($lines === []) {
            $this->addError('formLines.0.amount', 'Record at least one charge line.');

            return;
        }

        if ($this->formTreasuryAccountId === null) {
            $this->addError('formTreasuryAccountId', 'Say which float paid this.');

            return;
        }

        try {
            $record->handle([
                'payee_type' => $this->formPayeeType,
                'payee_id' => $this->formPayeeId,
                'payee_name' => $this->formPayeeName,
                'description' => $this->formDescription,
                'treasury_account_id' => $this->formTreasuryAccountId,
                'expense_date' => $this->formDate === '' ? BusinessDate::today() : $this->formDate,
                'attachment_ref' => trim($this->formAttachmentRef) === '' ? null : trim($this->formAttachmentRef),
                'notes' => trim($this->formNotes) === '' ? null : trim($this->formNotes),
                'lines' => $lines,
            ], $this->actor());
        } catch (ValidationException $e) {
            $this->addError('formDescription', $e->getMessage());

            return;
        } catch (DomainException $e) {
            $this->addError('formDescription', $e->getMessage());

            return;
        }

        $this->reset([
            'showForm', 'formPayeeType', 'formPayeeName', 'formPayeeId',
            'formDescription', 'formTreasuryAccountId', 'formAttachmentRef', 'formNotes',
        ]);
        $this->formLines = [$this->blankLine()];
        $this->formDate = BusinessDate::today();
        $this->tab = 'draft';
        $this->resetPage();
        session()->flash('status', 'Expense recorded as a draft. Attach the receipt, then submit it.');
    }

    public function submit(int $expenseId, SubmitExpense $submit): void
    {
        try {
            $expense = $submit->handle($expenseId, $this->actor());
        } catch (ValidationException|DomainException $e) {
            $this->addError('rowAction', $e->getMessage());

            return;
        }

        session()->flash('status', $expense->status === ExpenseStatus::Approved
            ? 'Expense submitted; it is below the approval threshold and is ready to post.'
            : 'Expense submitted for approval.');
    }

    public function approve(int $expenseId, ApproveExpense $approve): void
    {
        try {
            $approve->handle($expenseId, $this->actor());
        } catch (ValidationException|DomainException $e) {
            $this->addError('rowAction', $e->getMessage());

            return;
        }

        session()->flash('status', 'Expense approved; it can now be posted.');
    }

    public function startReject(int $expenseId): void
    {
        $this->rejectingId = $expenseId;
        $this->rejectReason = '';
    }

    public function cancelReject(): void
    {
        $this->rejectingId = null;
        $this->rejectReason = '';
    }

    public function confirmReject(ApproveExpense $approve): void
    {
        if ($this->rejectingId === null) {
            return;
        }

        try {
            $approve->reject($this->rejectingId, $this->rejectReason, $this->actor());
        } catch (ValidationException|DomainException $e) {
            $this->addError('rejectReason', $e->getMessage());

            return;
        }

        $this->cancelReject();
        session()->flash('status', 'Expense rejected; the reason is on the voucher.');
    }

    public function post(int $expenseId, PostExpense $post): void
    {
        try {
            $post->handle($expenseId, $this->actor());
        } catch (ValidationException|DomainException $e) {
            $this->addError('rowAction', $e->getMessage());

            return;
        }

        session()->flash('status', 'Expense posted to the ledger.');
    }

    private function actor(): Actor
    {
        /** @var \App\Modules\Identity\Models\User $user */
        $user = auth()->user();

        return $user->toAuditActor();
    }

    private function resetPage(): void
    {
        $this->page = 1;
    }

    /**
     * @return LengthAwarePaginator<int, Expense>
     */
    private function rows(): LengthAwarePaginator
    {
        return Expense::query()
            ->when($this->tab !== 'all', fn ($q) => $q->where('status', $this->tab))
            ->when($this->treasury !== '', fn ($q) => $q->where('treasury_account_id', (int) $this->treasury))
            ->when($this->from !== '', fn ($q) => $q->whereDate('expense_date', '>=', $this->from))
            ->when($this->to !== '', fn ($q) => $q->whereDate('expense_date', '<=', $this->to))
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('expense_no', 'like', '%'.$this->search.'%')
                        ->orWhere('payee_name', 'like', '%'.$this->search.'%')
                        ->orWhere('description', 'like', '%'.$this->search.'%');
                });
            })
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * @return array{month_total: int, awaiting_approval: int, awaiting_posting: int, posted_this_month: int}
     */
    private function kpis(): array
    {
        $monthStart = \Illuminate\Support\Carbon::parse(BusinessDate::today())->startOfMonth()->toDateString();

        return [
            'month_total' => (int) DB::table('expenses')
                ->where('status', ExpenseStatus::Posted->value)
                ->whereDate('expense_date', '>=', $monthStart)
                ->sum('total_amount'),
            'awaiting_approval' => (int) DB::table('expenses')
                ->where('status', ExpenseStatus::Submitted->value)->count(),
            'awaiting_posting' => (int) DB::table('expenses')
                ->where('status', ExpenseStatus::Approved->value)->count(),
            'posted_this_month' => (int) DB::table('expenses')
                ->where('status', ExpenseStatus::Posted->value)
                ->whereDate('expense_date', '>=', $monthStart)
                ->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function tabCounts(): array
    {
        $counts = [
            'all' => 0, 'draft' => 0, 'submitted' => 0,
            'approved' => 0, 'posted' => 0, 'rejected' => 0,
        ];

        $rows = DB::table('expenses')->selectRaw('status, COUNT(*) AS total')->groupBy('status')->get();

        foreach ($rows as $row) {
            /** @var object{status: string, total: int|string} $row */
            $counts[$row->status] = (int) $row->total;
            $counts['all'] += (int) $row->total;
        }

        return $counts;
    }

    /**
     * Class-5 postable accounts: the floats a school actually pays out of.
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    private function treasuryAccounts(): array
    {
        $rows = DB::table('chart_of_accounts')
            ->where('account_class', 5)
            ->where('is_postable', true)
            ->where('is_archived', false)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $accounts = [];

        foreach ($rows as $row) {
            /** @var object{id: int|string, code: string, name: string} $row */
            $accounts[] = ['id' => (int) $row->id, 'code' => $row->code, 'name' => $row->name];
        }

        return $accounts;
    }

    /**
     * Class 6 (operating charge) and class 2 (capex) postable accounts.
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    private function chargeAccounts(): array
    {
        $rows = DB::table('chart_of_accounts')
            ->whereIn('account_class', [2, 6])
            ->where('is_postable', true)
            ->where('is_archived', false)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $accounts = [];

        foreach ($rows as $row) {
            /** @var object{id: int|string, code: string, name: string} $row */
            $accounts[] = ['id' => (int) $row->id, 'code' => $row->code, 'name' => $row->name];
        }

        return $accounts;
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    private function analyticValues(): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('analytic_values')) {
            return [];
        }

        $rows = DB::table('analytic_values')
            ->orderBy('code')
            ->limit(300)
            ->get(['id', 'code', 'name']);

        $values = [];

        foreach ($rows as $row) {
            /** @var object{id: int|string, code: string, name: string} $row */
            $values[] = ['id' => (int) $row->id, 'label' => $row->code.' — '.$row->name];
        }

        return $values;
    }

    /**
     * Active tax codes, the per-line VAT treatment RecordExpense already
     * accepts. Read with DB::table: `tax_codes` belongs to Tax, not
     * Accounting (ModuleBoundaryTest).
     *
     * @return list<array{id: int, label: string}>
     */
    private function taxCodes(): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('tax_codes')) {
            return [];
        }

        $rows = DB::table('tax_codes')
            ->where('is_active', true)
            ->orderBy('code')
            ->limit(300)
            ->get(['id', 'code', 'name']);

        $codes = [];

        foreach ($rows as $row) {
            /** @var object{id: int|string, code: string, name: string} $row */
            $codes[] = ['id' => (int) $row->id, 'label' => $row->code.' — '.$row->name];
        }

        return $codes;
    }

    /**
     * Treasury spend per float over the last 90 days - the rail's answer to
     * "which tin is this coming out of".
     *
     * @return list<array{label: string, total: int}>
     */
    private function spendByFloat(): array
    {
        $since = \Illuminate\Support\Carbon::parse(BusinessDate::today())->subDays(90)->toDateString();

        $rows = DB::table('expenses as e')
            ->join('chart_of_accounts as a', 'a.id', '=', 'e.treasury_account_id')
            ->where('e.status', ExpenseStatus::Posted->value)
            ->whereDate('e.expense_date', '>=', $since)
            ->groupBy('a.code', 'a.name')
            ->orderByDesc(DB::raw('SUM(e.total_amount)'))
            ->limit(6)
            ->get([
                'a.code',
                'a.name',
                DB::raw('SUM(e.total_amount) AS total'),
            ]);

        $out = [];

        foreach ($rows as $row) {
            /** @var object{code: string, name: string, total: int|string} $row */
            $out[] = ['label' => $row->code.' '.$row->name, 'total' => (int) $row->total];
        }

        return $out;
    }

    /**
     * Top charge accounts over the last 90 days.
     *
     * @return list<array{label: string, total: int}>
     */
    private function spendByAccount(): array
    {
        $since = \Illuminate\Support\Carbon::parse(BusinessDate::today())->subDays(90)->toDateString();

        $rows = DB::table('expense_lines as l')
            ->join('expenses as e', 'e.id', '=', 'l.expense_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('e.status', ExpenseStatus::Posted->value)
            ->whereDate('e.expense_date', '>=', $since)
            ->groupBy('a.code', 'a.name')
            ->orderByDesc(DB::raw('SUM(l.amount)'))
            ->limit(6)
            ->get([
                'a.code',
                'a.name',
                DB::raw('SUM(l.amount) AS total'),
            ]);

        $out = [];

        foreach ($rows as $row) {
            /** @var object{code: string, name: string, total: int|string} $row */
            $out[] = ['label' => $row->code.' '.$row->name, 'total' => (int) $row->total];
        }

        return $out;
    }

    public function render(): mixed
    {
        $rows = $this->rows();

        return view('livewire.accounting.expenses.index', [
            'rows' => $rows,
            'kpis' => $this->kpis(),
            'tabCounts' => $this->tabCounts(),
            'treasuryAccounts' => $this->treasuryAccounts(),
            'chargeAccounts' => $this->chargeAccounts(),
            'analyticValues' => $this->analyticValues(),
            'taxCodes' => $this->taxCodes(),
            'spendByFloat' => $this->spendByFloat(),
            'spendByAccount' => $this->spendByAccount(),
            'payeeTypes' => ExpensePayeeType::cases(),
            'formTotal' => $this->formTotal(),
            'canRecord' => Gate::allows(ExpensePermission::RECORD),
            'canApprove' => Gate::allows(ExpensePermission::APPROVE),
            'canPost' => Gate::allows(ExpensePermission::POST),
        ]);
    }
}
