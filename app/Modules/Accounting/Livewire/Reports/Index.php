<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Livewire\Reports;

use App\Modules\Accounting\Actions\GeneralLedgerQuery;
use App\Modules\Accounting\Actions\TrialBalance as TrialBalanceQuery;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Reporting\Support\ExcelExport;
use App\Modules\Reporting\Support\PdfExport;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Financial Reports at /reports/financial (route wired centrally), gated
 * `ledger.view` for consistency with the other statement screens in this
 * module (`Livewire\Reports\TrialBalance`, `Livewire\JournalEntries\Index`)
 * - financial statements are read access under the same permission as the
 * books they summarise, not the generic `reports.view` used by cross-module
 * report clusters.
 *
 * Four tabs, each backed by the SAME read path every ledger statement in
 * this module must use:
 *   - Trial Balance:      delegates to `Actions\TrialBalance` (D5), unchanged.
 *   - Account Statement:  delegates to `Actions\GeneralLedgerQuery` (D5),
 *                          unchanged - one account, running balance.
 *   - General Ledger:     all postable lines across accounts, built here
 *                          directly off `journal_entry_lines`/`journal_entries`
 *                          via `JournalEntry::postedLedger()` (never a bare
 *                          `where('status', 'posted')` - see that scope's
 *                          docblock and the architecture test it protects).
 *   - Journal Register:   one row per journal entry, same scope.
 *
 * Trial Balance and Account Statement are computed as whole in-memory
 * Collections by their Actions (small - one row per account, or per posting
 * on one account), so on-screen preview paginates that Collection manually;
 * General Ledger and Journal Register paginate a query builder directly.
 * Export methods always re-run the unpaginated query/Action so the
 * spreadsheet/PDF carries every row, not just the visible page.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    /** Which report is showing: trial-balance | general-ledger | journal-register | account-statement. */
    #[Url]
    public string $tab = 'trial-balance';

    #[Url]
    public string $fiscalYearId = '';

    #[Url]
    public string $accountingPeriodId = '';

    /** Account Statement tab only. */
    #[Url]
    public string $accountId = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    public function mount(): void
    {
        Gate::authorize(Permission::LedgerView->value);

        if ($this->fiscalYearId === '') {
            $openYear = FiscalYear::query()->where('status', 'open')->first();
            $this->fiscalYearId = $openYear === null ? '' : (string) $openYear->id;
        }
    }

    public function selectTab(string $tab): void
    {
        $this->tab = in_array($tab, ['trial-balance', 'general-ledger', 'journal-register', 'account-statement', 'treasury-position'], true)
            ? $tab
            : 'trial-balance';
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['accountingPeriodId', 'accountId']);
        $this->resetPage();
    }

    public function updatedFiscalYearId(): void
    {
        $this->accountingPeriodId = '';
        $this->resetPage();
    }

    public function updatedAccountingPeriodId(): void
    {
        $this->resetPage();
    }

    public function updatedAccountId(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    private function resetPage(): void
    {
        $this->page = 1;
    }

    private function fiscalYearIdOrNull(): ?int
    {
        return $this->fiscalYearId === '' ? null : (int) $this->fiscalYearId;
    }

    /**
     * Paginate an already-fully-computed Collection for on-screen preview,
     * matching the `x-list-screen` binding rule (00-core 6.2 rule 8) that
     * every list on screen is paginated - even when the underlying Action
     * itself returns the whole Collection because a trial balance / single
     * account statement is inherently small.
     *
     * Generic in the row type: each tab's Action returns its OWN row shape,
     * and a non-generic `Collection<int, object>` parameter would reject all
     * of them (Collection's TValue is invariant), which is exactly what
     * level-8 analysis was reporting here.
     *
     * @template TRow
     *
     * @param  Collection<int, TRow>  $items
     * @return LengthAwarePaginator<int, TRow>
     */
    private function paginateCollection(Collection $items): LengthAwarePaginator
    {
        $page = max(1, $this->page);
        $slice = $items->slice(($page - 1) * $this->perPage, $this->perPage)->values();

        return new LengthAwarePaginator(
            $slice,
            $items->count(),
            $this->perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }

    /**
     * @return Collection<int, object{account_id: int, code: string, name: string, name_fr: string, total_debit: int, total_credit: int}>
     */
    private function trialBalanceRows(): Collection
    {
        $fiscalYearId = $this->fiscalYearIdOrNull();

        if ($fiscalYearId === null) {
            return collect();
        }

        return app(TrialBalanceQuery::class)->handle($fiscalYearId);
    }

    /**
     * @return Collection<int, object{line_id: int, entry_id: int, date: string, piece_no: string|null, label: string, debit: int, credit: int, running_balance: int}>
     */
    private function accountStatementRows(): Collection
    {
        $fiscalYearId = $this->fiscalYearIdOrNull();

        if ($fiscalYearId === null || $this->accountId === '') {
            return collect();
        }

        return app(GeneralLedgerQuery::class)->handle((int) $this->accountId, $fiscalYearId);
    }

    /**
     * Treasury Position (02-accounting §11.3): one row per place money can
     * actually sit - the cash box, the bank account, the MTN float, the
     * Orange float - each on its own line, so "how much is in the MTN float
     * right now" has a single unambiguous answer and each float reconciles
     * against its own operator statement.
     *
     * Read path is the ledger, same posted scope as every other tab here
     * (`JournalEntry::postedLedger()`), restricted to class-5 lines. The one
     * addition is ATTRIBUTION: a fee payment line is shown under the float
     * the payment itself names (`payments.treasury_account_id`) when it names
     * one. Nothing is re-posted and no entry is touched - this is a
     * presentation of existing ledger money under the account that received
     * it, which matters precisely because every pre-existing receipt was
     * posted through ONE hardcoded account and the float it really landed in
     * was recorded nowhere else. Reversal entries follow the payment they
     * reverse, so a voided receipt leaves the same float it entered.
     *
     * The `&\stdClass` in the row type is not decoration: the rows are built
     * here with an object cast, so that intersection is precisely what this
     * method returns, and stating it keeps level-8 analysis exact rather than
     * approximately right.
     *
     * @return Collection<int, object{account_id: int, code: string, name: string, type_label: string, total_debit: int, total_credit: int, balance: int}&\stdClass>
     */
    private function treasuryPositionRows(): Collection
    {
        $entries = JournalEntry::query()
            ->postedLedger()
            ->when($this->fiscalYearIdOrNull() !== null, function (Builder $query): void {
                $query->where('fiscal_year_id', (int) $this->fiscalYearId);
            })
            ->when($this->accountingPeriodId !== '', function (Builder $query): void {
                $query->where('accounting_period_id', (int) $this->accountingPeriodId);
            })
            ->select('id', 'reverses_entry_id');

        $movements = DB::table('journal_entry_lines as l')
            ->joinSub($entries, 'e', 'e.id', '=', 'l.journal_entry_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
            ->leftJoin('payments as p', 'p.journal_entry_id', '=', 'e.id')
            ->leftJoin('payments as rp', 'rp.journal_entry_id', '=', 'e.reverses_entry_id')
            ->where('a.account_class', 5)
            ->groupBy(DB::raw('COALESCE(p.treasury_account_id, rp.treasury_account_id, l.account_id)'))
            ->select([
                DB::raw('COALESCE(p.treasury_account_id, rp.treasury_account_id, l.account_id) as account_id'),
                DB::raw('COALESCE(SUM(l.debit), 0) as total_debit'),
                DB::raw('COALESCE(SUM(l.credit), 0) as total_credit'),
            ])
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->account_id);

        // Every float the school HAS, even at zero (a MoMo account showing
        // 0 is information; a missing row is a question), plus any account
        // that carries movement but has since been made non-postable - money
        // never silently disappears from this statement.
        $accounts = ChartOfAccount::query()
            ->where('account_class', 5)
            ->where(function (\Illuminate\Database\Eloquent\Builder $query) use ($movements): void {
                $query->where(fn (\Illuminate\Database\Eloquent\Builder $q) => $q
                    ->where('is_postable', true)->where('is_archived', false))
                    ->orWhereIn('id', $movements->keys()->all());
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        return $accounts->map(function (ChartOfAccount $account) use ($movements): object {
            $movement = $movements->get((int) $account->id);
            $debit = $movement === null ? 0 : (int) $movement->total_debit;
            $credit = $movement === null ? 0 : (int) $movement->total_credit;

            return (object) [
                'account_id' => (int) $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type_label' => self::treasuryTypeLabel($account->code),
                'total_debit' => $debit,
                'total_credit' => $credit,
                // Class 5 is a debit-normal asset family: what is left in
                // the float is what went in minus what went out.
                'balance' => $debit - $credit,
            ];
        })->values();
    }

    /**
     * What KIND of float this is, read off the SYSCOHADA code prefix
     * (02-accounting §2): 57 Caisse, 52 Banques, 55 Instruments de monnaie
     * electronique (552 Telephone portable = MTN / Orange floats).
     */
    private static function treasuryTypeLabel(string $code): string
    {
        return match (true) {
            str_starts_with($code, '57') => 'Cash box',
            str_starts_with($code, '52') => 'Bank',
            str_starts_with($code, '55') => 'Mobile money',
            default => 'Treasury',
        };
    }

    /**
     * @return Builder<JournalEntry>
     */
    private function journalRegisterQuery(): Builder
    {
        return JournalEntry::query()
            ->postedLedger()
            ->when($this->fiscalYearIdOrNull() !== null, function (Builder $query): void {
                $query->where('fiscal_year_id', (int) $this->fiscalYearId);
            })
            ->when($this->accountingPeriodId !== '', function (Builder $query): void {
                $query->where('accounting_period_id', (int) $this->accountingPeriodId);
            });
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function generalLedgerQueryBuilder(): \Illuminate\Database\Query\Builder
    {
        $postedEntryIds = JournalEntry::query()
            ->postedLedger()
            ->when($this->fiscalYearIdOrNull() !== null, function (Builder $query): void {
                $query->where('fiscal_year_id', (int) $this->fiscalYearId);
            })
            ->when($this->accountingPeriodId !== '', function (Builder $query): void {
                $query->where('accounting_period_id', (int) $this->accountingPeriodId);
            })
            ->select('id', 'date', 'journal_id');

        return DB::table('journal_entry_lines as l')
            ->joinSub($postedEntryIds, 'e', 'e.id', '=', 'l.journal_entry_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
            ->join('journals as j', 'j.id', '=', 'e.journal_id')
            ->orderBy('e.date')
            ->orderBy('e.id')
            ->orderBy('l.sequence')
            ->select([
                'l.id as line_id', 'e.date', 'j.code as journal_code', 'a.code as account_code',
                'a.name as account_name', 'l.debit', 'l.credit', 'l.label',
            ]);
    }

    /**
     * @return LengthAwarePaginatorContract<int, object>
     */
    private function rows(): LengthAwarePaginatorContract
    {
        return match ($this->tab) {
            'general-ledger' => $this->generalLedgerQueryBuilder()->paginate($this->perPage, page: $this->page),
            'journal-register' => $this->journalRegisterQuery()
                ->orderByDesc('date')->orderByDesc('id')
                ->paginate($this->perPage, ['*'], 'page', $this->page),
            'account-statement' => $this->paginateCollection($this->accountStatementRows()),
            'treasury-position' => $this->paginateCollection($this->treasuryPositionRows()),
            default => $this->paginateCollection($this->trialBalanceRows()),
        };
    }

    /**
     * @return array{title: string, headers: list<string>, rows: list<list<mixed>>}
     */
    private function exportData(): array
    {
        return match ($this->tab) {
            'general-ledger' => [
                'title' => 'General Ledger',
                'headers' => ['Date', 'Journal', 'Account', 'Label', 'Debit', 'Credit'],
                'rows' => $this->generalLedgerQueryBuilder()->get()->map(fn (object $row): array => [
                    $row->date, $row->journal_code, $row->account_code.' - '.$row->account_name,
                    $row->label, $row->debit, $row->credit,
                ])->all(),
            ],
            'journal-register' => [
                'title' => 'Journal Register',
                'headers' => ['Reference', 'Date', 'Journal', 'Status', 'Total'],
                'rows' => $this->journalRegisterQuery()
                    ->orderByDesc('date')->orderByDesc('id')
                    ->get()
                    ->map(fn (JournalEntry $entry): array => [
                        $entry->piece_no ?? (string) $entry->reference,
                        $entry->date->format('Y-m-d'),
                        $this->journals()->get($entry->journal_id)?->code ?? '—',
                        $entry->status,
                        $entry->total_debit,
                    ])->all(),
            ],
            'treasury-position' => [
                'title' => 'Treasury Position',
                'headers' => ['Code', 'Account', 'Type', 'Debits', 'Credits', 'Balance'],
                'rows' => $this->treasuryPositionRows()->map(fn (object $row): array => [
                    $row->code, $row->name, $row->type_label,
                    $row->total_debit, $row->total_credit, $row->balance,
                ])->all(),
            ],
            'account-statement' => [
                'title' => 'Account Statement',
                'headers' => ['Date', 'Piece No', 'Label', 'Debit', 'Credit', 'Running Balance'],
                'rows' => $this->accountStatementRows()->map(fn (object $row): array => [
                    $row->date, $row->piece_no ?? '—', $row->label, $row->debit, $row->credit, $row->running_balance,
                ])->all(),
            ],
            default => [
                'title' => 'Trial Balance',
                'headers' => ['Code', 'Account', 'Debit', 'Credit'],
                'rows' => $this->trialBalanceRows()->map(fn (object $row): array => [
                    $row->code, $row->name, $row->total_debit, $row->total_credit,
                ])->all(),
            ],
        };
    }

    public function exportExcel(): StreamedResponse
    {
        Gate::authorize(Permission::LedgerView->value);

        $data = $this->exportData();

        return ExcelExport::download(
            $data['title'],
            $data['headers'],
            $data['rows'],
            str($data['title'])->slug()->value().'.xlsx',
        );
    }

    public function exportPdf(): Response
    {
        Gate::authorize(Permission::LedgerView->value);

        $data = $this->exportData();
        $orientation = in_array($this->tab, ['general-ledger', 'journal-register'], true) ? 'landscape' : 'portrait';

        return PdfExport::download(
            $data['title'],
            $data['headers'],
            $data['rows'],
            str($data['title'])->slug()->value().'.pdf',
            $orientation,
        );
    }

    /**
     * @return Collection<int, FiscalYear>
     */
    private function fiscalYears(): Collection
    {
        return FiscalYear::query()->orderByDesc('starts_on')->get();
    }

    /**
     * @return Collection<int, AccountingPeriod>
     */
    private function accountingPeriods(): Collection
    {
        if ($this->fiscalYearId === '') {
            return collect();
        }

        return AccountingPeriod::query()
            ->where('fiscal_year_id', (int) $this->fiscalYearId)
            ->orderBy('starts_on')
            ->get();
    }

    /**
     * @return Collection<int, ChartOfAccount>
     */
    private function accountOptions(): Collection
    {
        return ChartOfAccount::query()->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name']);
    }

    /**
     * @return Collection<int, Journal>
     */
    private function journals(): Collection
    {
        return Journal::query()->orderBy('code')->get()->keyBy('id');
    }

    public function render(): mixed
    {
        return view('livewire.accounting.reports.index', [
            'rows' => $this->rows(),
            'fiscalYearOptions' => $this->fiscalYears(),
            'accountingPeriodOptions' => $this->accountingPeriods(),
            'accountOptions' => $this->accountOptions(),
            'journalOptions' => $this->journals(),
        ]);
    }
}
