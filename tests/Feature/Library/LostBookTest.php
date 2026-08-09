<?php

declare(strict_types=1);

use App\Modules\Library\Actions\AccrueOverdueFines;
use App\Modules\Library\Actions\LevyFine;
use App\Modules\Library\Actions\MarkIssueLost;
use App\Modules\Library\Actions\PromoteOverdueIssues;
use App\Modules\Library\Actions\ReturnBook;
use App\Modules\Library\Domain\BookCopyStatus;
use App\Modules\Library\Domain\FineStatus;
use App\Modules\Library\Domain\FineType;
use App\Modules\Library\Domain\IssueStatus;
use App\Modules\Library\Models\LibraryFine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/LibraryTestHelpers.php';

uses(RefreshDatabase::class);

it('marks a loan lost: issue and copy go lost, the replacement-cost fine is assessed, nothing posts (§10.5, expensed)', function (): void {
    $user = phase9LibLibrarian();
    $calendar = phase9LibCalendar();
    $class = phase9LibMembershipClass();
    $catalog = phase9LibCatalog($user, copies: 1); // replacement_cost 6000
    $member = phase9LibStudentMember($user, $calendar['academic_year_id'], $class);

    $copyId = (int) $catalog['copies'][0]->getKey();
    $issue = phase9LibIssue($user, $copyId, (int) $member->getKey(), '2031-03-02');

    $entriesBefore = (int) DB::table('journal_entries')->count();

    $result = app(MarkIssueLost::class)->handle([
        'issue_id' => (int) $issue->getKey(),
        'lost_on' => '2031-03-20',
        'processing_fee' => 500,
        'idempotency_key' => 'p9f4-lost-1',
    ], phase9LibActor($user));

    expect($result['issue']->status)->toBe(IssueStatus::Lost)
        ->and($catalog['copies'][0]->refresh()->status)->toBe(BookCopyStatus::Lost)
        ->and($result['fine']->fine_type)->toBe(FineType::Loss)
        ->and($result['fine']->amount)->toBe(6_500) // replacement 6000 + fee 500
        ->and($result['fine']->status)->toBe(FineStatus::Assessed)
        ->and($result['fine']->student_id)->toBe((int) $member->student_id)
        // Expensed collection policy: no carrying amount, NOTHING posts here.
        ->and((int) DB::table('journal_entries')->count())->toBe($entriesBefore)
        // The loss releases uq_open_issue: the generated key goes NULL.
        ->and(DB::table('library_issues')->where('id', $issue->getKey())->value('open_copy_key'))->toBeNull();

    // Replay with the same idempotency key returns the SAME fine.
    $replay = app(MarkIssueLost::class)->handle([
        'issue_id' => (int) $issue->getKey(),
        'lost_on' => '2031-03-20',
        'processing_fee' => 500,
        'idempotency_key' => 'p9f4-lost-1',
    ], phase9LibActor($user));

    expect((int) $replay['fine']->getKey())->toBe((int) $result['fine']->getKey())
        ->and(LibraryFine::query()->count())->toBe(1);
});

it('refuses a loss without a replacement cost on record, a negative fee, and a non-open issue', function (): void {
    $user = phase9LibLibrarian();
    $calendar = phase9LibCalendar();
    $class = phase9LibMembershipClass();
    $catalog = phase9LibCatalog($user, copies: 2, bookOverrides: ['replacement_cost' => 0]);
    $member = phase9LibStudentMember($user, $calendar['academic_year_id'], $class);

    $issue = phase9LibIssue($user, (int) $catalog['copies'][0]->getKey(), (int) $member->getKey(), '2031-03-02');

    expect(fn () => app(MarkIssueLost::class)->handle([
        'issue_id' => (int) $issue->getKey(),
        'lost_on' => '2031-03-20',
    ], phase9LibActor($user)))->toThrow(DomainException::class, 'no replacement cost');

    expect(fn () => app(MarkIssueLost::class)->handle([
        'issue_id' => (int) $issue->getKey(),
        'lost_on' => '2031-03-20',
        'processing_fee' => -1,
    ], phase9LibActor($user)))->toThrow(DomainException::class, 'cannot be negative');

    // A returned loan cannot be declared lost.
    app(ReturnBook::class)->handle([
        'issue_id' => (int) $issue->getKey(),
        'returned_on' => '2031-03-10',
    ], phase9LibActor($user));

    expect(fn () => app(MarkIssueLost::class)->handle([
        'issue_id' => (int) $issue->getKey(),
        'lost_on' => '2031-03-20',
    ], phase9LibActor($user)))->toThrow(DomainException::class, 'only an open loan');
});

