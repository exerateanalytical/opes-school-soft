<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\YearEnd;

use App\Modules\Accounting\Actions\VerifyLedgerIntegrity;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/02-accounting.md §17.9 - step 7 of the close sequence.
 *
 * "Step 7 is not 'print the balance'. It is a validation Action returning a
 * pass/fail set." This is that Action, and its eleven checks are §17.9's
 * table, in its order. Nothing here writes; the caller
 * (EvaluateYearEndChecklist) stores the result as the item's evidence, which
 * is what YE-4 means by "the validation result is stored on the item".
 *
 * Six of the eleven are already implemented, correctly and under test, by
 * `VerifyLedgerIntegrity` (L2, L7, L8, L9, L10, L11). They are DELEGATED,
 * not reimplemented: a second copy of the auxiliary-reconciliation query
 * that drifts from the first is precisely the defect the nightly backstop
 * exists to catch. The five that are genuinely new here - the global
 * balance, the DSF mapping, suspense accounts, drafts inside the year, and
 * the bank reconciliation - are the ones §17.9 adds on top of the nightly
 * invariants because they are questions only a CLOSE asks.
 *
 * Every failure carries the offending rows, so "every failure is actionable
 * and links to the offending rows" is a property of the return value rather
 * than of a screen.
 *
 * @phpstan-type Check array{code: string, label: string, status: string, failures: list<array<string, int|string>>}
 */
final class ValidateYearEndTrialBalance
{
    public const STATUS_PASS = 'pass';

    public const STATUS_FAIL = 'fail';

    /** The check cannot run in this build (its table does not exist yet). */
    public const STATUS_UNAVAILABLE = 'unavailable';

    public function __construct(private readonly VerifyLedgerIntegrity $integrity) {}

    /**
     * @return array{passed: bool, fiscal_year_id: int, checked_at: string, checks: list<Check>}
     *
     * @phpstan-return array{passed: bool, fiscal_year_id: int, checked_at: string, checks: list<Check>}
     */
    public function handle(int $fiscalYearId): array
    {
        Gate::authorize(Permission::LedgerView->value);

        $invariants = $this->integrity->handle($fiscalYearId);

        $checks = [
            $this->globalBalance($fiscalYearId),
            $this->fromInvariant('per_entry_balance', 'Per-entry balance (L2)', $invariants['L2'] ?? []),
            $this->fromInvariant('partner_integrity', 'Collective-account partner discipline (L8)', $invariants['L8'] ?? []),
            $this->fromInvariant('auxiliary_reconciliation', 'Auxiliary ledger reconciles to the GL (L9)', $invariants['L9'] ?? []),
            $this->fromInvariant('lettering', 'Lettering groups net to zero (L10)', $invariants['L10'] ?? []),
            $this->fromInvariant('analytic', 'Mandatory analytic axes are split (L11/AN-3)', $invariants['L11'] ?? []),
            $this->fromInvariant('sequence', 'Piece-number sequence is gapless (L7)', $invariants['L7'] ?? []),
            $this->bankReconciliation($fiscalYearId),
            $this->dsfMapping(),
            $this->suspenseAccounts($fiscalYearId),
            $this->draftEntries($fiscalYearId),
        ];

        $passed = true;

        foreach ($checks as $check) {
            if ($check['status'] === self::STATUS_FAIL) {
                $passed = false;
            }
        }

        return [
            'passed' => $passed,
            'fiscal_year_id' => $fiscalYearId,
            'checked_at' => now()->toDateTimeString(),
            'checks' => $checks,
        ];
    }

