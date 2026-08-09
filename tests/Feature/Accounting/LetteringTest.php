<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\LetterEntries;
use App\Modules\Accounting\Actions\UnletterGroup;
use App\Modules\Accounting\Domain\FiscalYearStatus;
use App\Modules\Accounting\Domain\LetteringStatus;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Models\Lettering;
use App\Modules\Identity\Models\User;
use App\Support\Clock\BusinessDate;
use App\Support\Sequence\SequenceAllocator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
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

it('promotes a group to full when Sigma-debit equals Sigma-credit', function () {
    $user = ledgerUserAs();
    actingAs($user);

    $today = Carbon::parse(BusinessDate::today());
    $academicYearId = ledgerAcademicYearId($today);
    $fiscalYear = ledgerFiscalYear($today->year);
    $period = ledgerPeriod($fiscalYear, $today, 'open');
    $journal = ledgerJournal();
    $collective = ledgerCollectiveAccount();
    $bank = ChartOfAccount::factory()->create();

    $invoice = ledgerPostEntry($journal, $fiscalYear, $period, $academicYearId, $today->toDateString(), [
        ['account_id' => $collective->id, 'debit' => 50000, 'credit' => 0, 'partner_type' => 'student', 'partner_id' => 7],
        ['account_id' => $bank->id, 'debit' => 0, 'credit' => 50000],
    ], $user);

    $payment = ledgerPostEntry($journal, $fiscalYear, $period, $academicYearId, $today->toDateString(), [
        ['account_id' => $bank->id, 'debit' => 50000, 'credit' => 0],
        ['account_id' => $collective->id, 'debit' => 0, 'credit' => 50000, 'partner_type' => 'student', 'partner_id' => 7],
    ], $user);

    $invoiceLine = JournalEntryLine::query()->where('journal_entry_id', $invoice->id)->where('account_id', $collective->id)->firstOrFail();
    $paymentLine = JournalEntryLine::query()->where('journal_entry_id', $payment->id)->where('account_id', $collective->id)->firstOrFail();

    $lettering = app(LetterEntries::class)->handle([$invoiceLine->id, $paymentLine->id], $user->toAuditActor());

    expect($lettering->status)->toBe(LetteringStatus::Full);
    expect($lettering->total_debit)->toBe(50000);
    expect($lettering->total_credit)->toBe(50000);
    expect($lettering->code)->toBe('AA');

    expect($invoiceLine->refresh()->lettering_id)->toBe($lettering->id);
    expect($paymentLine->refresh()->lettering_id)->toBe($lettering->id);
});

it('leaves a group partial when it does not balance - a part payment', function () {
    $user = ledgerUserAs();
    actingAs($user);

    $today = Carbon::parse(BusinessDate::today());
    $academicYearId = ledgerAcademicYearId($today);
    $fiscalYear = ledgerFiscalYear($today->year);
    $period = ledgerPeriod($fiscalYear, $today, 'open');
    $journal = ledgerJournal();
    $collective = ledgerCollectiveAccount();
    $bank = ChartOfAccount::factory()->create();

    $invoice = ledgerPostEntry($journal, $fiscalYear, $period, $academicYearId, $today->toDateString(), [
        ['account_id' => $collective->id, 'debit' => 50000, 'credit' => 0, 'partner_type' => 'student', 'partner_id' => 9],
        ['account_id' => $bank->id, 'debit' => 0, 'credit' => 50000],
    ], $user);

    // Part payment of only 20000 against a 50000 invoice.
    $payment = ledgerPostEntry($journal, $fiscalYear, $period, $academicYearId, $today->toDateString(), [
        ['account_id' => $bank->id, 'debit' => 20000, 'credit' => 0],
        ['account_id' => $collective->id, 'debit' => 0, 'credit' => 20000, 'partner_type' => 'student', 'partner_id' => 9],
    ], $user);

    $invoiceLine = JournalEntryLine::query()->where('journal_entry_id', $invoice->id)->where('account_id', $collective->id)->firstOrFail();
    $paymentLine = JournalEntryLine::query()->where('journal_entry_id', $payment->id)->where('account_id', $collective->id)->firstOrFail();

    $lettering = app(LetterEntries::class)->handle([$invoiceLine->id, $paymentLine->id], $user->toAuditActor());

    expect($lettering->status)->toBe(LetteringStatus::Partial);
    expect($lettering->total_debit)->toBe(50000);
    expect($lettering->total_credit)->toBe(20000);
});

