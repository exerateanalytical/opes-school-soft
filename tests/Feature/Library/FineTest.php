<?php

declare(strict_types=1);

use App\Modules\Library\Actions\AccrueOverdueFines;
use App\Modules\Library\Actions\LevyFine;
use App\Modules\Library\Actions\PromoteOverdueIssues;
use App\Modules\Library\Actions\WaiveFine;
use App\Modules\Library\Domain\FineStatus;
use App\Modules\Library\Domain\FineType;
use App\Modules\Library\Domain\LibraryPermission;
use App\Modules\Library\Domain\SettlementRoute;
use App\Modules\Library\Models\LibraryFine;
use App\Modules\Library\Models\LibraryIssue;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/LibraryTestHelpers.php';

uses(RefreshDatabase::class);

if (! function_exists('phase9LibOverdueFineFixture')) {
    /**
     * An overdue ASSESSED fine through the real doors: issue 2031-03-02, due
     * 2031-03-16 (14-day class), promoted overdue and accrued as of $asOf.
     *
     * @param  array<string, mixed>  $classOverrides
     * @param  array<string, mixed>  $bookOverrides
     * @return array{fine: LibraryFine, issue: LibraryIssue, member: \App\Modules\Library\Models\LibraryMember, calendar: array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}}
     */
    function phase9LibOverdueFineFixture(
        \App\Modules\Identity\Models\User $user,
        string $asOf = '2031-03-20',
        array $classOverrides = [],
        array $bookOverrides = [],
    ): array {
        $calendar = phase9LibCalendar();
        $class = phase9LibMembershipClass($classOverrides);
        $catalog = phase9LibCatalog($user, copies: 1, bookOverrides: $bookOverrides);
        $member = phase9LibStudentMember($user, $calendar['academic_year_id'], $class);

        $issue = phase9LibIssue($user, (int) $catalog['copies'][0]->getKey(), (int) $member->getKey(), '2031-03-02');

        app(PromoteOverdueIssues::class)->handle($asOf, phase9LibActor($user));
        app(AccrueOverdueFines::class)->handle($asOf, phase9LibActor($user));

        /** @var LibraryFine $fine */
        $fine = LibraryFine::query()->where('library_issue_id', $issue->getKey())->firstOrFail();

        return ['fine' => $fine, 'issue' => $issue, 'member' => $member, 'calendar' => $calendar];
    }
}

it('accrues the overdue ENTITLEMENT idempotently: rerun lands on the same figure, catch-up adjusts one row (acceptance 11)', function (): void {
    $user = phase9LibLibrarian();
    // 200/day, 0 grace: due 03-16, as-of 03-20 → 4 days → 800.
    $fixture = phase9LibOverdueFineFixture($user, '2031-03-20');
    $fine = $fixture['fine'];

    expect($fine->fine_type)->toBe(FineType::Overdue)
        ->and($fine->days_overdue)->toBe(4)
        ->and($fine->amount)->toBe(800)
        ->and($fine->status)->toBe(FineStatus::Assessed)
        ->and($fine->settlement_route)->toBe(SettlementRoute::StudentReceivable);

    // Running the SAME night five more times changes nothing.
    $rerun = app(AccrueOverdueFines::class)->handle('2031-03-20', phase9LibActor($user));

    expect($rerun)->toBe(['examined' => 1, 'created' => 0, 'adjusted' => 0])
        ->and(LibraryFine::query()->count())->toBe(1)
        ->and($fine->refresh()->amount)->toBe(800);

    // A week missed: the catch-up run RECOMPUTES the one row - 9 days → 1800
    // - it never appends a fine per night.
    $catchUp = app(AccrueOverdueFines::class)->handle('2031-03-25', phase9LibActor($user));

    expect($catchUp)->toBe(['examined' => 1, 'created' => 0, 'adjusted' => 1])
        ->and(LibraryFine::query()->count())->toBe(1);

    $fine->refresh();
    expect($fine->days_overdue)->toBe(9)
        ->and($fine->amount)->toBe(1_800)
        ->and($fine->fine_no)->toMatch('#^FIN/2031/\d{6}$#');
});

