<?php

declare(strict_types=1);

use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Carbon;

if (! function_exists('assertNotNull')) {
    /**
     * A real null-narrowing assertion, usable across every Accounting test
     * file. `expect($x)->toBeInstanceOf(...)` runs a genuine runtime check
     * but returns a fresh Expectation object rather than narrowing $x
     * itself, so PHPStan still sees the original variable as nullable on
     * every line after it - this throws (or, via the generic template,
     * hands the caller back a statically non-null value PHPStan actually
     * trusts).
     *
     * @template T
     *
     * @param  T|null  $value
     * @return T
     */
    function assertNotNull(mixed $value, string $message = 'Expected a non-null value.'): mixed
    {
        if ($value === null) {
            throw new RuntimeException($message);
        }

        return $value;
    }
}

if (! function_exists('postDirectEntry')) {
    /**
     * Posts a self-consistent JournalEntry directly against the schema:
     * create it `draft` (so the L3 line-lock trigger permits inserting
     * lines), insert the balanced lines, then flip the entry to `posted`
     * with a piece_no - the same order docs/specs/02-accounting.md's own
     * triggers require, since D3's `PostJournalEntry` Action does not exist
     * yet for these tests to call.
     *
     * @param  list<array{account_id: int, debit: int, credit: int}>  $lines
     */
    function postDirectEntry(
        int $fiscalYearId,
        int $accountingPeriodId,
        int $academicYearId,
        string $date,
        string $pieceNo,
        array $lines,
    ): JournalEntry {
        $journal = Journal::factory()->create();
        $totalDebit = array_sum(array_column($lines, 'debit'));
        $totalCredit = array_sum(array_column($lines, 'credit'));

        /** @var JournalEntry $entry */
        $entry = JournalEntry::query()->create([
            'journal_id' => $journal->id,
            'piece_no' => null,
            'date' => $date,
            'value_date' => $date,
            'accounting_period_id' => $accountingPeriodId,
            'fiscal_year_id' => $fiscalYearId,
            'academic_year_id' => $academicYearId,
            'label' => 'Test entry '.$pieceNo,
            'status' => JournalEntry::STATUS_DRAFT,
            'total_debit' => 0,
            'total_credit' => 0,
        ]);

        foreach ($lines as $sequence => $line) {
            JournalEntryLine::query()->create([
                'journal_entry_id' => $entry->id,
                'sequence' => $sequence + 1,
                'account_id' => $line['account_id'],
                'label' => 'Line',
                'debit' => $line['debit'],
                'credit' => $line['credit'],
            ]);
        }

        $entry->forceFill([
            'status' => JournalEntry::STATUS_POSTED,
            'piece_no' => $pieceNo,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
        ])->save();

        return $entry->fresh() ?? $entry;
    }
}

if (! function_exists('ledgerCalendar')) {
    /**
     * @return array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}
     */
    function ledgerCalendar(string $date = '2031-03-15'): array
    {
        return (new Database\Factories\JournalEntryFactory())->buildCalendar(Carbon::parse($date));
    }
}

if (! function_exists('ledgerUser')) {
    function ledgerUser(Role $role = Role::Accountant): User
    {
        (new Database\Seeders\RolePermissionSeeder())->run();
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user->fresh() ?? $user;
    }
}