it('unlettering nulls the lines lettering_id, retains the Lettering row as history, and never hard-deletes it', function () {
    $user = ledgerUserAs();
    actingAs($user);

    $today = Carbon::parse(BusinessDate::today());
    $academicYearId = ledgerAcademicYearId($today);
    $fiscalYear = ledgerFiscalYear($today->year);
    $period = ledgerPeriod($fiscalYear, $today, 'open');
    $journal = ledgerJournal();
    $collective = ledgerCollectiveAccount();
    $bank = ChartOfAccount::factory()->create();

    $invoice = ledgerPostEntry($journal, $fiscalYear, $period, $academicYearId, $today->toDateString(), [
        ['account_id' => $collective->id, 'debit' => 30000, 'credit' => 0, 'partner_type' => 'student', 'partner_id' => 11],
        ['account_id' => $bank->id, 'debit' => 0, 'credit' => 30000],
    ], $user);

    $payment = ledgerPostEntry($journal, $fiscalYear, $period, $academicYearId, $today->toDateString(), [
        ['account_id' => $bank->id, 'debit' => 30000, 'credit' => 0],
        ['account_id' => $collective->id, 'debit' => 0, 'credit' => 30000, 'partner_type' => 'student', 'partner_id' => 11],
    ], $user);

    $invoiceLine = JournalEntryLine::query()->where('journal_entry_id', $invoice->id)->where('account_id', $collective->id)->firstOrFail();
    $paymentLine = JournalEntryLine::query()->where('journal_entry_id', $payment->id)->where('account_id', $collective->id)->firstOrFail();

    $lettering = app(LetterEntries::class)->handle([$invoiceLine->id, $paymentLine->id], $user->toAuditActor());
    expect($lettering->status)->toBe(LetteringStatus::Full);

    $unlettered = app(UnletterGroup::class)->handle($lettering->id, 'Wrong invoice matched.', $user->toAuditActor());

    expect($unlettered->unlettered_at)->not->toBeNull();
    expect($unlettered->unlettered_by)->toBe($user->id);
    expect($unlettered->unletter_reason)->toBe('Wrong invoice matched.');
    // The row survives - §15/§10.4: never hard-deleted, status kept as history.
    expect(Lettering::query()->find($lettering->id))->not->toBeNull();
    expect($unlettered->status)->toBe(LetteringStatus::Full);

    expect($invoiceLine->refresh()->lettering_id)->toBeNull();
    expect($paymentLine->refresh()->lettering_id)->toBeNull();
});

it('LT-1: rejects lettering lines that do not share the same account and partner', function () {
    $user = ledgerUserAs();
    actingAs($user);

    $today = Carbon::parse(BusinessDate::today());
    $academicYearId = ledgerAcademicYearId($today);
    $fiscalYear = ledgerFiscalYear($today->year);
    $period = ledgerPeriod($fiscalYear, $today, 'open');
    $journal = ledgerJournal();
    $collective = ledgerCollectiveAccount();
    // Deliberately just these two lines: they already balance
    // (10000 debit against 10000 credit), and a third, unused $bank line
    // written as debit=0/credit=0 would violate ck_jel_one_side - a
    // genuinely zero-amount line is never legal on this ledger, not even
    // as test padding.
    $entry = ledgerPostEntry($journal, $fiscalYear, $period, $academicYearId, $today->toDateString(), [
        ['account_id' => $collective->id, 'debit' => 10000, 'credit' => 0, 'partner_type' => 'student', 'partner_id' => 1],
        ['account_id' => $collective->id, 'debit' => 0, 'credit' => 10000, 'partner_type' => 'student', 'partner_id' => 2],
    ], $user);

    $lineA = JournalEntryLine::query()->where('journal_entry_id', $entry->id)->where('partner_id', 1)->firstOrFail();
    $lineB = JournalEntryLine::query()->where('journal_entry_id', $entry->id)->where('partner_id', 2)->firstOrFail();

    expect(fn () => app(LetterEntries::class)->handle([$lineA->id, $lineB->id], $user->toAuditActor()))
        ->toThrow(DomainException::class, 'LT-1');
});

