<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\Books\BuildBalanceGenerale;
use App\Modules\Accounting\Actions\Books\BuildGrandLivre;
use App\Modules\Accounting\Actions\Books\BuildLivreJournal;
use App\Modules\Accounting\Actions\Books\GenerateStatutoryBook;
use App\Modules\Accounting\Domain\StatutoryBookType;
use App\Modules\Accounting\Models\StatutoryBook;
use App\Modules\Accounting\Domain\AccountingPeriodStatus;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * docs/specs/02-accounting.md §14 - the four books of AUDCIF Art. 19.
 *
 * The invariant these tests exist to protect is L13: a book reads through
 * `postedLedger()`, which is `posted` PLUS `reversed`. Filtering to `posted`
 * alone drops the original half of a reversal pair while keeping the
 * reversal, which silently flips the sign of the transaction and still
 * balances - so no other assertion catches it.
 */

/**
 * A fiscal year holding one posted entry, one reversed entry and one draft,
 * each with a balanced debit/credit pair.
 *
 * @return array{0: object, 1: JournalEntry, 2: JournalEntry, 3: JournalEntry}
 */
function bookFixture(): array
{
    (new \Database\Seeders\RolePermissionSeeder())->run();

    $user = User::factory()->create();
    $user->assignRole(Role::SuperAdmin->value);
    Auth::setUser($user);

    /** @var \Database\Factories\JournalEntryFactory $factory */
    $factory = JournalEntry::factory();
    $calendar = $factory->buildCalendar(Carbon::createFromDate(2035, 3, 15));

    $debitAccount = ChartOfAccount::factory()->create();
    $creditAccount = ChartOfAccount::factory()->create();

    // Trigger L6/C3 requires accounting_period_id to match the period
    // derived from the entry's own date, so each month needs its own period
    // inside the single fiscal year this fixture works in.
    $periodFor = static function (string $date) use ($calendar): int {
        $month = Carbon::parse($date)->startOfMonth();

        $existing = DB::table('accounting_periods')
            ->where('fiscal_year_id', $calendar['fiscal_year_id'])
            ->whereDate('period_month', $month->toDateString())
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) AccountingPeriod::factory()->create([
            'fiscal_year_id' => $calendar['fiscal_year_id'],
            'period_month' => $month->toDateString(),
            'starts_on' => $month->toDateString(),
            'ends_on' => $month->copy()->endOfMonth()->toDateString(),
            'status' => AccountingPeriodStatus::Open,
        ])->getKey();
    };

    // The entry is always created as a DRAFT and its lines added while it is
    // still one: trigger L3 refuses to insert a line into a posted or
    // reversed entry, which is exactly the invariant it exists to protect.
    // The status is promoted afterwards, the way real posting does it.
    $make = static function (string $date, string $piece, string $status) use ($calendar, $debitAccount, $creditAccount, $periodFor): JournalEntry {
        $entry = JournalEntry::factory()->create([
            'date' => $date,
            'value_date' => $date,
            'piece_no' => $piece,
            'status' => JournalEntry::STATUS_DRAFT,
            'fiscal_year_id' => $calendar['fiscal_year_id'],
            'accounting_period_id' => $periodFor($date),
            'academic_year_id' => $calendar['academic_year_id'],
            'total_debit' => 100000,
            'total_credit' => 100000,
        ]);

        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry->getKey(),
            'sequence' => 1,
            'account_id' => $debitAccount->getKey(),
            'debit' => 100000,
            'credit' => 0,
        ]);

        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry->getKey(),
            'sequence' => 2,
            'account_id' => $creditAccount->getKey(),
            'debit' => 0,
            'credit' => 100000,
        ]);

        if ($status !== JournalEntry::STATUS_DRAFT) {
            $entry->forceFill(['status' => $status])->save();
        }

        return $entry->refresh();
    };

    $posted = $make('2035-03-01', 'OD/2035/000001', JournalEntry::STATUS_POSTED);
    $reversed = $make('2035-04-01', 'OD/2035/000002', JournalEntry::STATUS_REVERSED);
    $draft = $make('2035-05-01', 'OD/2035/000003', JournalEntry::STATUS_DRAFT);

    $fiscalYear = (object) ['id' => $calendar['fiscal_year_id']];

    return [$fiscalYear, $posted, $reversed, $draft];
}