it('rejects "lost" as a RETURN condition: loss is a deliberate act through MarkIssueLost', function (): void {
    $user = phase9LibLibrarian();
    $calendar = phase9LibCalendar();
    $class = phase9LibMembershipClass();
    $catalog = phase9LibCatalog($user, copies: 1);
    $member = phase9LibStudentMember($user, $calendar['academic_year_id'], $class);

    $issue = phase9LibIssue($user, (int) $catalog['copies'][0]->getKey(), (int) $member->getKey(), '2031-03-02');

    expect(fn () => app(ReturnBook::class)->handle([
        'issue_id' => (int) $issue->getKey(),
        'returned_on' => '2031-03-10',
        'condition' => 'lost',
    ], phase9LibActor($user)))->toThrow(DomainException::class, 'MarkIssueLost');
});

it('lets a loss fine COEXIST with the frozen overdue fine on the same issue, and levy through the one Fees stream', function (): void {
    phase9LibPostingRules();
    $user = phase9LibLibrarian();
    actingAs($user);
    $feeItemId = phase9LibFeeItemId();

    $calendar = phase9LibCalendar();
    $class = phase9LibMembershipClass();
    $catalog = phase9LibCatalog($user, copies: 1);
    $member = phase9LibStudentMember($user, $calendar['academic_year_id'], $class);

    $issue = phase9LibIssue($user, (int) $catalog['copies'][0]->getKey(), (int) $member->getKey(), '2031-03-02');

    // Nightly discipline first: overdue + 800 FCFA accrued as of 03-20.
    app(PromoteOverdueIssues::class)->handle('2031-03-20', phase9LibActor($user));
    app(AccrueOverdueFines::class)->handle('2031-03-20', phase9LibActor($user));

    // Then the member reports the book gone.
    $lost = app(MarkIssueLost::class)->handle([
        'issue_id' => (int) $issue->getKey(),
        'lost_on' => '2031-03-21',
    ], phase9LibActor($user));

    // uq_overdue_fine_issue keys ONLY overdue fines: both rows stand.
    $fines = LibraryFine::query()->where('library_issue_id', $issue->getKey())->get();

    expect($fines)->toHaveCount(2)
        ->and($fines->firstWhere('fine_type', FineType::Overdue)?->amount)->toBe(800)
        ->and($lost['fine']->amount)->toBe(6_000);

    // A lost issue leaves the overdue population: accrual freezes at 800.
    $sweep = app(AccrueOverdueFines::class)->handle('2031-04-10', phase9LibActor($user));

    expect($sweep['examined'])->toBe(0)
        ->and((int) LibraryFine::query()->where('fine_type', FineType::Overdue->value)->value('amount'))->toBe(800);

    // The loss fine joins the SINGLE student debt stream through Fees.
    $levied = app(LevyFine::class)->handle([
        'fine_id' => (int) $lost['fine']->getKey(),
        'fee_item_id' => $feeItemId,
        'fiscal_year_id' => $calendar['fiscal_year_id'],
    ], phase9LibActor($user));

    expect($levied->status)->toBe(FineStatus::Invoiced)
        ->and($levied->invoice_id)->not->toBeNull();

    $entryId = $levied->journal_entry_id;
    assert($entryId !== null);
    $lines = phase9LibEntryLines($entryId);

    expect($lines[0]->code)->toBe('4111')
        ->and($lines[0]->debit)->toBe(6_000)
        ->and($lines[1]->code)->toBe('706')
        ->and($lines[1]->credit)->toBe(6_000);
});
