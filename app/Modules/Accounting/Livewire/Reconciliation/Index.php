<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Livewire\Reconciliation;

use App\Modules\Accounting\Actions\Reconciliation\AutoMatchStatementLines;
use App\Modules\Accounting\Actions\Reconciliation\BuildReconciliationStatement;
use App\Modules\Accounting\Actions\Reconciliation\CloseReconciliationSession;
use App\Modules\Accounting\Actions\Reconciliation\ImportBankStatement;
use App\Modules\Accounting\Actions\Reconciliation\MatchReconciliationLines;
use App\Modules\Accounting\Actions\Reconciliation\OpenReconciliationSession;
use App\Modules\Accounting\Actions\Reconciliation\UnmatchReconciliation;
use App\Modules\Accounting\Domain\StatementLineStatus;
use App\Modules\Accounting\Domain\StatementSource;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\BankStatement;
use App\Modules\Accounting\Models\BankStatementLine;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\ReconciliationSession;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Reporting\Support\PdfExport;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;

/**
 * docs/specs/02-accounting.md §13 - the bank and treasury reconciliation
 * screen: pick a float, put the relevé beside the books, match, and print
 * the état de rapprochement.
 *
 * The float picker lists every POSTABLE class-5 account flagged
 * `is_reconcilable`, which is why MTN 5521 and Orange 5522 appear as two
 * separate reconciliations rather than one lumped "mobile money" - §1.3's
 * whole point - and why 571 Main Cash Box does not appear at all: a till is
 * counted at the desk (`cash_desk_sessions`), not reconciled against a
 * document nobody sends.
 *
 * Every write goes through the §13 Actions, which re-check the Gate
 * themselves; nothing here writes to a table. Reads that need the ledger use
 * `JournalEntry::postedLedger()` so the lines offered for matching are the
 * same lines the balance générale counts.
 *
 * This screen deliberately touches none of the existing accounting screens -
 * Reports' Treasury Position, Statements, FinanceDashboard - it is a new
 * route of its own.
 *
 * Strings are literal English: `lang/en|fr/opes.php` is under concurrent
 * edit and this feature adds no keys to it. Translating this screen is a
 * follow-up, and it is reported as one.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    #[Url]
    public ?int $accountId = null;

    #[Url]
    public ?int $periodId = null;

    /** @var list<int> */
    public array $selectedStatementLines = [];

    /** @var list<int> */
    public array $selectedLedgerLines = [];

    // ── Statement import form ───────────────────────────────────────────
    public bool $showImportForm = false;

    public string $importReference = '';

    public string $importPeriodStart = '';

    public string $importPeriodEnd = '';

    public string $importOpeningBalance = '0';

    public string $importClosingBalance = '0';

    /** `operation_date,value_date,label,reference,debit,credit`, one header row. */
    public string $importCsv = '';

    public function mount(): void
    {
        Gate::authorize(Permission::LedgerView->value);

        if ($this->accountId === null) {
            $this->accountId = $this->reconcilableAccounts()->first()?->id;
        }

        if ($this->periodId === null) {
            $this->periodId = $this->periods()->first()?->id;
        }
    }

    // ── Selection ───────────────────────────────────────────────────────

    public function selectAccount(int $accountId): void
    {
        $this->accountId = $accountId;
        $this->resetSelection();
    }

    public function selectPeriod(int $periodId): void
    {
        $this->periodId = $periodId;
        $this->resetSelection();
    }

    public function resetSelection(): void
    {
        $this->selectedStatementLines = [];
        $this->selectedLedgerLines = [];
    }

    // ── Writes, all of them delegated ───────────────────────────────────

    public function openSession(OpenReconciliationSession $open): void
    {
        Gate::authorize(Permission::LedgerPost->value);

        if ($this->accountId === null || $this->periodId === null) {
            return;
        }

        $statement = $this->statementForPeriod();

        try {
            $open->handle($this->accountId, $this->periodId, $this->actor(), $statement?->id === null ? null : (int) $statement->id);
        } catch (DomainException $e) {
            $this->addError('session', $e->getMessage());

            return;
        }

        session()->flash('status', 'Reconciliation session ready.');
    }

    public function autoMatch(AutoMatchStatementLines $auto): void
    {
        Gate::authorize(Permission::LedgerPost->value);

        $session = $this->session();

        if ($session === null) {
            return;
        }

        try {
            $result = $auto->handle((int) $session->id, $this->actor());
        } catch (DomainException $e) {
            $this->addError('session', $e->getMessage());

            return;
        }

        $this->resetSelection();
        session()->flash('status', sprintf(
            '%d line(s) matched automatically, %d left for a human.',
            $result['matched'],
            $result['skipped'],
        ));
    }

    public function matchSelected(MatchReconciliationLines $match): void
    {
        Gate::authorize(Permission::LedgerPost->value);

        $session = $this->session();

        if ($session === null) {
            return;
        }

        try {
            $match->handle(
                sessionId: (int) $session->id,
                statementLineIds: array_map(static fn (int $id): int => $id, $this->selectedStatementLines),
                ledgerLineIds: array_map(static fn (int $id): int => $id, $this->selectedLedgerLines),
                actor: $this->actor(),
            );
        } catch (DomainException $e) {
            $this->addError('match', $e->getMessage());

            return;
        }

        $this->resetSelection();
        session()->flash('status', 'Matched.');
    }

    public function unmatch(int $matchId, UnmatchReconciliation $unmatch): void
    {
        Gate::authorize(Permission::LedgerPost->value);

        try {
            $unmatch->handle($matchId, $this->actor());
        } catch (DomainException $e) {
            $this->addError('match', $e->getMessage());

            return;
        }

        session()->flash('status', 'Unmatched; both lines are free again and the ledger is untouched.');
    }

    public function closeSession(CloseReconciliationSession $close): void
    {
        Gate::authorize(Permission::LedgerPost->value);

        $session = $this->session();

        if ($session === null) {
            return;
        }

        try {
            $close->handle((int) $session->id, $this->actor());
        } catch (DomainException $e) {
            $this->addError('close', $e->getMessage());

            return;
        }

        session()->flash('status', 'Reconciliation completed; the état de rapprochement is final.');
    }

    public function toggleImportForm(): void
    {
        Gate::authorize(Permission::LedgerPost->value);

        $this->showImportForm = ! $this->showImportForm;

        if ($this->showImportForm && $this->importPeriodStart === '') {
            $period = $this->period();

            if ($period !== null) {
                $this->importPeriodStart = $period->starts_on->toDateString();
                $this->importPeriodEnd = $period->ends_on->toDateString();
            }
        }
    }

    public function importStatement(ImportBankStatement $import): void
    {
        Gate::authorize(Permission::LedgerPost->value);

        if ($this->accountId === null) {
            return;
        }

        try {
            $lines = ImportBankStatement::linesFromCsv($this->importCsv);

            $import->handle(
                treasuryAccountId: $this->accountId,
                statementReference: $this->importReference,
                periodStart: $this->importPeriodStart,
                periodEnd: $this->importPeriodEnd,
                openingBalance: (int) $this->importOpeningBalance,
                closingBalance: (int) $this->importClosingBalance,
                lines: $lines,
                actor: $this->actor(),
                source: StatementSource::Csv,
                fileSha256: hash('sha256', $this->importCsv),
            );
        } catch (DomainException $e) {
            $this->addError('import', $e->getMessage());

            return;
        }

        $this->reset(['showImportForm', 'importReference', 'importCsv', 'importOpeningBalance', 'importClosingBalance']);
        session()->flash('status', 'Statement imported.');
    }

    /** §13.3's printable état, through the shared report shell. */
    public function exportPdf(BuildReconciliationStatement $builder): ?Response
    {
        Gate::authorize(Permission::LedgerView->value);

        $session = $this->session();

        if ($session === null) {
            return null;
        }

        $etat = $builder->handle($session);
        $account = $this->account();
        $period = $this->period();

        return PdfExport::download(
            title: sprintf(
                'Etat de rapprochement %s - %s %s',
                $session->session_no,
                $account === null ? '' : $account->code,
                $period === null ? '' : $period->ends_on->toDateString(),
            ),
            headers: ['Libelle', 'Montant (FCFA)'],
            rows: [
                ['Solde du releve au '.($period === null ? '' : $period->ends_on->toDateString()), $etat['statement_balance']],
                ['+ Encaissements comptabilises non encore au releve', $etat['deposits_in_transit']],
                ['- Decaissements comptabilises non encore au releve', -$etat['unpresented_payments']],
                ['- Operations au releve non encore comptabilisees', -$etat['unrecorded_statement_items']],
                ['= Solde comptable au '.($period === null ? '' : $period->ends_on->toDateString()), $etat['book_balance']],
                ['Difference non expliquee', $etat['computed_difference']],
            ],
            filename: 'etat-rapprochement-'.str_replace('/', '-', $session->session_no).'.pdf',
        );
    }

    // ── Reads ───────────────────────────────────────────────────────────

    /**
     * @return Collection<int, ChartOfAccount>
     */
    public function reconcilableAccounts(): Collection
    {
        /** @var Collection<int, ChartOfAccount> $accounts */
        $accounts = ChartOfAccount::query()
            ->where('account_class', 5)
            ->where('is_postable', true)
            ->where('is_reconcilable', true)
            ->where('is_archived', false)
            ->orderBy('code')
            ->get();

        return $accounts;
    }

    /**
     * @return Collection<int, AccountingPeriod>
     */
    public function periods(): Collection
    {
        /** @var Collection<int, AccountingPeriod> $periods */
        $periods = AccountingPeriod::query()
            ->orderByDesc('starts_on')
            ->limit(24)
            ->get();

        return $periods;
    }

    public function account(): ?ChartOfAccount
    {
        return $this->accountId === null
            ? null
            : ChartOfAccount::query()->find($this->accountId);
    }

    public function period(): ?AccountingPeriod
    {
        return $this->periodId === null
            ? null
            : AccountingPeriod::query()->find($this->periodId);
    }

    public function session(): ?ReconciliationSession
    {
        if ($this->accountId === null || $this->periodId === null) {
            return null;
        }

        return ReconciliationSession::query()
            ->where('treasury_account_id', $this->accountId)
            ->where('accounting_period_id', $this->periodId)
            ->first();
    }

    /** The relevé whose period end falls inside the selected period. */
    public function statementForPeriod(): ?BankStatement
    {
        $period = $this->period();

        if ($this->accountId === null || $period === null) {
            return null;
        }

        return BankStatement::query()
            ->where('treasury_account_id', $this->accountId)
            ->whereDate('period_end', '>=', $period->starts_on->toDateString())
            ->whereDate('period_end', '<=', $period->ends_on->toDateString())
            ->orderByDesc('id')
            ->first();
    }

    public function render(BuildReconciliationStatement $builder): View
    {
        Gate::authorize(Permission::LedgerView->value);

        $session = $this->session();
        $statement = $session !== null && $session->bank_statement_id !== null
            ? $session->statement()->first()
            : $this->statementForPeriod();

        $etat = $session === null
            ? null
            : $builder->handle($session);

        return view('livewire.accounting.reconciliation.index', [
            'accounts' => $this->reconcilableAccounts(),
            'periodOptions' => $this->periods(),
            'account' => $this->account(),
            'period' => $this->period(),
            'session' => $session,
            'statement' => $statement,
            'statementLines' => $statement === null ? collect() : $this->statementLines((int) $statement->id),
            'ledgerLines' => $this->unmatchedLedgerLines(),
            'matches' => $this->matchRows($session),
            'etat' => $etat,
            'canPost' => Gate::allows(Permission::LedgerPost->value),
        ]);
    }

    /**
     * @return Collection<int, BankStatementLine>
     */
    private function statementLines(int $statementId): Collection
    {
        /** @var Collection<int, BankStatementLine> $lines */
        $lines = BankStatementLine::query()
            ->where('bank_statement_id', $statementId)
            ->orderBy('line_no')
            ->get();

        return $lines;
    }

    /**
     * @return Collection<int, object{id: int, label: string, debit: int, credit: int, date: string, piece_no: string|null}>
     */
    private function unmatchedLedgerLines(): Collection
    {
        $period = $this->period();

        if ($this->accountId === null || $period === null) {
            /** @var Collection<int, object{id: int, label: string, debit: int, credit: int, date: string, piece_no: string|null}> $empty */
            $empty = collect();

            return $empty;
        }

        $entries = JournalEntry::query()
            ->postedLedger()
            ->where('fiscal_year_id', $period->fiscal_year_id)
            ->whereDate('date', '<=', $period->ends_on->toDateString())
            ->select(['id', 'date', 'piece_no']);

        /** @var Collection<int, object{id: int, label: string, debit: int, credit: int, date: string, piece_no: string|null}> $rows */
        $rows = DB::table('journal_entry_lines as l')
            ->joinSub($entries, 'e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.account_id', $this->accountId)
            ->whereNull('l.reconciliation_match_id')
            ->orderBy('e.date')
            ->orderBy('l.id')
            ->select(['l.id', 'l.label', 'l.debit', 'l.credit', 'e.date', 'e.piece_no'])
            ->get();

        return $rows;
    }

    /**
     * @return Collection<int, object{id: int, amount: int, match_type: string, is_auto: bool, confidence_bp: int, statement_lines: string, ledger_lines: string}>
     */
    private function matchRows(?ReconciliationSession $session): Collection
    {
        if ($session === null) {
            /** @var Collection<int, object{id: int, amount: int, match_type: string, is_auto: bool, confidence_bp: int, statement_lines: string, ledger_lines: string}> $empty */
            $empty = collect();

            return $empty;
        }

        /** @var Collection<int, object{id: int, amount: int, match_type: string, is_auto: bool, confidence_bp: int, statement_lines: string, ledger_lines: string}> $rows */
        $rows = DB::table('reconciliation_matches as m')
            ->leftJoin('reconciliation_match_statement_lines as ms', 'ms.reconciliation_match_id', '=', 'm.id')
            ->leftJoin('bank_statement_lines as s', 's.id', '=', 'ms.bank_statement_line_id')
            ->leftJoin('reconciliation_match_ledger_lines as ml', 'ml.reconciliation_match_id', '=', 'm.id')
            ->leftJoin('journal_entry_lines as l', 'l.id', '=', 'ml.journal_entry_line_id')
            ->where('m.reconciliation_session_id', $session->id)
            ->groupBy('m.id', 'm.amount', 'm.match_type', 'm.is_auto', 'm.confidence_bp')
            ->orderBy('m.id')
            ->select([
                'm.id',
                'm.amount',
                'm.match_type',
                'm.is_auto',
                'm.confidence_bp',
                DB::raw('GROUP_CONCAT(DISTINCT s.label ORDER BY s.line_no SEPARATOR " | ") as statement_lines'),
                DB::raw('GROUP_CONCAT(DISTINCT l.label ORDER BY l.id SEPARATOR " | ") as ledger_lines'),
            ])
            ->get();

        return $rows;
    }

    private function actor(): Actor
    {
        /** @var \App\Modules\Identity\Models\User|null $user */
        $user = auth()->user();

        return $user === null
            ? Actor::system()
            : new Actor((int) $user->getKey(), (string) $user->name);
    }
}
