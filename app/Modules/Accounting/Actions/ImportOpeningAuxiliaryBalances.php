<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use App\Support\Money\Money;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/**
 * Opening-balance migration, part 2 of 2: the per-partner breakdown of the
 * collective accounts (411x students, 401 suppliers, 42x staff, ...).
 * docs/specs/02-accounting.md §18.4, and §18.2's rule that partner detail is
 * preserved - one line per partner, never a lump.
 *
 * Sanction to write `AN`: identical to ImportOpeningTrialBalance - §3 names
 * the opening-balance import as one of the two permitted writers of the
 * `is_system` AN journal. Same posting path too: DraftJournalEntry +
 * PostJournalEntry (which do not block system journals), with
 * `is_migration = true` stamped on the draft in the same transaction.
 *
 * How the two halves meet. The trial-balance import REFUSES collective
 * accounts (L8 forbids a partner-less line on them), so the accountant
 * carries each collective total on a postable, NON-collective migration
 * suspense account in the trial balance. This Action then posts the detail:
 * per-partner lines on the collective accounts, balanced by counter-lines
 * that clear the suspense. After both imports the suspense is exactly zero,
 * the collective accounts hold their opening balances partner by partner,
 * and L9 (Sigma auxiliary = GL balance) holds by construction.
 *
 * The Sigma check: before posting, the suspense account's posted migration
 * balance in the ledger must equal the net of all auxiliary rows. A mismatch
 * is a hard refusal listing each collective account's per-partner sum and
 * the overall difference. §18.4's full OB-3/OB-5 form (validating against
 * DECLARED totals held on an OpeningBalanceImport staging row) needs the
 * staging tables, which are a later phase - the honest check available
 * today is against what the trial-balance import actually posted.
 */
final class ImportOpeningAuxiliaryBalances
{
    /**
     * partner_type enum value => table holding that partner. `partner_id` is
     * deliberately not an FK (§8.3, polymorphic across five tables); this
     * Action carries the spec's "Action-level existence check".
     *
     * @var array<string, string>
     */
    private const PARTNER_TABLES = [
        'student' => 'students',
        'guardian' => 'guardians',
        'supplier' => 'suppliers',
        'staff' => 'staff_members',
        'organisation' => 'organisations',
    ];

    public function __construct(
        private readonly WriteAuditEntry $audit,
        private readonly DraftJournalEntry $draft,
        private readonly PostJournalEntry $post,
    ) {
    }

    /**
     * `amount_signed` convention: positive = debit balance, negative = credit
     * balance (a student owing fees is positive on 4111; a supplier owed
     * money is negative on 401).
     *
     * @param  array<int, array{account_code: string, partner_type: string, partner_id: int, amount_signed: int, due_date?: string|null}>  $rows
     * @return array{errors: list<string>, per_account: array<string, int>, net_total: int, suspense_balance: int}
     */
    public function validate(array $rows, string $suspenseAccountCode): array
    {
        $errors = [];
        $perAccount = [];

        if ($rows === []) {
            $errors[] = 'The import contains no rows.';
        }

        $accounts = ChartOfAccount::query()
            ->whereIn('code', array_column($rows, 'account_code'))
            ->get()
            ->keyBy('code');

        /** @var ChartOfAccount|null $suspense */
        $suspense = ChartOfAccount::query()->where('code', $suspenseAccountCode)->first();

        if ($suspense === null) {
            $errors[] = "Suspense account {$suspenseAccountCode} does not exist. Create a postable, non-collective migration "
                .'suspense account (chart-of-accounts extension) before running the auxiliary import.';
        } elseif (! $suspense->is_postable || $suspense->is_collective || $suspense->is_archived) {
            $errors[] = "Suspense account {$suspenseAccountCode} must be postable, non-collective and not archived.";
            $suspense = null;
        }

        foreach (array_values($rows) as $index => $row) {
            $rowNo = $index + 1;
            $code = $row['account_code'];

            /** @var ChartOfAccount|null $account */
            $account = $accounts->get($code);

            if ($account === null) {
                $errors[] = "Row {$rowNo}: account code {$code} does not exist in the chart of accounts.";

                continue;
            }

            if ($account->is_archived || ! $account->is_postable) {
                $errors[] = "Row {$rowNo}: account {$code} is archived or not postable.";

                continue;
            }

            if (! $account->is_collective) {
                $errors[] = "Row {$rowNo}: account {$code} is not a collective account - non-collective balances belong in the "
                    .'trial-balance import, not the auxiliary import.';

                continue;
            }

            $partnerType = $row['partner_type'];
            $allowed = $account->allowed_partner_types;

            if (! array_key_exists($partnerType, self::PARTNER_TABLES)) {
                $errors[] = "Row {$rowNo}: unknown partner type '{$partnerType}' (expected one of: "
                    .implode(', ', array_keys(self::PARTNER_TABLES)).').';

                continue;
            }

            if ($allowed !== null && ! in_array($partnerType, $allowed, true)) {
                $errors[] = "Row {$rowNo}: partner type '{$partnerType}' is not allowed on account {$code} (allowed: "
                    .implode(', ', $allowed).').';

                continue;
            }

            $table = self::PARTNER_TABLES[$partnerType];

            if (! Schema::hasTable($table)) {
                // The suppliers/organisations registries belong to modules not
                // yet installed; an unverifiable partner reference is refused,
                // not waved through.
                $errors[] = "Row {$rowNo}: cannot verify {$partnerType} #{$row['partner_id']} - the '{$table}' registry is not installed yet.";

                continue;
            }

            if (! DB::table($table)->where('id', $row['partner_id'])->exists()) {
                $errors[] = "Row {$rowNo}: {$partnerType} #{$row['partner_id']} does not exist.";

                continue;
            }

            if ($row['amount_signed'] === 0) {
                $errors[] = "Row {$rowNo}: {$partnerType} #{$row['partner_id']} on {$code} has a zero balance - drop the row.";

                continue;
            }

            $perAccount[$code] = ($perAccount[$code] ?? 0) + $row['amount_signed'];
        }

        $netTotal = array_sum($perAccount);
        $suspenseBalance = 0;

        if ($suspense !== null) {
            // What the trial-balance import actually parked on the suspense:
            // posted migration entries only.
            $suspenseBalance = (int) DB::table('journal_entry_lines as l')
                ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->where('l.account_id', $suspense->getKey())
                ->where('e.status', JournalEntry::STATUS_POSTED)
                ->where('e.is_migration', true)
                ->selectRaw('COALESCE(SUM(l.debit) - SUM(l.credit), 0) as bal')
                ->value('bal');

            if ($errors === [] && $netTotal !== $suspenseBalance) {
                $detail = [];

                foreach ($perAccount as $code => $sum) {
                    $detail[] = sprintf('%s => %s', $code, Money::of($sum)->format());
                }

                $errors[] = sprintf(
                    'Auxiliary balances do not match what the trial-balance import posted: suspense %s holds %s, auxiliary rows '
                    .'sum to %s, difference %s. Per account: %s. Nothing was posted.',
                    $suspenseAccountCode,
                    Money::of($suspenseBalance)->format(),
                    Money::of($netTotal)->format(),
                    Money::of($suspenseBalance - $netTotal)->absolute()->format(),
                    $detail === [] ? '(none)' : implode('; ', $detail),
                );
            }
        }

        return [
            'errors' => $errors,
            'per_account' => $perAccount,
            'net_total' => $netTotal,
            'suspense_balance' => $suspenseBalance,
        ];
    }

