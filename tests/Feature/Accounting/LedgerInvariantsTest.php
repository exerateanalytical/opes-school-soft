<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\DraftJournalEntry;
use App\Modules\Accounting\Actions\PostJournalEntry;
use App\Modules\Accounting\Domain\AccountingPeriodStatus;
use App\Modules\Accounting\Domain\FiscalYearStatus;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalEntry;
use App\Support\Sequence\SequenceAllocator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

if (! function_exists('fixedDate')) {
    // Carbon::create() is typed to allow a null return for an invalid
    // calendar date, even though every call site in this file passes a
    // literal date that can never actually be one. Carbon::parse() of a
    // formatted string has no such nullable escape hatch.
    function fixedDate(int $year, int $month, int $day): Carbon
    {
        return Carbon::parse(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }
}

/**
 * These tests deliberately write with DB::table() / DB::statement() - raw
 * SQL, never Eloquent - because §4.3's whole point is that L1/L3/L4/L6 are
 * proven at the database, not merely by an Action that happens to also
 * validate in PHP. Going through Eloquent (or worse, through the Action)
 * would prove the PHP guard, not the trigger it is supposed to back stop.
 */
it('L1: a CHECK constraint rejects a line where debit and credit are both zero', function () {
    $fixture = ledgerFixture();
    $entry = draftEntryDirect($fixture);
    $account = ChartOfAccount::factory()->create();

    expect(fn () => DB::table('journal_entry_lines')->insert([
        'journal_entry_id' => $entry->id,
        'sequence' => 1,
        'account_id' => $account->id,
        'label' => 'zero/zero',
        'debit' => 0,
        'credit' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('L1: a CHECK constraint rejects a line where debit and credit are both non-zero', function () {
    $fixture = ledgerFixture();
    $entry = draftEntryDirect($fixture);
    $account = ChartOfAccount::factory()->create();

    expect(fn () => DB::table('journal_entry_lines')->insert([
        'journal_entry_id' => $entry->id,
        'sequence' => 1,
        'account_id' => $account->id,
        'label' => 'both sides',
        'debit' => 500,
        'credit' => 500,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('L3: a raw SQL insert into the lines of a posted entry is blocked by the BEFORE INSERT trigger', function () {
    [$entry, $account] = postedEntryWithOneLine();

    expect(fn () => DB::table('journal_entry_lines')->insert([
        'journal_entry_id' => $entry->id,
        'sequence' => 99,
        'account_id' => $account->id,
        'label' => 'smuggled in after posting',
        'debit' => 1,
        'credit' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'L3');
});

it('L3: a raw SQL update of a line on a posted entry is blocked by the BEFORE UPDATE trigger', function () {
    [$entry] = postedEntryWithOneLine();
    $lineId = (int) DB::table('journal_entry_lines')->where('journal_entry_id', $entry->id)->value('id');

    expect(fn () => DB::table('journal_entry_lines')->where('id', $lineId)->update(['label' => 'rewritten']))
        ->toThrow(QueryException::class, 'L3');
});

it('L3: a raw SQL delete of a line on a posted entry is blocked by the BEFORE DELETE trigger', function () {
    [$entry] = postedEntryWithOneLine();
    $lineId = (int) DB::table('journal_entry_lines')->where('journal_entry_id', $entry->id)->value('id');

    expect(fn () => DB::table('journal_entry_lines')->where('id', $lineId)->delete())
        ->toThrow(QueryException::class, 'L3');
});

it('L3: lines of a still-draft entry are unaffected by the trigger', function () {
    $fixture = ledgerFixture();
    $entry = draftEntryDirect($fixture);
    $account = ChartOfAccount::factory()->create();

    DB::table('journal_entry_lines')->insert([
        'journal_entry_id' => $entry->id,
        'sequence' => 1,
        'account_id' => $account->id,
        'label' => 'fine while draft',
        'debit' => 1,
        'credit' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('journal_entry_lines')->where('journal_entry_id', $entry->id)->count())->toBe(1);
});

it('L4: a raw SQL update of date on a posted entry is blocked by the BEFORE UPDATE trigger', function () {
    [$entry] = postedEntryWithOneLine();

    expect(fn () => DB::table('journal_entries')->where('id', $entry->id)->update(['date' => '2099-01-01']))
        ->toThrow(QueryException::class, 'L4');
});

it('L4: a raw SQL update of piece_no on a posted entry is blocked', function () {
    [$entry] = postedEntryWithOneLine();

    expect(fn () => DB::table('journal_entries')->where('id', $entry->id)->update(['piece_no' => 'FORGED/0001']))
        ->toThrow(QueryException::class, 'L4');
});

it('L4: a raw SQL update of journal_id on a posted entry is blocked', function () {
    [$entry] = postedEntryWithOneLine();
    $otherJournal = Journal::factory()->create();

    expect(fn () => DB::table('journal_entries')->where('id', $entry->id)->update(['journal_id' => $otherJournal->id]))
        ->toThrow(QueryException::class, 'L4');
});

it('L4: a raw SQL update of a non-immutable column (label) on a posted entry is allowed', function () {
    [$entry] = postedEntryWithOneLine();

    DB::table('journal_entries')->where('id', $entry->id)->update(['label' => 'relabelled, not a frozen column']);

    expect(DB::table('journal_entries')->where('id', $entry->id)->value('label'))
        ->toBe('relabelled, not a frozen column');
});

it('L6/C3: a raw SQL insert whose accounting_period_id does not match date is blocked by the BEFORE INSERT trigger', function () {
    $dateA = fixedDate(2031, 2, 10);
    $dateB = fixedDate(2031, 8, 10);
    $fixtureA = ledgerFixture($dateA);
    $fixtureB = ledgerFixture($dateB);

    expect(fn () => DB::table('journal_entries')->insert([
        'journal_id' => $fixtureA['journal_id'],
        'date' => $dateA->toDateString(),
        'value_date' => $dateA->toDateString(),
        // Deliberately the WRONG period - it belongs to fixture B's window.
        'accounting_period_id' => $fixtureB['accounting_period_id'],
        'fiscal_year_id' => $fixtureA['fiscal_year_id'],
        'academic_year_id' => $fixtureA['academic_year_id'],
        'label' => 'mismatched period',
        'status' => 'draft',
        'total_debit' => 0,
        'total_credit' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'L6');
});

it('L6/C3: a raw SQL insert whose fiscal_year_id does not match date is blocked', function () {
    $dateA = fixedDate(2032, 2, 10);
    $dateB = fixedDate(2033, 8, 10);
    $fixtureA = ledgerFixture($dateA);
    $fixtureB = ledgerFixture($dateB);

    expect(fn () => DB::table('journal_entries')->insert([
        'journal_id' => $fixtureA['journal_id'],
        'date' => $dateA->toDateString(),
        'value_date' => $dateA->toDateString(),
        'accounting_period_id' => $fixtureA['accounting_period_id'],
        // Deliberately the WRONG fiscal year.
        'fiscal_year_id' => $fixtureB['fiscal_year_id'],
        'academic_year_id' => $fixtureA['academic_year_id'],
        'label' => 'mismatched fiscal year',
        'status' => 'draft',
        'total_debit' => 0,
        'total_credit' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'L6');
});

it('L7: a rolled-back post consumes no piece number, proven end-to-end through PostJournalEntry', function () {
    $user = ledgerUserAs();
    actingAs($user);
    $fixture = ledgerFixture();

    $entry = app(DraftJournalEntry::class)->handle(
        $fixture['journal_id'], $fixture['date'], null, 'To be rolled back', null, twoLines(), $user->toAuditActor(),
    );

    $series = "journal_entry_piece.{$entry->journal_id}.{$entry->fiscal_year_id}";
    $allocator = app(SequenceAllocator::class);

    expect($allocator->peek($series))->toBe(1);

    // Post inside an OUTER transaction that we force to fail after
    // PostJournalEntry's own (nested/savepointed) transaction commits.
    // Laravel's nested DB::transaction() uses a savepoint, so the outer
    // rollback undoes the post AND the sequence allocation together.
    try {
        DB::transaction(function () use ($entry, $user) {
            app(PostJournalEntry::class)->handle((int) $entry->getKey(), $user->toAuditActor());

            throw new RuntimeException('forced rollback after posting, before the outer commit');
        });
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('forced rollback');
    }

    expect($entry->refresh()->status)->toBe(JournalEntry::STATUS_DRAFT);
    expect($entry->refresh()->piece_no)->toBeNull();

    // The number the failed attempt would have used was never consumed.
    expect($allocator->peek($series))->toBe(1);

    // A real post now gets exactly that number - proof there is no gap.
    $posted = app(PostJournalEntry::class)->handle((int) $entry->getKey(), $user->toAuditActor());
    expect($posted->piece_no)->toContain('000001');
});

// ---------------------------------------------------------------------
// Fixtures shared with JournalEntryTest.php's helpers (Pest test files
// share one global function namespace within a run, but each file may
// also run alone, so the small building blocks below are self-contained).
// ---------------------------------------------------------------------

if (! function_exists('ledgerFixture')) {
    /**
     * @return array{journal_id: int, date: string, accounting_period_id: int, fiscal_year_id: int, academic_year_id: int}
     */
    function ledgerFixture(?Carbon $date = null): array
    {
        $date = $date instanceof Carbon ? $date : fixedDate(2027, 3, 15);
        $year = (int) $date->format('Y');

        $fiscalYear = FiscalYear::factory()->create([
            'code' => strtoupper(Illuminate\Support\Str::random(8)),
            'starts_on' => "{$year}-01-01",
            'ends_on' => "{$year}-12-31",
            'status' => FiscalYearStatus::Open,
        ]);

        $period = AccountingPeriod::factory()->create([
            'fiscal_year_id' => $fiscalYear->getKey(),
            'period_month' => $date->copy()->startOfMonth()->toDateString(),
            'starts_on' => $date->copy()->startOfMonth()->toDateString(),
            'ends_on' => $date->copy()->endOfMonth()->toDateString(),
            'status' => AccountingPeriodStatus::Open,
        ]);

        $academicYearId = DB::table('academic_years')->insertGetId([
            'code' => 'AY-'.$year.'-'.uniqid(),
            'name' => 'Academic year covering '.$date->toDateString(),
            'starts_on' => "{$year}-01-01",
            'ends_on' => "{$year}-12-31",
            'is_current' => false,
            'status' => 'planned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $journal = Journal::factory()->create();

        return [
            'journal_id' => (int) $journal->getKey(),
            'date' => $date->toDateString(),
            'accounting_period_id' => (int) $period->getKey(),
            'fiscal_year_id' => (int) $fiscalYear->getKey(),
            'academic_year_id' => (int) $academicYearId,
        ];
    }
}

if (! function_exists('ledgerUserAs')) {
    function ledgerUserAs(bool $withPermission = true): App\Modules\Identity\Models\User
    {
        app()->make(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        Spatie\Permission\Models\Permission::findOrCreate(App\Modules\Identity\Domain\Permission::LedgerPost->value, 'web');

        $user = App\Modules\Identity\Models\User::factory()->create();

        if ($withPermission) {
            $user->givePermissionTo(App\Modules\Identity\Domain\Permission::LedgerPost->value);
        }

        return $user->fresh() ?? $user;
    }
}

if (! function_exists('twoLines')) {
    /**
     * @return array<int, array{account_id: int, label: string, debit: int, credit: int}>
     */
    function twoLines(int $amount = 100_000): array
    {
        return [
            ['account_id' => (int) ChartOfAccount::factory()->create()->getKey(), 'label' => 'Debit side', 'debit' => $amount, 'credit' => 0],
            ['account_id' => (int) ChartOfAccount::factory()->create()->getKey(), 'label' => 'Credit side', 'debit' => 0, 'credit' => $amount],
        ];
    }
}

/**
 * Inserts a minimal, trigger-satisfying draft JournalEntry with raw SQL -
 * deliberately bypassing DraftJournalEntry so tests that need a plain draft
 * row to attack with further raw SQL are not coupled to the Action's own
 * behaviour.
 *
 * @param  array{journal_id: int, date: string, accounting_period_id: int, fiscal_year_id: int, academic_year_id: int}  $fixture
 */
function draftEntryDirect(array $fixture): JournalEntry
{
    $id = DB::table('journal_entries')->insertGetId([
        'journal_id' => $fixture['journal_id'],
        'date' => $fixture['date'],
        'value_date' => $fixture['date'],
        'accounting_period_id' => $fixture['accounting_period_id'],
        'fiscal_year_id' => $fixture['fiscal_year_id'],
        'academic_year_id' => $fixture['academic_year_id'],
        'label' => 'direct draft',
        'status' => 'draft',
        'total_debit' => 0,
        'total_credit' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return JournalEntry::query()->findOrFail($id);
}

/**
 * @return array{0: JournalEntry, 1: ChartOfAccount}
 */
function postedEntryWithOneLine(): array
{
    $user = ledgerUserAs();
    actingAs($user);
    $fixture = ledgerFixture();

    $entry = app(DraftJournalEntry::class)->handle(
        $fixture['journal_id'], $fixture['date'], null, 'Will be posted for a trigger test', null, twoLines(), $user->toAuditActor(),
    );

    $posted = app(PostJournalEntry::class)->handle((int) $entry->getKey(), $user->toAuditActor());
    $account = ChartOfAccount::factory()->create();

    return [$posted, $account];
}
