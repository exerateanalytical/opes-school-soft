<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Actions\Reconciliation\ImportBankStatement;
use App\Modules\Accounting\Domain\StatementSource;
use App\Modules\Accounting\Models\BankStatement;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Models\User;
use App\Support\Audit\Actor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\PermissionRegistrar;

/**
 * A demo MTN Mobile Money operator statement (02-accounting.md §13, §1.3)
 * that TIES to the fee receipts already in the demo ledger, so the
 * reconciliation screen opens on a real reconciliation instead of an empty
 * shell.
 *
 * The statement is DERIVED from the ledger rather than invented beside it:
 * every line mirrors an existing posted movement on 5521 in the chosen
 * month, at its own date and its own amount, carrying the receipt number as
 * the operator's reference. That is what a real MoMo export looks like for a
 * school whose books are correct, and it means this seeder cannot drift out
 * of agreement with demo data that another seeder changes.
 *
 * It writes NOTHING but its own two tables. No payment, no journal entry and
 * no journal line is created, amended or touched - reconciliation is an
 * annotation, and a seeder that "fixed up" the ledger to make its own demo
 * work would be the exact failure this feature exists to detect.
 *
 * Idempotent: keyed on `UNIQUE(treasury_account_id, statement_reference)`,
 * it returns immediately if its statement is already there.
 */
final class BankStatementSeeder extends Seeder
{
    /** The float this demo statement belongs to - MTN, per §1.3 a 552x row. */
    private const TREASURY_CODE = '5521';

    public function run(): void
    {
        Auth::login($this->admin());

        $account = ChartOfAccount::query()->where('code', self::TREASURY_CODE)->first();

        if ($account === null || ! $account->is_reconcilable) {
            $this->command?->warn('No reconcilable '.self::TREASURY_CODE.' account; MoMo statement not seeded.');

            return;
        }

        $movements = $this->ledgerMovements((int) $account->getKey());

        if ($movements === []) {
            $this->command?->warn('No posted 5521 movements to build a demo statement from.');

            return;
        }

        $reference = 'MTN-MOMO-'.substr((string) $movements[0]->date, 0, 7);

        $already = BankStatement::query()
            ->where('treasury_account_id', $account->getKey())
            ->where('statement_reference', $reference)
            ->exists();

        if ($already) {
            $this->command?->info('MoMo statement '.$reference.' already seeded.');

            return;
        }

        $month = substr((string) $movements[0]->date, 0, 7);
        $periodStart = $month.'-01';
        $periodEnd = date('Y-m-t', (int) strtotime($periodStart));

        $lines = [];
        $total = 0;

        foreach ($movements as $movement) {
            $signed = (int) $movement->debit - (int) $movement->credit;
            $total += $signed;

            $lines[] = [
                'operation_date' => (string) $movement->date,
                'value_date' => (string) $movement->date,
                // The operator's own wording, not the school's: this is the
                // document the school did NOT write.
                'label' => 'MoMo '.($signed >= 0 ? 'reception' : 'envoi').' '.$this->receiptRef($movement->label),
                'reference' => $this->receiptRef($movement->label),
                // Mirror image of the ledger: money in is a statement CREDIT.
                'debit' => $signed < 0 ? -$signed : 0,
                'credit' => $signed > 0 ? $signed : 0,
            ];
        }

        $statement = app(ImportBankStatement::class)->handle(
            treasuryAccountId: (int) $account->getKey(),
            statementReference: $reference,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            openingBalance: 0,
            closingBalance: $total,
            lines: $lines,
            actor: new Actor((int) Auth::id(), 'demo seeder'),
            source: StatementSource::Csv,
            fileSha256: hash('sha256', $reference.'|'.$total.'|'.count($lines)),
            notes: 'Demo MTN operator export, derived from the posted 5521 movements of '.$month.'.',
        );

        $this->command?->info(sprintf(
            'Seeded %s: %d lines, closing balance %d FCFA.',
            $statement->statement_reference,
            count($lines),
            $total,
        ));
    }

    /**
     * Posted 5521 movements of the FIRST month that has any - real ledger
     * rows, read through the same postedLedger scope every report uses.
     *
     * @return list<object{id: int, label: string, debit: int, credit: int, date: string}>
     */
    private function ledgerMovements(int $accountId): array
    {
        $entries = JournalEntry::query()->postedLedger()->select(['id', 'date']);

        /** @var string|null $month */
        $month = DB::table('journal_entry_lines as l')
            ->joinSub($entries, 'e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.account_id', $accountId)
            ->where('l.debit', '>', 0)
            ->orderBy('e.date')
            ->value(DB::raw("DATE_FORMAT(e.date, '%Y-%m')"));

        if ($month === null) {
            return [];
        }

        /** @var list<object{id: int, label: string, debit: int, credit: int, date: string}> $rows */
        $rows = DB::table('journal_entry_lines as l')
            ->joinSub($entries, 'e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.account_id', $accountId)
            ->whereRaw("DATE_FORMAT(e.date, '%Y-%m') = ?", [$month])
            ->orderBy('e.date')
            ->orderBy('l.id')
            ->select(['l.id', 'l.label', 'l.debit', 'l.credit', 'e.date'])
            ->get()
            ->all();

        return $rows;
    }

    /** "Encaissement RCPT/2026/000003" → "RCPT/2026/000003". */
    private function receiptRef(string $label): string
    {
        if (preg_match('#[A-Z]{2,6}/\d{4}/\d{4,}#', $label, $m) === 1) {
            return $m[0];
        }

        return substr($label, 0, 60);
    }

    /**
     * The demo admin TreasurySeeder already created, so this seeder adds no
     * account of its own. `ledger.post` is granted if missing - the Actions
     * are exercised through their Gates here, never around them.
     */
    private function admin(): User
    {
        /** @var User|null $user */
        $user = User::query()->where('email', 'demo.admin@opeschool.test')->first()
            ?? User::query()->orderBy('id')->first();

        if ($user === null) {
            throw new \RuntimeException('No user to attribute the demo statement to; run the demo seeders first.');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        SpatiePermission::findOrCreate(Permission::LedgerPost->value, 'web');

        if (! $user->hasPermissionTo(Permission::LedgerPost->value)) {
            $user->givePermissionTo(Permission::LedgerPost->value);
        }

        return $user->fresh() ?? $user;
    }
}