it('lists posted and reversed entries in chronological order, excluding drafts', function (): void {
    [$fiscalYear, $posted, $reversed, $draft] = bookFixture();

    $rows = app(BuildLivreJournal::class)->handle(
        (int) $fiscalYear->id,
        '2035-01-01',
        '2035-12-31',
    );

    $pieces = array_values(array_unique(array_column($rows, 'piece_no')));

    expect($pieces)->toContain($posted->piece_no)
        ->and($pieces)->toContain($reversed->piece_no)
        ->and($pieces)->not->toContain($draft->piece_no)
        ->and($pieces)->toBe([$posted->piece_no, $reversed->piece_no]);
});

it('balances the balance generale: total debit equals total credit', function (): void {
    [$fiscalYear] = bookFixture();

    $result = app(BuildBalanceGenerale::class)
        ->handle((int) $fiscalYear->id, '2035-01-01', '2035-12-31');

    expect($result['totals']['closing_debit'])->toBe($result['totals']['closing_credit'])
        ->and($result['totals']['movement_debit'])->toBe($result['totals']['movement_credit'])
        ->and($result['totals']['movement_debit'])->toBeGreaterThan(0);
});

it('supersedes rather than replaces when a book is regenerated', function (): void {
    [$fiscalYear] = bookFixture();

    $action = app(GenerateStatutoryBook::class);
    $type = StatutoryBookType::LivreJournal;

    $first = $action->handle($type, (int) $fiscalYear->id, '2035-01-01', '2035-12-31');
    $second = $action->handle($type, (int) $fiscalYear->id, '2035-01-01', '2035-12-31');

    expect($second->getKey())->not->toBe($first->getKey())
        ->and($second->supersedes_book_id)->toBe($first->getKey())
        // The superseded book is still there, untouched. That is the whole
        // point: a regenerated book supersedes, it never replaces.
        ->and(StatutoryBook::find($first->getKey()))->not->toBeNull()
        ->and($first->sha256)->toHaveLength(64)
        ->and($first->line_count)->toBeGreaterThan(0);
});

it("generates the livre d'inventaire, transcribing the other statements plus the physical inventory", function (): void {
    [$fiscalYear] = bookFixture();

    $book = app(GenerateStatutoryBook::class)->handle(
        StatutoryBookType::LivreInventaire,
        (int) $fiscalYear->id,
        '2035-01-01',
        '2035-12-31',
    );

    // bookFixture()'s accounts live under the class-9 (off-balance) branch
    // - the ONE part of the tree a factory can extend without colliding
    // with seeded statutory data (see ChartOfAccountFactory's own docblock)
    // - so the bilan/resultat/flux/stock/assets sections all legitimately
    // see zero lines here; that is the excluded-account path being
    // exercised correctly, not a bug. What this test protects is that
    // handle() completes the whole LivreInventaire pipeline (all five
    // sub-Actions, the dedicated Blade view, hashing, storage) without
    // throwing and writes a well-formed StatutoryBook row.
    expect($book->book_type)->toBe(StatutoryBookType::LivreInventaire)
        ->and($book->sha256)->toHaveLength(64)
        ->and($book->entry_count)->toBe(4)
        ->and($book->file_path)->toContain('livre_inventaire');
});

it('carries a running balance per account in the grand livre', function (): void {
    [$fiscalYear] = bookFixture();

    $accounts = app(BuildGrandLivre::class)
        ->handle((int) $fiscalYear->id, '2035-01-01', '2035-12-31');

    expect($accounts)->not->toBeEmpty();

    foreach ($accounts as $account) {
        $running = $account['opening_balance'];

        foreach ($account['movements'] as $movement) {
            $running += $movement['debit'] - $movement['credit'];
            expect($movement['running_balance'])->toBe($running);
        }

        expect($account['closing_balance'])->toBe($running);
    }
});
