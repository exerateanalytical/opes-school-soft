<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\ReverseJournalEntry;
use App\Modules\Accounting\Domain\FiscalYearStatus;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Support\Audit\Actor;
use App\Support\Clock\BusinessDate;
use App\Support\Sequence\SequenceAllocator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

if (! function_exists('ledgerUserAs')) {
    function ledgerUserAs(bool $withPermission = true): User
    {
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('ledger.post', 'web');
        Permission::findOrCreate('ledger.view', 'web');

        $user = User::factory()->create();

        if ($withPermission) {
            $user->givePermissionTo('ledger.post', 'ledger.view');
        }

        return $user->fresh() ?? $user;
    }
}

if (! function_exists('ledgerAcademicYearId')) {
    function ledgerAcademicYearId(Carbon $anchor): int
    {
        $startYear = $anchor->month >= 9 ? $anchor->year : $anchor->year - 1;
        $code = sprintf('%d-%d-%s', $startYear, $startYear + 1, str()->random(6));

        return (int) DB::table('academic_years')->insertGetId([
            'code' => $code,
            'name' => 'Test AY '.$code,
            'starts_on' => sprintf('%d-09-01', $startYear),
            'ends_on' => sprintf('%d-08-31', $startYear + 1),
            'is_current' => false,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('ledgerFiscalYear')) {
    function ledgerFiscalYear(int $year): FiscalYear
    {
        return FiscalYear::factory()->create([
            'code' => $year.strtoupper(str()->random(4)),
            'starts_on' => sprintf('%d-01-01', $year),
            'ends_on' => sprintf('%d-12-31', $year),
            'status' => FiscalYearStatus::Open,
        ]);
    }
}

if (! function_exists('ledgerPeriod')) {
    function ledgerPeriod(FiscalYear $fiscalYear, Carbon $anyDateInMonth, string $status = 'open'): AccountingPeriod
    {
        $month = $anyDateInMonth->copy();

        return AccountingPeriod::factory()->create([
            'fiscal_year_id' => $fiscalYear->id,
            'period_month' => $month->copy()->startOfMonth()->toDateString(),
            'starts_on' => $month->copy()->startOfMonth()->toDateString(),
            'ends_on' => $month->copy()->endOfMonth()->toDateString(),
            'status' => $status,
            'is_quarter_end' => false,
        ]);
    }
}

if (! function_exists('ledgerCollectiveAccount')) {
    function ledgerCollectiveAccount(): ChartOfAccount
    {
        return ChartOfAccount::factory()->create([
            'is_collective' => true,
            'requires_partner' => true,
            'allowed_partner_types' => ['student'],
            'is_lettrable' => true,
        ]);
    }
}

if (! function_exists('ledgerJournal')) {
    function ledgerJournal(): Journal
    {
        return Journal::factory()->create();
    }
}

if (! function_exists('ledgerPostEntry')) {
    /**
     * Builds a POSTED JournalEntry directly (bypassing the not-yet-landed
     * PostJournalEntry). Every column PostJournalEntry would derive is
     * computed here the same way, so the L1/L3/L4/L6/ck_je_* triggers this
     * agent does not own still accept the row.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    function ledgerPostEntry(
        Journal $journal,
        FiscalYear $fiscalYear,
        AccountingPeriod $period,
        int $academicYearId,
        string $date,
        array $lines,
        User $actor,
    ): JournalEntry {
        $series = sprintf('journal_entry_piece.%d.%d', $journal->id, $fiscalYear->id);
        $sequence = app(SequenceAllocator::class)->allocate($series);
        $pieceNo = sprintf('%s/%s/%06d', $journal->code, $fiscalYear->code, $sequence);

        $totalDebit = 0;
        $totalCredit = 0;
        foreach ($lines as $line) {
            $totalDebit += $line['debit'] ?? 0;
            $totalCredit += $line['credit'] ?? 0;
        }

        // L3's trigger correctly refuses a line insert once the parent is
        // posted (docs/specs/02-accounting.md 4.3) - so, same as
        // AccountingTestHelpers::postDirectEntry(), the entry is created
        // `draft`, the lines go in while that is still legal, and only then
        // does it flip to `posted`. Building it posted-first here was the bug,
        // not the trigger.
        $entry = JournalEntry::query()->create([
            'journal_id' => $journal->id,
            'piece_no' => null,
            'date' => $date,
            'value_date' => $date,
            'accounting_period_id' => $period->id,
            'fiscal_year_id' => $fiscalYear->id,
            'academic_year_id' => $academicYearId,
            'label' => 'Test entry',
            'status' => JournalEntry::STATUS_DRAFT,
            'total_debit' => 0,
            'total_credit' => 0,
            'created_by' => $actor->id,
        ]);

        foreach ($lines as $i => $line) {
            JournalEntryLine::query()->create(array_merge([
                'journal_entry_id' => $entry->id,
                'sequence' => $i + 1,
                'label' => 'Line '.($i + 1),
            ], $line));
        }

        $entry->forceFill([
            'status' => JournalEntry::STATUS_POSTED,
            'piece_no' => $pieceNo,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'posted_by' => $actor->id,
            'posted_at' => now(),
        ])->save();

        return $entry->fresh() ?? $entry;
    }
}

it('reverses a posted entry, flips every line, and links both directions', function () {
    $user = ledgerUserAs();
    actingAs($user);

    $today = Carbon::parse(BusinessDate::today());
    $academicYearId = ledgerAcademicYearId($today);
    $fiscalYear = ledgerFiscalYear($today->year);
    $period = ledgerPeriod($fiscalYear, $today, 'open');
    $journal = ledgerJournal();
    $debitAccount = ChartOfAccount::factory()->create();
    $creditAccount = ChartOfAccount::factory()->create();

    $original = ledgerPostEntry($journal, $fiscalYear, $period, $academicYearId, $today->toDateString(), [
        ['account_id' => $debitAccount->id, 'debit' => 15000, 'credit' => 0],
        ['account_id' => $creditAccount->id, 'debit' => 0, 'credit' => 15000],
    ], $user);

    $reversal = app(ReverseJournalEntry::class)->handle($original->id, 'Posted to the wrong account.', $user->toAuditActor());

    expect($reversal->status)->toBe(JournalEntry::STATUS_POSTED);
    expect($reversal->reverses_entry_id)->toBe($original->id);
    expect($reversal->reversal_reason)->toBe('Posted to the wrong account.');
    expect($reversal->total_debit)->toBe(15000);
    expect($reversal->total_credit)->toBe(15000);
    expect($reversal->piece_no)->not->toBeNull();

    $original->refresh();
    expect($original->status)->toBe(JournalEntry::STATUS_REVERSED);
    expect($original->reversed_by_entry_id)->toBe($reversal->id);

    $reversalLines = JournalEntryLine::query()
        ->where('journal_entry_id', $reversal->id)
        ->orderBy('sequence')
        ->get();

    expect($reversalLines)->toHaveCount(2);
    $firstReversalLine = assertNotNull($reversalLines->get(0));
    $secondReversalLine = assertNotNull($reversalLines->get(1));
    expect((int) $firstReversalLine->debit)->toBe(0);
    expect((int) $firstReversalLine->credit)->toBe(15000);
    expect((int) $secondReversalLine->debit)->toBe(15000);
    expect((int) $secondReversalLine->credit)->toBe(0);

    expect(AuditLog::query()->where('module', 'Accounting')->where('auditable_type', JournalEntry::class)->count())
        ->toBeGreaterThanOrEqual(2);
});

it('always lands the reversal in the earliest OPEN period as of today, never the original date - even when the original period is still open', function () {
    // §9.2 step 6 is unconditional: it never inherits the original date, even
    // when that date's own period is still open. Only the current business
    // date's earliest open period is used.
    $user = ledgerUserAs();
    actingAs($user);

    $today = Carbon::parse(BusinessDate::today());
    $academicYearId = ledgerAcademicYearId($today);
    $fiscalYear = ledgerFiscalYear($today->year);
    $currentPeriod = ledgerPeriod($fiscalYear, $today, 'open');
    $journal = ledgerJournal();
    $account1 = ChartOfAccount::factory()->create();
    $account2 = ChartOfAccount::factory()->create();

    $original = ledgerPostEntry($journal, $fiscalYear, $currentPeriod, $academicYearId, $today->toDateString(), [
        ['account_id' => $account1->id, 'debit' => 5000, 'credit' => 0],
        ['account_id' => $account2->id, 'debit' => 0, 'credit' => 5000],
    ], $user);

    $reversal = app(ReverseJournalEntry::class)->handle($original->id, 'Duplicate entry, correcting.', $user->toAuditActor());

    expect($reversal->accounting_period_id)->toBe($currentPeriod->id);
    expect($reversal->date->toDateString())->toBe($today->toDateString());
    expect($reversal->value_date->toDateString())->toBe($today->toDateString());
    expect($reversal->is_forward_posted)->toBeFalse();
});

it('forward-posts the reversal into the next open period when the current period is hard-locked, quoting §9.2 step 6 in this test as the contract', function () {
    // Build TWO months in the same fiscal year: the "current" month is
    // hard-locked (quarterly clôture already ran), and the following month
    // is open. The reversal must land in the following month, dated on its
    // starts_on - exactly the §5.4 forward-posting shape.
    $user = ledgerUserAs();
    actingAs($user);

    $today = Carbon::parse(BusinessDate::today());
    $academicYearId = ledgerAcademicYearId($today);
    $fiscalYear = ledgerFiscalYear($today->year);

    $lockedPeriod = ledgerPeriod($fiscalYear, $today, 'open');
    $journal = ledgerJournal();
    $account1 = ChartOfAccount::factory()->create();
    $account2 = ChartOfAccount::factory()->create();

    $original = ledgerPostEntry($journal, $fiscalYear, $lockedPeriod, $academicYearId, $today->toDateString(), [
        ['account_id' => $account1->id, 'debit' => 8000, 'credit' => 0],
        ['account_id' => $account2->id, 'debit' => 0, 'credit' => 8000],
    ], $user);

    // Now hard-lock today's period (the quarterly clôture ran after
    // posting) and open next month.
    $lockedPeriod->forceFill(['status' => 'hard_locked'])->save();
    $nextMonth = $today->copy()->addMonthNoOverflow();

    // ledgerAcademicYearId() only inserted the single academic year covering
    // $today (Sept-to-Aug). Whenever $today falls in August, $nextMonth
    // crosses into September and lands in the FOLLOWING academic year, which
    // nothing has created yet - L6's derivation would then correctly find no
    // covering year and refuse, which is not what this test is about. Extend
    // coverage explicitly rather than special-casing the shared helper for an
    // edge every other test's anchor never hits.
    if (! DB::table('academic_years')
        ->where('starts_on', '<=', $nextMonth->toDateString())
        ->where('ends_on', '>=', $nextMonth->toDateString())
        ->exists()
    ) {
        ledgerAcademicYearId($nextMonth);
    }

    $nextPeriod = ledgerPeriod($fiscalYear, $nextMonth, 'open');

    $reversal = app(ReverseJournalEntry::class)->handle($original->id, 'Correcting after quarter close.', $user->toAuditActor());

    expect($reversal->accounting_period_id)->toBe($nextPeriod->id);
    expect($reversal->date->toDateString())->toBe($nextPeriod->starts_on->toDateString());
    // value_date is always the reversal's OWN business date, never the
    // original's and never the forward-posted date.
    expect($reversal->value_date->toDateString())->toBe($today->toDateString());
    expect($reversal->is_forward_posted)->toBeTrue();
});

it('rejects reversing a draft entry', function () {
    $user = ledgerUserAs();
    actingAs($user);

    $today = Carbon::parse(BusinessDate::today());
    $academicYearId = ledgerAcademicYearId($today);
    $fiscalYear = ledgerFiscalYear($today->year);
    $period = ledgerPeriod($fiscalYear, $today, 'open');
    $journal = ledgerJournal();
    $account1 = ChartOfAccount::factory()->create();
    $account2 = ChartOfAccount::factory()->create();

    $draft = JournalEntry::query()->create([
        'journal_id' => $journal->id,
        'piece_no' => null,
        'date' => $today->toDateString(),
        'value_date' => $today->toDateString(),
        'accounting_period_id' => $period->id,
        'fiscal_year_id' => $fiscalYear->id,
        'academic_year_id' => $academicYearId,
        'label' => 'Draft entry',
        'status' => JournalEntry::STATUS_DRAFT,
        'total_debit' => 0,
        'total_credit' => 0,
        'created_by' => $user->id,
    ]);

    JournalEntryLine::query()->create([
        'journal_entry_id' => $draft->id, 'sequence' => 1, 'account_id' => $account1->id, 'label' => 'L1', 'debit' => 1000, 'credit' => 0,
    ]);
    JournalEntryLine::query()->create([
        'journal_entry_id' => $draft->id, 'sequence' => 2, 'account_id' => $account2->id, 'label' => 'L2', 'debit' => 0, 'credit' => 1000,
    ]);

    expect(fn () => app(ReverseJournalEntry::class)->handle($draft->id, 'Trying to reverse a draft.', $user->toAuditActor()))
        ->toThrow(DomainException::class, 'Only a posted entry may be reversed.');
});

it('rejects reversing an entry that has already been reversed', function () {
    $user = ledgerUserAs();
    actingAs($user);

    $today = Carbon::parse(BusinessDate::today());
    $academicYearId = ledgerAcademicYearId($today);
    $fiscalYear = ledgerFiscalYear($today->year);
    $period = ledgerPeriod($fiscalYear, $today, 'open');
    $journal = ledgerJournal();
    $account1 = ChartOfAccount::factory()->create();
    $account2 = ChartOfAccount::factory()->create();

    $original = ledgerPostEntry($journal, $fiscalYear, $period, $academicYearId, $today->toDateString(), [
        ['account_id' => $account1->id, 'debit' => 2000, 'credit' => 0],
        ['account_id' => $account2->id, 'debit' => 0, 'credit' => 2000],
    ], $user);

    app(ReverseJournalEntry::class)->handle($original->id, 'First reversal.', $user->toAuditActor());

    expect(fn () => app(ReverseJournalEntry::class)->handle($original->id, 'Second attempt at reversal.', $user->toAuditActor()))
        ->toThrow(DomainException::class, 'already been reversed');
});

it('L12: rejects reversing a reversal - the correct move is a fresh restating entry, not a chain of reversals', function () {
    $user = ledgerUserAs();
    actingAs($user);

    $today = Carbon::parse(BusinessDate::today());
    $academicYearId = ledgerAcademicYearId($today);
    $fiscalYear = ledgerFiscalYear($today->year);
    $period = ledgerPeriod($fiscalYear, $today, 'open');
    $journal = ledgerJournal();
    $account1 = ChartOfAccount::factory()->create();
    $account2 = ChartOfAccount::factory()->create();

    $original = ledgerPostEntry($journal, $fiscalYear, $period, $academicYearId, $today->toDateString(), [
        ['account_id' => $account1->id, 'debit' => 3000, 'credit' => 0],
        ['account_id' => $account2->id, 'debit' => 0, 'credit' => 3000],
    ], $user);

    $reversal = app(ReverseJournalEntry::class)->handle($original->id, 'Original reversal reason.', $user->toAuditActor());

    expect($reversal->is_reversal)->toBeTrue();

    expect(fn () => app(ReverseJournalEntry::class)->handle($reversal->id, 'Trying to reverse the reversal.', $user->toAuditActor()))
        ->toThrow(DomainException::class, 'A reversal may not itself be reversed');
});

it('rejects a reversal reason shorter than 10 characters', function () {
    $user = ledgerUserAs();
    actingAs($user);

    $today = Carbon::parse(BusinessDate::today());
    $academicYearId = ledgerAcademicYearId($today);
    $fiscalYear = ledgerFiscalYear($today->year);
    $period = ledgerPeriod($fiscalYear, $today, 'open');
    $journal = ledgerJournal();
    $account1 = ChartOfAccount::factory()->create();
    $account2 = ChartOfAccount::factory()->create();

    $original = ledgerPostEntry($journal, $fiscalYear, $period, $academicYearId, $today->toDateString(), [
        ['account_id' => $account1->id, 'debit' => 1000, 'credit' => 0],
        ['account_id' => $account2->id, 'debit' => 0, 'credit' => 1000],
    ], $user);

    expect(fn () => app(ReverseJournalEntry::class)->handle($original->id, 'too short', $user->toAuditActor()))
        ->toThrow(ValidationException::class);
});

it('unletters a lettered group when its entry is reversed', function () {
    $user = ledgerUserAs();
    actingAs($user);

    $today = Carbon::parse(BusinessDate::today());
    $academicYearId = ledgerAcademicYearId($today);
    $fiscalYear = ledgerFiscalYear($today->year);
    $period = ledgerPeriod($fiscalYear, $today, 'open');
    $journal = ledgerJournal();
    $collective = ledgerCollectiveAccount();
    $bank = ChartOfAccount::factory()->create();

    $original = ledgerPostEntry($journal, $fiscalYear, $period, $academicYearId, $today->toDateString(), [
        ['account_id' => $collective->id, 'debit' => 0, 'credit' => 20000, 'partner_type' => 'student', 'partner_id' => 42],
        ['account_id' => $bank->id, 'debit' => 20000, 'credit' => 0],
    ], $user);

    $collectiveLine = JournalEntryLine::query()
        ->where('journal_entry_id', $original->id)
        ->where('account_id', $collective->id)
        ->firstOrFail();

    $lettering = app(App\Modules\Accounting\Actions\LetterEntries::class)
        ->handle([$collectiveLine->id], $user->toAuditActor());

    expect($lettering->unlettered_at)->toBeNull();

    app(ReverseJournalEntry::class)->handle($original->id, 'Wrong student credited.', $user->toAuditActor());

    $lettering->refresh();
    expect($lettering->unlettered_at)->not->toBeNull();
    expect($lettering->unletter_reason)->toBe('reversal');
    expect($collectiveLine->refresh()->lettering_id)->toBeNull();
});

it('denies reversal to a user without ledger.post', function () {
    $user = ledgerUserAs(withPermission: false);
    actingAs($user);

    $today = Carbon::parse(BusinessDate::today());
    $academicYearId = ledgerAcademicYearId($today);
    $fiscalYear = ledgerFiscalYear($today->year);
    $period = ledgerPeriod($fiscalYear, $today, 'open');
    $journal = ledgerJournal();
    $account1 = ChartOfAccount::factory()->create();
    $account2 = ChartOfAccount::factory()->create();

    $poster = ledgerUserAs();
    $original = ledgerPostEntry($journal, $fiscalYear, $period, $academicYearId, $today->toDateString(), [
        ['account_id' => $account1->id, 'debit' => 1000, 'credit' => 0],
        ['account_id' => $account2->id, 'debit' => 0, 'credit' => 1000],
    ], $poster);

    expect(fn () => app(ReverseJournalEntry::class)->handle($original->id, 'Attempted without permission.', $user->toAuditActor()))
        ->toThrow(AuthorizationException::class);
});