it('respects grace days and caps at the replacement cost per the class policy', function (): void {
    $user = phase9LibLibrarian();

    // 5 grace days: 4 days overdue is still inside grace - nothing assessed.
    $calendar = phase9LibCalendar();
    $graced = phase9LibMembershipClass(['fine_grace_days' => 5]);
    $catalog = phase9LibCatalog($user, copies: 1);
    $member = phase9LibStudentMember($user, $calendar['academic_year_id'], $graced);
    $issue = phase9LibIssue($user, (int) $catalog['copies'][0]->getKey(), (int) $member->getKey(), '2031-03-02');

    app(PromoteOverdueIssues::class)->handle('2031-03-20', phase9LibActor($user));
    $run = app(AccrueOverdueFines::class)->handle('2031-03-20', phase9LibActor($user));

    expect($run['created'])->toBe(0)
        ->and(LibraryFine::query()->where('library_issue_id', $issue->getKey())->count())->toBe(0);

    // Past grace: 10 days − 5 grace → 5 × 200 = 1000.
    app(AccrueOverdueFines::class)->handle('2031-03-26', phase9LibActor($user));

    expect((int) LibraryFine::query()->where('library_issue_id', $issue->getKey())->value('amount'))->toBe(1_000);

    // The cap: 46 days × 200 = 9200 would exceed the 6000 replacement cost -
    // a fine must never grow past the book it is about.
    $capped = phase9LibOverdueFineFixture($user, '2031-05-01');

    expect($capped['fine']->amount)->toBe(6_000)
        ->and($capped['fine']->days_overdue)->toBe(46);

    // An `uncapped` class keeps the arithmetic figure.
    $uncapped = phase9LibOverdueFineFixture($user, '2031-05-01', classOverrides: ['fine_cap_policy' => 'uncapped']);

    expect($uncapped['fine']->amount)->toBe(9_200);
});