    /**
     * @param  list<array<string, int|string>>  $findings
     *
     * @phpstan-return Check
     */
    private function fromInvariant(string $code, string $label, array $findings): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'status' => $findings === [] ? self::STATUS_PASS : self::STATUS_FAIL,
            'failures' => $findings,
        ];
    }

    /**
     * §17.9 row 1: Σdebit ≠ Σcredit across all posted+reversed entries.
     * `reversed` is included for the reason TrialBalance's docblock gives -
     * dropping it keeps the reversal and loses the entry it cancels.
     *
     * @phpstan-return Check
     */
    private function globalBalance(int $fiscalYearId): array
    {
        /** @var object{d: int|string, c: int|string}|null $row */
        $row = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.fiscal_year_id', $fiscalYearId)
            ->whereIn('e.status', [JournalEntry::STATUS_POSTED, JournalEntry::STATUS_REVERSED])
            ->selectRaw('CAST(COALESCE(SUM(l.debit), 0) AS SIGNED) as d, CAST(COALESCE(SUM(l.credit), 0) AS SIGNED) as c')
            ->first();

        $debit = (int) ($row->d ?? 0);
        $credit = (int) ($row->c ?? 0);

        return [
            'code' => 'global_balance',
            'label' => 'Global balance: sum of debits equals sum of credits',
            'status' => $debit === $credit ? self::STATUS_PASS : self::STATUS_FAIL,
            'failures' => $debit === $credit ? [] : [[
                'total_debit' => $debit,
                'total_credit' => $credit,
                'difference' => $debit - $credit,
            ]],
        ];
    }

    /**
     * §17.9 row "Bank reconciliation": any `is_reconcilable` account with an
     * incomplete session for any period.
     *
     * The reconciliation-session table is not in this build (§13 is a later
     * phase), so the check reports UNAVAILABLE rather than silently passing
     * - a check that cannot run must not be mistaken for one that ran and
     * found nothing. It is schema-guarded exactly like VerifyLedgerIntegrity
     * guards L11, so it starts working the day the table lands.
     *
     * @phpstan-return Check
     */
    private function bankReconciliation(int $fiscalYearId): array
    {
        if (! Schema::hasTable('bank_reconciliation_sessions')) {
            $accounts = DB::table('chart_of_accounts')
                ->where('is_reconcilable', true)
                ->where('is_archived', false)
                ->orderBy('code')
                ->get(['id', 'code', 'name']);

            $rows = [];

            foreach ($accounts as $account) {
                $rows[] = [
                    'account_id' => (int) $account->id,
                    'code' => (string) $account->code,
                    'name' => (string) $account->name,
                    'note' => 'No reconciliation session table in this build; reconcile manually and waive with a reason.',
                ];
            }

            return [
                'code' => 'bank_reconciliation',
                'label' => 'Bank/mobile-money reconciliation complete for every period',
                'status' => self::STATUS_UNAVAILABLE,
                'failures' => $rows,
            ];
        }

        $rows = DB::table('bank_reconciliation_sessions as s')
            ->join('chart_of_accounts as a', 'a.id', '=', 's.account_id')
            ->join('accounting_periods as p', 'p.id', '=', 's.accounting_period_id')
            ->where('p.fiscal_year_id', $fiscalYearId)
            ->where('s.status', '<>', 'completed')
            ->orderBy('a.code')
            ->get(['s.id as session_id', 'a.code', 's.status']);

        $failures = [];

        foreach ($rows as $row) {
            $failures[] = [
                'session_id' => (int) $row->session_id,
                'code' => (string) $row->code,
                'status' => (string) $row->status,
            ];
        }

        return [
            'code' => 'bank_reconciliation',
            'label' => 'Bank/mobile-money reconciliation complete for every period',
            'status' => $failures === [] ? self::STATUS_PASS : self::STATUS_FAIL,
            'failures' => $failures,
        ];
    }

    /**
     * §17.9 row "DSF mapping": any postable class 1-8 account with a null
     * `dsf_line_code`.
     *
     * This one WILL fail on a fresh install, and that is the point: the
     * migration that populated `dsf_statement` deliberately left
     * `dsf_line_code` NULL because the DGI line codes are unverified. A
     * close that pretended this passed would produce a DSF whose lines are
     * unmapped. It is a real blocking item until an accountant maps them.
     *
     * @phpstan-return Check
     */
    private function dsfMapping(): array
    {
        $rows = DB::table('chart_of_accounts')
            ->where('is_postable', true)
            ->where('is_archived', false)
            ->whereBetween('account_class', [1, 8])
            ->whereNull('dsf_line_code')
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $failures = [];

        foreach ($rows as $row) {
            $failures[] = [
                'account_id' => (int) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
            ];
        }

        return [
            'code' => 'dsf_mapping',
            'label' => 'Every postable class 1-8 account carries a DSF line code',
            'status' => $failures === [] ? self::STATUS_PASS : self::STATUS_FAIL,
            'failures' => $failures,
        ];
    }

    /**
     * §17.9 row "Suspense": any non-zero balance on a suspense/waiting
     * account. In SYSCOHADA those are the 47x *comptes d'attente et de
     * régularisation* proper - 471 *Compte d'attente* and 478 *Autres
     * comptes transitoires*. 476/477 (charges/produits constatés d'avance)
     * are NOT suspense: they are the §17.4 cut-off accounts and they are
     * SUPPOSED to carry a balance at 31 December, so matching them here
     * would fail every correct close.
     *
     * @phpstan-return Check
     */
    private function suspenseAccounts(int $fiscalYearId): array
    {
        $rows = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('e.fiscal_year_id', $fiscalYearId)
            ->whereIn('e.status', [JournalEntry::STATUS_POSTED, JournalEntry::STATUS_REVERSED])
            ->where(function ($query): void {
                $query->where('a.code', 'like', '471%')->orWhere('a.code', 'like', '478%');
            })
            ->groupBy('a.id', 'a.code', 'a.name')
            ->havingRaw('COALESCE(SUM(l.debit), 0) <> COALESCE(SUM(l.credit), 0)')
            ->selectRaw('a.id, a.code, a.name, CAST(COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0) AS SIGNED) as balance')
            ->get();

        $failures = [];

        foreach ($rows as $row) {
            $failures[] = [
                'account_id' => (int) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'balance' => (int) $row->balance,
            ];
        }

        return [
            'code' => 'suspense',
            'label' => 'No suspense account (471/478) carries a balance',
            'status' => $failures === [] ? self::STATUS_PASS : self::STATUS_FAIL,
            'failures' => $failures,
        ];
    }

    /**
     * §17.9 row "Draft entries": any draft entry dated inside the year -
     * "must be posted or discarded, never left".
     *
     * @phpstan-return Check
     */
    private function draftEntries(int $fiscalYearId): array
    {
        $rows = DB::table('journal_entries')
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('status', JournalEntry::STATUS_DRAFT)
            ->orderBy('date')
            ->get(['id', 'date', 'label']);

        $failures = [];

        foreach ($rows as $row) {
            $failures[] = [
                'entry_id' => (int) $row->id,
                'date' => (string) $row->date,
                'label' => (string) $row->label,
            ];
        }

        return [
            'code' => 'draft_entries',
            'label' => 'No draft entry remains inside the exercice',
            'status' => $failures === [] ? self::STATUS_PASS : self::STATUS_FAIL,
            'failures' => $failures,
        ];
    }
}