    /**
     * @param  array<int, array{account_code: string, partner_type: string, partner_id: int, amount_signed: int, due_date?: string|null}>  $rows
     */
    public function handle(
        int $fiscalYearId,
        array $rows,
        string $asOfDate,
        Actor $actor,
        string $suspenseAccountCode = '4712',
    ): JournalEntry {
        Gate::authorize(Permission::LedgerPost->value);

        $validation = $this->validate($rows, $suspenseAccountCode);

        if ($validation['errors'] !== []) {
            throw new DomainException(implode("\n", $validation['errors']));
        }

        /** @var Journal $journal */
        $journal = Journal::query()->where('code', 'AN')->firstOrFail();

        $accounts = ChartOfAccount::query()
            ->whereIn('code', array_merge(array_column($rows, 'account_code'), [$suspenseAccountCode]))
            ->get()
            ->keyBy('code');

        /** @var ChartOfAccount $suspense */
        $suspense = $accounts->get($suspenseAccountCode);

        $lines = [];

        foreach (array_values($rows) as $row) {
            /** @var ChartOfAccount $account */
            $account = $accounts->get($row['account_code']);
            $amount = $row['amount_signed'];

            $lines[] = [
                'account_id' => (int) $account->getKey(),
                'label' => "Opening balance {$account->code} {$row['partner_type']} #{$row['partner_id']}",
                'debit' => $amount > 0 ? $amount : 0,
                'credit' => $amount < 0 ? -$amount : 0,
                'partner_type' => $row['partner_type'],
                'partner_id' => $row['partner_id'],
                'due_date' => $row['due_date'] ?? null,
            ];
        }

        // One counter-line per collective account, clearing the suspense. The
        // entry balances by construction: each group's net moved off the
        // suspense onto the collective account, partner by partner.
        foreach ($validation['per_account'] as $code => $net) {
            $lines[] = [
                'account_id' => (int) $suspense->getKey(),
                'label' => "Clear migration suspense for {$code}",
                'debit' => $net < 0 ? -$net : 0,
                'credit' => $net > 0 ? $net : 0,
            ];
        }

        return DB::transaction(function () use ($fiscalYearId, $journal, $lines, $asOfDate, $actor, $validation, $suspenseAccountCode): JournalEntry {
            $entry = $this->draft->handle(
                journalId: (int) $journal->getKey(),
                date: $asOfDate,
                valueDate: null,
                label: "Opening auxiliary balances as of {$asOfDate} (migration)",
                reference: null,
                lines: $lines,
                actor: $actor,
            );

            if ($entry->fiscal_year_id !== $fiscalYearId) {
                throw new DomainException(
                    "The as-of date {$asOfDate} falls in fiscal year {$entry->fiscal_year_id}, not the requested fiscal year {$fiscalYearId}."
                );
            }

            $entry->forceFill(['is_migration' => true])->save();

            $posted = $this->post->handle((int) $entry->getKey(), $actor);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Accounting',
                auditableType: JournalEntry::class,
                auditableId: (int) $posted->getKey(),
                after: [
                    'kind' => 'opening_auxiliary_import',
                    'as_of' => $asOfDate,
                    'fiscal_year_id' => $fiscalYearId,
                    'suspense_account' => $suspenseAccountCode,
                    'per_account' => $validation['per_account'],
                    'line_count' => count($lines),
                ],
                actor: $actor,
            );

            return $posted;
        });
    }
}