it('excludes days the library was closed per the school calendar (§10.5)', function (): void {
    $user = phase9LibLibrarian();
    $calendar = phase9LibCalendar();
    $class = phase9LibMembershipClass();
    $catalog = phase9LibCatalog($user, copies: 1);
    $member = phase9LibStudentMember($user, $calendar['academic_year_id'], $class);
    $issue = phase9LibIssue($user, (int) $catalog['copies'][0]->getKey(), (int) $member->getKey(), '2031-03-02');

    // Two closure days inside (due, as_of].
    foreach (['2031-03-18', '2031-03-19'] as $closed) {
        DB::table('school_calendar_days')->insert([
            'academic_year_id' => $calendar['academic_year_id'],
            'date' => $closed,
            'day_type' => 'school_holiday',
            'school_section_id' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    app(PromoteOverdueIssues::class)->handle('2031-03-20', phase9LibActor($user));
    app(AccrueOverdueFines::class)->handle('2031-03-20', phase9LibActor($user));

    /** @var LibraryFine $fine */
    $fine = LibraryFine::query()->where('library_issue_id', $issue->getKey())->firstOrFail();

    // 4 calendar days minus 2 closed → 2 × 200 = 400.
    expect($fine->days_overdue)->toBe(2)
        ->and($fine->amount)->toBe(400);
});

it('levies a STUDENT fine through the Fees door: one debt stream, Dr 4111 / Cr the FeeItem income account (§10.7)', function (): void {
    phase9LibPostingRules();
    $user = phase9LibLibrarian();
    actingAs($user);
    $feeItemId = phase9LibFeeItemId();

    $fixture = phase9LibOverdueFineFixture($user, '2031-03-20'); // 800 FCFA
    $fine = $fixture['fine'];

    $invoicesBefore = (int) DB::table('invoices')->count();

    $levied = app(LevyFine::class)->handle([
        'fine_id' => (int) $fine->getKey(),
        'fee_item_id' => $feeItemId,
        'fiscal_year_id' => $fixture['calendar']['fiscal_year_id'],
    ], phase9LibActor($user));

    expect($levied->status)->toBe(FineStatus::Invoiced)
        ->and($levied->invoice_id)->not->toBeNull()
        ->and($levied->journal_entry_id)->not->toBeNull()
        ->and($levied->levied_by)->toBe((int) $user->getKey())
        // ONE debt stream: exactly one new invoice, no parallel receivable.
        ->and((int) DB::table('invoices')->count())->toBe($invoicesBefore + 1)
        ->and((int) DB::table('invoices')->where('id', $levied->invoice_id)->value('student_id'))
        ->toBe((int) $fixture['member']->student_id);

    $entryId = $levied->journal_entry_id;
    assert($entryId !== null);
    $lines = phase9LibEntryLines($entryId);

    expect($lines)->toHaveCount(2)
        ->and($lines[0]->code)->toBe('4111')
        ->and($lines[0]->debit)->toBe(800)
        ->and($lines[0]->partner_type)->toBe('student')
        ->and($lines[0]->partner_id)->toBe((int) $fixture['member']->student_id)
        ->and($lines[1]->code)->toBe('706')
        ->and($lines[1]->credit)->toBe(800);

    // The assessment is now FROZEN: a later accrual run must not touch it.
    $frozen = app(AccrueOverdueFines::class)->handle('2031-04-10', phase9LibActor($user));

    expect($frozen['adjusted'])->toBe(0)
        ->and($fine->refresh()->amount)->toBe(800);

    // And an invoiced fine cannot levy twice.
    expect(fn () => app(LevyFine::class)->handle([
        'fine_id' => (int) $fine->getKey(),
        'fee_item_id' => $feeItemId,
        'fiscal_year_id' => $fixture['calendar']['fiscal_year_id'],
    ], phase9LibActor($user)))->toThrow(DomainException::class, 'only an assessed fine');
});

it('refuses a student levy without the accountant-configured FeeItem (V14) and refuses ad-hoc overdue fines', function (): void {
    $user = phase9LibLibrarian();
    $fixture = phase9LibOverdueFineFixture($user, '2031-03-20');

    expect(fn () => app(LevyFine::class)->handle([
        'fine_id' => (int) $fixture['fine']->getKey(),
        'fiscal_year_id' => $fixture['calendar']['fiscal_year_id'],
    ], phase9LibActor($user)))->toThrow(DomainException::class, 'V14');

    expect(fn () => app(LevyFine::class)->handle([
        'library_member_id' => (int) $fixture['member']->getKey(),
        'fine_type' => 'overdue',
        'amount' => 500,
    ], phase9LibActor($user)))->toThrow(DomainException::class, 'nightly accrual');
});

it('routes a STAFF fine to payroll deduction: snapshotted route, nothing posted, nothing in Fees (§10.7)', function (): void {
    $user = phase9LibLibrarian();
    $calendar = phase9LibCalendar();
    $class = phase9LibMembershipClass();
    $staffMember = phase9LibStaffMember($user, $calendar['academic_year_id'], $class);

    $entriesBefore = (int) DB::table('journal_entries')->count();
    $invoicesBefore = (int) DB::table('invoices')->count();

    $fine = app(LevyFine::class)->handle([
        'library_member_id' => (int) $staffMember->getKey(),
        'fine_type' => 'damage',
        'amount' => 2_500,
        'assessed_on' => '2031-03-20',
    ], phase9LibActor($user));

    expect($fine->settlement_route)->toBe(SettlementRoute::StaffPayrollDeduction)
        ->and($fine->status)->toBe(FineStatus::Assessed) // queued for Phase 11
        ->and($fine->invoice_id)->toBeNull()
        ->and($fine->payroll_deduction_id)->toBeNull()
        ->and((int) DB::table('journal_entries')->count())->toBe($entriesBefore)
        ->and((int) DB::table('invoices')->count())->toBe($invoicesBefore);
});

it('hard-gates immediate cash collection for EXTERNAL members on the unverified 571x Caisse (V13)', function (): void {
    $user = phase9LibLibrarian();
    $calendar = phase9LibCalendar();
    $class = phase9LibMembershipClass();

    $external = app(\App\Modules\Library\Actions\EnrollLibraryMember::class)->handle([
        'member_type' => 'external',
        'membership_class_id' => (int) $class->getKey(),
        'academic_year_id' => $calendar['academic_year_id'],
        'joined_on' => '2031-01-10',
        'external_name' => 'Alumni Reader',
        'external_contact' => '+237 690 00 00 00',
    ], phase9LibActor($user));

    expect(fn () => app(LevyFine::class)->handle([
        'library_member_id' => (int) $external->getKey(),
        'fine_type' => 'damage',
        'amount' => 1_000,
    ], phase9LibActor($user)))->toThrow(DomainException::class, '571x');
});

it('waives an ASSESSED fine in place - reason required, approver may not be the levier, nothing posts (§10.6)', function (): void {
    phase9LibPostingRules();
    $levier = phase9LibUser(
        LibraryPermission::MANAGE,
        LibraryPermission::CIRCULATE,
        LibraryPermission::WAIVE_FINE,
        Permission::FeeCollect->value,
        Permission::LedgerPost->value,
    );
    actingAs($levier);
    $feeItemId = phase9LibFeeItemId();

    $fixture = phase9LibOverdueFineFixture($levier, '2031-03-20'); // 800
    $fine = $fixture['fine'];

    // Fresh from the nightly accrual nobody levied it - anyone may waive.
    // But a reason is still mandatory.
    $approver = phase9LibUser(LibraryPermission::WAIVE_FINE, Permission::FeeCollect->value, Permission::LedgerPost->value);

    expect(fn () => app(WaiveFine::class)->handle([
        'fine_id' => (int) $fine->getKey(),
        'reason' => '   ',
    ], phase9LibActor($approver)))->toThrow(DomainException::class, 'reason');

    $entriesBefore = (int) DB::table('journal_entries')->count();

    $waived = app(WaiveFine::class)->handle([
        'fine_id' => (int) $fine->getKey(),
        'reason' => 'First offence, returned next morning',
    ], phase9LibActor($approver));

    expect($waived->status)->toBe(FineStatus::Waived)
        ->and($waived->waived_amount)->toBe(800)
        ->and($waived->waived_by)->toBe((int) $approver->getKey())
        ->and($waived->credit_note_id)->toBeNull()
        // Never posted, so nothing to reverse: the ledger is untouched.
        ->and((int) DB::table('journal_entries')->count())->toBe($entriesBefore);

    // Segregation: once LEVIED, the levier is barred from approving a waiver.
    actingAs($levier);
    $second = phase9LibOverdueFineFixture($levier, '2031-03-20');

    app(LevyFine::class)->handle([
        'fine_id' => (int) $second['fine']->getKey(),
        'fee_item_id' => $feeItemId,
        'fiscal_year_id' => $second['calendar']['fiscal_year_id'],
    ], phase9LibActor($levier));

    expect(fn () => app(WaiveFine::class)->handle([
        'fine_id' => (int) $second['fine']->getKey(),
        'reason' => 'Waiving my own levy',
    ], phase9LibActor($levier)))->toThrow(DomainException::class, 'may not be the person');
});

it('waives an INVOICED fine through a Fees credit note: contra-revenue, receivable relieved, stream stays single', function (): void {
    phase9LibPostingRules();
    $levier = phase9LibLibrarian();
    actingAs($levier);
    $feeItemId = phase9LibFeeItemId();

    $fixture = phase9LibOverdueFineFixture($levier, '2031-03-20'); // 800
    $fine = $fixture['fine'];

    app(LevyFine::class)->handle([
        'fine_id' => (int) $fine->getKey(),
        'fee_item_id' => $feeItemId,
        'fiscal_year_id' => $fixture['calendar']['fiscal_year_id'],
    ], phase9LibActor($levier));

    $approver = phase9LibUser(LibraryPermission::WAIVE_FINE, Permission::FeeCollect->value, Permission::LedgerPost->value);

    // Partial waiver first: 300 of 800.
    $partial = app(WaiveFine::class)->handle([
        'fine_id' => (int) $fine->getKey(),
        'reason' => 'Storm week - library inaccessible',
        'amount' => 300,
        'waived_on' => '2031-03-21',
    ], phase9LibActor($approver));

    expect($partial->status)->toBe(FineStatus::Invoiced)
        ->and($partial->waived_amount)->toBe(300)
        ->and($partial->credit_note_id)->not->toBeNull();

    // The credit note posted contra-revenue: Dr 706 / Cr 4111 for 300.
    $noteEntryId = (int) DB::table('credit_notes')->where('id', $partial->credit_note_id)->value('journal_entry_id');
    $lines = phase9LibEntryLines($noteEntryId);

    expect($lines)->toHaveCount(2)
        ->and($lines[0]->code)->toBe('706')
        ->and($lines[0]->debit)->toBe(300)
        ->and($lines[1]->code)->toBe('4111')
        ->and($lines[1]->credit)->toBe(300);

    // A waiver can never exceed what remains.
    expect(fn () => app(WaiveFine::class)->handle([
        'fine_id' => (int) $fine->getKey(),
        'reason' => 'Too much',
        'amount' => 501,
    ], phase9LibActor($approver)))->toThrow(DomainException::class, 'between 1 and the remaining');

    // Waiving the remaining 500 closes the fine.
    $closed = app(WaiveFine::class)->handle([
        'fine_id' => (int) $fine->getKey(),
        'reason' => 'Goodwill - remaining balance',
        'waived_on' => '2031-03-22',
    ], phase9LibActor($approver));

    expect($closed->status)->toBe(FineStatus::Waived)
        ->and($closed->waived_amount)->toBe(800);
});

it('blocks issue and renewal while unpaid fines stand above the class threshold', function (): void {
    $user = phase9LibLibrarian();
    // threshold 0: ANY unpaid fine blocks.
    $fixture = phase9LibOverdueFineFixture($user, '2031-03-20');

    $another = phase9LibCatalog($user, copies: 1);

    expect(fn () => phase9LibIssue($user, (int) $another['copies'][0]->getKey(), (int) $fixture['member']->getKey(), '2031-03-21'))
        ->toThrow(DomainException::class, 'unpaid fines');

    expect(fn () => app(\App\Modules\Library\Actions\RenewIssue::class)->handle([
        'issue_id' => (int) $fixture['issue']->getKey(),
        'renewed_on' => '2031-03-21',
    ], phase9LibActor($user)))->toThrow(DomainException::class, 'unpaid fines');
});
