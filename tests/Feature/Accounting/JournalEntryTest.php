<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\AddJournalEntryLine;
use App\Modules\Accounting\Actions\DraftJournalEntry;
use App\Modules\Accounting\Actions\PostJournalEntry;
use App\Modules\Accounting\Domain\AccountingPeriodStatus;
use App\Modules\Accounting\Domain\FiscalYearStatus;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

if (! function_exists('ledgerUserAs')) {
    // BYTE-IDENTICAL in every file that defines it (JournalEntryTest,
    // LedgerInvariantsTest, LetteringTest, ReversalTest): the guard means the
    // FIRST loaded copy serves the whole process, so a divergent copy is a
    // load-order-dependent bug. FQCNs on purpose - the body must not depend
    // on any single file's `use` table.
    function ledgerUserAs(bool $withPermission = true): \App\Modules\Identity\Models\User
    {
        app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        \Spatie\Permission\Models\Permission::findOrCreate('ledger.post', 'web');
        \Spatie\Permission\Models\Permission::findOrCreate('ledger.view', 'web');

        $user = \App\Modules\Identity\Models\User::factory()->create();

        if ($withPermission) {
            $user->givePermissionTo('ledger.post', 'ledger.view');
        }

        return $user->fresh() ?? $user;
    }
}

// Builds a fiscal year / accounting period / academic year all covering
// `$date`, plus a journal - everything DraftJournalEntry needs to derive
// L6's three FKs without the caller ever supplying them.
//
// Identical to the copy in LedgerInvariantsTest.php on purpose: Pest loads
// every test file's global functions into one process, so the
// `function_exists` guard means whichever file runs first wins - the two
// copies must never drift apart.
if (! function_exists('ledgerFixture')) {
    /**
     * @return array{journal_id: int, date: string, accounting_period_id: int, fiscal_year_id: int, academic_year_id: int}
     */
    function ledgerFixture(?Carbon $date = null): array
    {
        // Carbon::create() is typed to allow a null return for an invalid
        // calendar date, even though 2027-03-15 can never actually be one -
        // Carbon::parse() of a literal string has no such nullable escape
        // hatch. (This guarded helper is BYTE-IDENTICAL in JournalEntryTest
        // and LedgerInvariantsTest - keep both copies in sync.)
        $date = $date instanceof Carbon ? $date : Carbon::parse('2027-03-15');
        $year = (int) $date->format('Y');

        $fiscalYear = FiscalYear::factory()->create([
            'code' => strtoupper(\Illuminate\Support\Str::random(8)),
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

it('drafts a journal entry with no piece_no and derives the calendar from date alone', function () {
    $user = ledgerUserAs();
    actingAs($user);
    $fixture = ledgerFixture();

    $entry = app(DraftJournalEntry::class)->handle(
        journalId: $fixture['journal_id'],
        date: $fixture['date'],
        valueDate: null,
        label: 'Test draft entry',
        reference: null,
        lines: twoLines(),
        actor: $user->toAuditActor(),
    );

    expect($entry->status)->toBe(JournalEntry::STATUS_DRAFT);
    expect($entry->piece_no)->toBeNull();
    expect($entry->lines)->toHaveCount(2);
    expect($entry->total_debit)->toBe(0);
    expect($entry->total_credit)->toBe(0);
    expect(AuditLog::query()->where('module', 'Accounting')->where('action', 'created')->count())->toBe(1);
});

it('L6: DraftJournalEntry has no parameter through which a caller can supply the derived FKs', function () {
    $ref = new ReflectionMethod(DraftJournalEntry::class, 'handle');
    $names = array_map(fn (ReflectionParameter $p): string => $p->getName(), $ref->getParameters());

    expect($names)->not->toContain('accountingPeriodId');
    expect($names)->not->toContain('fiscalYearId');
    expect($names)->not->toContain('academicYearId');
});

it('rejects a 0/0 line at the Action level before it ever reaches the database (L1)', function () {
    $user = ledgerUserAs();
    actingAs($user);
    $fixture = ledgerFixture();

    expect(fn () => app(DraftJournalEntry::class)->handle(
        journalId: $fixture['journal_id'],
        date: $fixture['date'],
        valueDate: null,
        label: 'Bad entry',
        reference: null,
        lines: [['account_id' => (int) ChartOfAccount::factory()->create()->getKey(), 'label' => 'zero', 'debit' => 0, 'credit' => 0]],
        actor: $user->toAuditActor(),
    ))->toThrow(DomainException::class, 'L1');
});

it('appends a line to a draft with an incrementing sequence', function () {
    $user = ledgerUserAs();
    actingAs($user);
    $fixture = ledgerFixture();

    $entry = app(DraftJournalEntry::class)->handle(
        $fixture['journal_id'], $fixture['date'], null, 'Growing entry', null, twoLines(), $user->toAuditActor(),
    );

    $line = app(AddJournalEntryLine::class)->handle(
        journalEntryId: (int) $entry->getKey(),
        accountId: (int) ChartOfAccount::factory()->create()->getKey(),
        label: 'Third line',
        debit: 0,
        credit: 50_000,
        actor: $user->toAuditActor(),
    );

    expect($line->sequence)->toBe(3);
    expect($entry->refresh()->lines)->toHaveCount(3);
});

it('posts a balanced draft: allocates piece_no, stamps totals, flips status', function () {
    $user = ledgerUserAs();
    actingAs($user);
    $fixture = ledgerFixture();

    $entry = app(DraftJournalEntry::class)->handle(
        $fixture['journal_id'], $fixture['date'], null, 'Balanced entry', null, twoLines(100_000), $user->toAuditActor(),
    );

    $posted = app(PostJournalEntry::class)->handle((int) $entry->getKey(), $user->toAuditActor());

    expect($posted->status)->toBe(JournalEntry::STATUS_POSTED);
    expect($posted->piece_no)->not->toBeNull();
    expect($posted->total_debit)->toBe(100_000);
    expect($posted->total_credit)->toBe(100_000);
    expect($posted->posted_at)->not->toBeNull();
    expect($posted->posted_by)->toBe($user->id);
});

it('L2: refuses to post an unbalanced entry, asserted under lock before commit', function () {
    $user = ledgerUserAs();
    actingAs($user);
    $fixture = ledgerFixture();

    $entry = app(DraftJournalEntry::class)->handle(
        $fixture['journal_id'], $fixture['date'], null, 'Unbalanced', null, [
            ['account_id' => (int) ChartOfAccount::factory()->create()->getKey(), 'label' => 'D', 'debit' => 100_000, 'credit' => 0],
            ['account_id' => (int) ChartOfAccount::factory()->create()->getKey(), 'label' => 'C', 'debit' => 0, 'credit' => 90_000],
        ], $user->toAuditActor(),
    );

    expect(fn () => app(PostJournalEntry::class)->handle((int) $entry->getKey(), $user->toAuditActor()))
        ->toThrow(DomainException::class, 'L2');

    expect($entry->refresh()->status)->toBe(JournalEntry::STATUS_DRAFT);
});

it('L3: refuses to add a line to a posted entry even at the Action level', function () {
    $user = ledgerUserAs();
    actingAs($user);
    $fixture = ledgerFixture();

    $entry = app(DraftJournalEntry::class)->handle(
        $fixture['journal_id'], $fixture['date'], null, 'Will be posted', null, twoLines(), $user->toAuditActor(),
    );
    app(PostJournalEntry::class)->handle((int) $entry->getKey(), $user->toAuditActor());

    expect(fn () => app(AddJournalEntryLine::class)->handle(
        journalEntryId: (int) $entry->getKey(),
        accountId: (int) ChartOfAccount::factory()->create()->getKey(),
        label: 'too late',
        debit: 1,
        credit: 0,
        actor: $user->toAuditActor(),
    ))->toThrow(DomainException::class, 'L3');
});

it('L5: refuses to post into a hard-locked period', function () {
    $user = ledgerUserAs();
    actingAs($user);
    $date = Carbon::create(2028, 4, 10);
    $fixture = ledgerFixture($date);

    $entry = app(DraftJournalEntry::class)->handle(
        $fixture['journal_id'], $fixture['date'], null, 'Locked period', null, twoLines(), $user->toAuditActor(),
    );

    AccountingPeriod::query()->where('id', $entry->accounting_period_id)->update(['status' => AccountingPeriodStatus::HardLocked->value]);

    expect(fn () => app(PostJournalEntry::class)->handle((int) $entry->getKey(), $user->toAuditActor()))
        ->toThrow(DomainException::class);

    expect($entry->refresh()->status)->toBe(JournalEntry::STATUS_DRAFT);
});

it('L7: gapless piece_no per (journal, fiscal_year), allocated only at posting - never at draft time', function () {
    $user = ledgerUserAs();
    actingAs($user);
    $fixture = ledgerFixture();

    $draft1 = app(DraftJournalEntry::class)->handle($fixture['journal_id'], $fixture['date'], null, 'First', null, twoLines(), $user->toAuditActor());
    $draft2 = app(DraftJournalEntry::class)->handle($fixture['journal_id'], $fixture['date'], null, 'Second', null, twoLines(), $user->toAuditActor());

    expect($draft1->piece_no)->toBeNull();
    expect($draft2->piece_no)->toBeNull();

    $posted1 = app(PostJournalEntry::class)->handle((int) $draft1->getKey(), $user->toAuditActor());
    $posted2 = app(PostJournalEntry::class)->handle((int) $draft2->getKey(), $user->toAuditActor());

    expect($posted1->piece_no)->not->toBe($posted2->piece_no);
    expect($posted1->piece_no)->toContain('000001');
    expect($posted2->piece_no)->toContain('000002');
});

it('L13: the postedLedger scope includes posted AND reversed, excludes draft only', function () {
    $fixture = ledgerFixture();

    $draft = JournalEntry::factory()->create([
        'journal_id' => $fixture['journal_id'],
        'status' => JournalEntry::STATUS_DRAFT,
    ]);
    $posted = JournalEntry::factory()->create([
        'journal_id' => $fixture['journal_id'],
        'status' => JournalEntry::STATUS_POSTED,
        'piece_no' => 'X/2027/000001',
    ]);
    $reversed = JournalEntry::factory()->create([
        'journal_id' => $fixture['journal_id'],
        'status' => JournalEntry::STATUS_REVERSED,
        'piece_no' => 'X/2027/000002',
    ]);

    $ids = JournalEntry::query()->postedLedger()->pluck('id')->all();

    expect($ids)->toContain($posted->id, $reversed->id);
    expect($ids)->not->toContain($draft->id);
});

it('denies drafting to a user without ledger.post', function () {
    $user = ledgerUserAs(withPermission: false);
    actingAs($user);
    $fixture = ledgerFixture();

    expect(fn () => app(DraftJournalEntry::class)->handle(
        $fixture['journal_id'], $fixture['date'], null, 'Denied', null, twoLines(), $user->toAuditActor(),
    ))->toThrow(AuthorizationException::class);

    expect(JournalEntry::query()->count())->toBe(0);
});

it('denies posting to a user without ledger.post', function () {
    $owner = ledgerUserAs();
    actingAs($owner);
    $fixture = ledgerFixture();
    $entry = app(DraftJournalEntry::class)->handle(
        $fixture['journal_id'], $fixture['date'], null, 'Owner draft', null, twoLines(), $owner->toAuditActor(),
    );

    $stranger = ledgerUserAs(withPermission: false);
    actingAs($stranger);

    expect(fn () => app(PostJournalEntry::class)->handle((int) $entry->getKey(), $stranger->toAuditActor()))
        ->toThrow(AuthorizationException::class);
});

it('L12: reverses_entry_id is unique at the database', function () {
    $fixture = ledgerFixture();

    $target = JournalEntry::factory()->create([
        'journal_id' => $fixture['journal_id'],
        'status' => JournalEntry::STATUS_POSTED,
        'piece_no' => 'X/2027/000010',
    ]);

    JournalEntry::factory()->create([
        'journal_id' => $fixture['journal_id'],
        'status' => JournalEntry::STATUS_POSTED,
        'piece_no' => 'X/2027/000011',
        'reverses_entry_id' => $target->id,
        'reversal_reason' => 'first reversal claiming the target',
    ]);

    expect(fn () => JournalEntry::factory()->create([
        'journal_id' => $fixture['journal_id'],
        'status' => JournalEntry::STATUS_POSTED,
        'piece_no' => 'X/2027/000012',
        'reverses_entry_id' => $target->id,
        'reversal_reason' => 'second reversal - forbidden by uq_je_reverses',
    ]))->toThrow(QueryException::class);
});

it('L12: is_reversal is a generated column that follows reverses_entry_id', function () {
    $fixture = ledgerFixture();

    $target = JournalEntry::factory()->create([
        'journal_id' => $fixture['journal_id'],
        'status' => JournalEntry::STATUS_POSTED,
        'piece_no' => 'X/2027/000020',
    ]);
    $reversal = JournalEntry::factory()->create([
        'journal_id' => $fixture['journal_id'],
        'status' => JournalEntry::STATUS_POSTED,
        'piece_no' => 'X/2027/000021',
        'reverses_entry_id' => $target->id,
        'reversal_reason' => 'because it was wrong',
    ]);

    expect((bool) $target->refresh()->is_reversal)->toBeFalse();
    expect((bool) $reversal->refresh()->is_reversal)->toBeTrue();
});