it('LT-6: rejects lettering a line that already belongs to a group', function () {
    $user = ledgerUserAs();
    actingAs($user);

    $today = Carbon::parse(BusinessDate::today());
    $academicYearId = ledgerAcademicYearId($today);
    $fiscalYear = ledgerFiscalYear($today->year);
    $period = ledgerPeriod($fiscalYear, $today, 'open');
    $journal = ledgerJournal();
    $collective = ledgerCollectiveAccount();
    $bank = ChartOfAccount::factory()->create();

    $invoice = ledgerPostEntry($journal, $fiscalYear, $period, $academicYearId, $today->toDateString(), [
        ['account_id' => $collective->id, 'debit' => 10000, 'credit' => 0, 'partner_type' => 'student', 'partner_id' => 5],
        ['account_id' => $bank->id, 'debit' => 0, 'credit' => 10000],
    ], $user);

    $payment = ledgerPostEntry($journal, $fiscalYear, $period, $academicYearId, $today->toDateString(), [
        ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
        ['account_id' => $collective->id, 'debit' => 0, 'credit' => 10000, 'partner_type' => 'student', 'partner_id' => 5],
    ], $user);

    $extraPayment = ledgerPostEntry($journal, $fiscalYear, $period, $academicYearId, $today->toDateString(), [
        ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
        ['account_id' => $collective->id, 'debit' => 0, 'credit' => 10000, 'partner_type' => 'student', 'partner_id' => 5],
    ], $user);

    $invoiceLine = JournalEntryLine::query()->where('journal_entry_id', $invoice->id)->where('account_id', $collective->id)->firstOrFail();
    $paymentLine = JournalEntryLine::query()->where('journal_entry_id', $payment->id)->where('account_id', $collective->id)->firstOrFail();
    $extraLine = JournalEntryLine::query()->where('journal_entry_id', $extraPayment->id)->where('account_id', $collective->id)->firstOrFail();

    app(LetterEntries::class)->handle([$invoiceLine->id, $paymentLine->id], $user->toAuditActor());

    expect(fn () => app(LetterEntries::class)->handle([$invoiceLine->id, $extraLine->id], $user->toAuditActor()))
        ->toThrow(DomainException::class, 'LT-6');
});

it('LT-5: rejects lettering a line on a draft entry', function () {
    $user = ledgerUserAs();
    actingAs($user);

    $today = Carbon::parse(BusinessDate::today());
    $academicYearId = ledgerAcademicYearId($today);
    $fiscalYear = ledgerFiscalYear($today->year);
    $period = ledgerPeriod($fiscalYear, $today, 'open');
    $journal = ledgerJournal();
    $collective = ledgerCollectiveAccount();
    $bank = ChartOfAccount::factory()->create();

    $draft = JournalEntry::query()->create([
        'journal_id' => $journal->id,
        'piece_no' => null,
        'date' => $today->toDateString(),
        'value_date' => $today->toDateString(),
        'accounting_period_id' => $period->id,
        'fiscal_year_id' => $fiscalYear->id,
        'academic_year_id' => $academicYearId,
        'label' => 'Draft',
        'status' => JournalEntry::STATUS_DRAFT,
        'total_debit' => 0,
        'total_credit' => 0,
        'created_by' => $user->id,
    ]);

    $draftLine = JournalEntryLine::query()->create([
        'journal_entry_id' => $draft->id, 'sequence' => 1, 'account_id' => $collective->id,
        'label' => 'L1', 'debit' => 10000, 'credit' => 0, 'partner_type' => 'student', 'partner_id' => 3,
    ]);
    JournalEntryLine::query()->create([
        'journal_entry_id' => $draft->id, 'sequence' => 2, 'account_id' => $bank->id, 'label' => 'L2', 'debit' => 0, 'credit' => 10000,
    ]);

    $posted = ledgerPostEntry($journal, $fiscalYear, $period, $academicYearId, $today->toDateString(), [
        ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
        ['account_id' => $collective->id, 'debit' => 0, 'credit' => 10000, 'partner_type' => 'student', 'partner_id' => 3],
    ], $user);
    $postedLine = JournalEntryLine::query()->where('journal_entry_id', $posted->id)->where('account_id', $collective->id)->firstOrFail();

    expect(fn () => app(LetterEntries::class)->handle([$draftLine->id, $postedLine->id], $user->toAuditActor()))
        ->toThrow(DomainException::class, 'LT-5');
});

it('rejects lettering an account that is not lettrable', function () {
    $user = ledgerUserAs();
    actingAs($user);

    $today = Carbon::parse(BusinessDate::today());
    $academicYearId = ledgerAcademicYearId($today);
    $fiscalYear = ledgerFiscalYear($today->year);
    $period = ledgerPeriod($fiscalYear, $today, 'open');
    $journal = ledgerJournal();
    $notLettrable = ChartOfAccount::factory()->create([
        'is_collective' => true,
        'requires_partner' => true,
        'allowed_partner_types' => ['student'],
        'is_lettrable' => false,
    ]);
    $bank = ChartOfAccount::factory()->create();

    $entry = ledgerPostEntry($journal, $fiscalYear, $period, $academicYearId, $today->toDateString(), [
        ['account_id' => $notLettrable->id, 'debit' => 10000, 'credit' => 0, 'partner_type' => 'student', 'partner_id' => 4],
        ['account_id' => $bank->id, 'debit' => 0, 'credit' => 10000],
    ], $user);

    $line = JournalEntryLine::query()->where('journal_entry_id', $entry->id)->where('account_id', $notLettrable->id)->firstOrFail();

    expect(fn () => app(LetterEntries::class)->handle([$line->id], $user->toAuditActor()))
        ->toThrow(DomainException::class, 'not lettrable');
});

it('denies lettering to a user without ledger.post', function () {
    $poster = ledgerUserAs();
    $noPermission = ledgerUserAs(withPermission: false);

    actingAs($poster);
    $today = Carbon::parse(BusinessDate::today());
    $academicYearId = ledgerAcademicYearId($today);
    $fiscalYear = ledgerFiscalYear($today->year);
    $period = ledgerPeriod($fiscalYear, $today, 'open');
    $journal = ledgerJournal();
    $collective = ledgerCollectiveAccount();
    $bank = ChartOfAccount::factory()->create();

    $entry = ledgerPostEntry($journal, $fiscalYear, $period, $academicYearId, $today->toDateString(), [
        ['account_id' => $collective->id, 'debit' => 10000, 'credit' => 0, 'partner_type' => 'student', 'partner_id' => 6],
        ['account_id' => $bank->id, 'debit' => 0, 'credit' => 10000],
    ], $poster);
    $line = JournalEntryLine::query()->where('journal_entry_id', $entry->id)->where('account_id', $collective->id)->firstOrFail();

    actingAs($noPermission);

    expect(fn () => app(LetterEntries::class)->handle([$line->id], $noPermission->toAuditActor()))
        ->toThrow(AuthorizationException::class);
});
