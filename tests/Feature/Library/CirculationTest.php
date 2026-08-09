<?php

declare(strict_types=1);

use App\Modules\Library\Actions\ExpireReservations;
use App\Modules\Library\Actions\IssueBook;
use App\Modules\Library\Actions\PromoteOverdueIssues;
use App\Modules\Library\Actions\RenewIssue;
use App\Modules\Library\Actions\ReserveBook;
use App\Modules\Library\Actions\ReturnBook;
use App\Modules\Library\Domain\BookCopyStatus;
use App\Modules\Library\Domain\IssueStatus;
use App\Modules\Library\Domain\LibraryPermission;
use App\Modules\Library\Domain\LibraryReservationStatus;
use App\Modules\Library\Models\BookCopy;
use App\Modules\Library\Models\LibraryRenewal;
use App\Modules\Library\Models\LibraryReservation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/LibraryTestHelpers.php';

uses(RefreshDatabase::class);

it('issues a copy: due date from the class terms, copy off the shelf, numbered issue', function (): void {
    $user = phase9LibLibrarian();
    $calendar = phase9LibCalendar();
    $class = phase9LibMembershipClass(); // 14-day loans
    $catalog = phase9LibCatalog($user, copies: 1);
    $member = phase9LibStudentMember($user, $calendar['academic_year_id'], $class);

    $issue = phase9LibIssue($user, (int) $catalog['copies'][0]->getKey(), (int) $member->getKey(), '2031-03-02');

    expect($issue->issue_no)->toMatch('#^ISS/2031/\d{6}$#')
        ->and($issue->due_on)->toBe('2031-03-16')
        ->and($issue->status)->toBe(IssueStatus::Open)
        ->and($catalog['copies'][0]->refresh()->status)->toBe(BookCopyStatus::Issued);
});

it('never issues the same copy twice: door refusal AND the uq_open_issue key as last defence (acceptance 10)', function (): void {
    $user = phase9LibLibrarian();
    $calendar = phase9LibCalendar();
    $class = phase9LibMembershipClass();
    $catalog = phase9LibCatalog($user, copies: 1);
    $memberA = phase9LibStudentMember($user, $calendar['academic_year_id'], $class);
    $memberB = phase9LibStudentMember($user, $calendar['academic_year_id'], $class);

    $copyId = (int) $catalog['copies'][0]->getKey();
    phase9LibIssue($user, $copyId, (int) $memberA->getKey());

    // The door: the copy is `issued`, not available.
    expect(fn () => phase9LibIssue($user, $copyId, (int) $memberB->getKey()))
        ->toThrow(DomainException::class, 'not available');

    // The last line of defence: even a write that skips every check dies on
    // the generated-column unique key, not on a double loan.
    expect(fn () => DB::table('library_issues')->insert([
        'issue_no' => 'ISS/2031/999999',
        'book_copy_id' => $copyId,
        'library_member_id' => (int) $memberB->getKey(),
        'issued_on' => '2031-03-03',
        'due_on' => '2031-03-17',
        'issued_by' => (int) $user->getKey(),
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('enforces the concurrent-issue limit and the reference-only rule per membership class', function (): void {
    $user = phase9LibLibrarian();
    $calendar = phase9LibCalendar();
    $class = phase9LibMembershipClass(['max_concurrent_issues' => 2]);
    $catalog = phase9LibCatalog($user, copies: 3);
    $member = phase9LibStudentMember($user, $calendar['academic_year_id'], $class);

    phase9LibIssue($user, (int) $catalog['copies'][0]->getKey(), (int) $member->getKey());
    phase9LibIssue($user, (int) $catalog['copies'][1]->getKey(), (int) $member->getKey());

    expect(fn () => phase9LibIssue($user, (int) $catalog['copies'][2]->getKey(), (int) $member->getKey()))
        ->toThrow(DomainException::class, 'concurrent issues');

    // Reference-only never circulates for a class without the privilege (§10.1).
    $reference = phase9LibCatalog($user, copies: 1, bookOverrides: ['is_reference_only' => true]);
    $privileged = phase9LibMembershipClass(['can_borrow_reference' => true]);
    $memberB = phase9LibStudentMember($user, $calendar['academic_year_id'], $class);
    $memberC = phase9LibStudentMember($user, $calendar['academic_year_id'], $privileged);

    expect(fn () => phase9LibIssue($user, (int) $reference['copies'][0]->getKey(), (int) $memberB->getKey()))
        ->toThrow(DomainException::class, 'reference-only');

    $issued = phase9LibIssue($user, (int) $reference['copies'][0]->getKey(), (int) $memberC->getKey());
    expect($issued->status)->toBe(IssueStatus::Open);
});

it('refuses issue to a suspended member and to a signed-in user without library.circulate', function (): void {
    $user = phase9LibLibrarian();
    $calendar = phase9LibCalendar();
    $class = phase9LibMembershipClass();
    $catalog = phase9LibCatalog($user, copies: 1);
    $member = phase9LibStudentMember($user, $calendar['academic_year_id'], $class);

    $member->forceFill(['status' => 'suspended'])->save();

    expect(fn () => phase9LibIssue($user, (int) $catalog['copies'][0]->getKey(), (int) $member->getKey()))
        ->toThrow(DomainException::class, 'only active members');

    $member->forceFill(['status' => 'active'])->save();
    $viewer = phase9LibUser(LibraryPermission::VIEW);

    expect(fn () => app(IssueBook::class)->handle([
        'book_copy_id' => (int) $catalog['copies'][0]->getKey(),
        'library_member_id' => (int) $member->getKey(),
        'issued_on' => '2031-03-02',
    ], phase9LibActor($viewer)))->toThrow(AuthorizationException::class);
});

it('renews: due date extends, history row appends, limits bind, and an overdue loan reopens', function (): void {
    $user = phase9LibLibrarian();
    $calendar = phase9LibCalendar();
    $class = phase9LibMembershipClass(['max_renewals' => 1, 'renewal_days' => 7]);
    $catalog = phase9LibCatalog($user, copies: 1);
    $member = phase9LibStudentMember($user, $calendar['academic_year_id'], $class);

    $issue = phase9LibIssue($user, (int) $catalog['copies'][0]->getKey(), (int) $member->getKey(), '2031-03-02');

    // Promote past due FIRST - the renewal must also reopen an overdue loan.
    app(PromoteOverdueIssues::class)->handle('2031-03-20', phase9LibActor($user));
    expect($issue->refresh()->status)->toBe(IssueStatus::Overdue);

    $renewed = app(RenewIssue::class)->handle([
        'issue_id' => (int) $issue->getKey(),
        'renewed_on' => '2031-03-20',
    ], phase9LibActor($user));

    expect($renewed->due_on)->toBe('2031-03-23') // previous due + renewal_days
        ->and($renewed->renewal_count)->toBe(1)
        ->and($renewed->status)->toBe(IssueStatus::Open);

    /** @var LibraryRenewal $history */
    $history = LibraryRenewal::query()->where('library_issue_id', $issue->getKey())->firstOrFail();
    expect($history->previous_due_on)->toBe('2031-03-16')
        ->and($history->new_due_on)->toBe('2031-03-23');

    // The class allows ONE renewal.
    expect(fn () => app(RenewIssue::class)->handle([
        'issue_id' => (int) $issue->getKey(),
        'renewed_on' => '2031-03-21',
    ], phase9LibActor($user)))->toThrow(DomainException::class, 'renewals');

    // And the history is append-only at the database.
    expect(fn () => DB::table('library_renewals')
        ->where('id', $history->getKey())
        ->update(['new_due_on' => '2031-06-01']))->toThrow(QueryException::class);
});

it('refuses renewal while a reservation waits for the title (§10.4)', function (): void {
    $user = phase9LibLibrarian();
    $calendar = phase9LibCalendar();
    $class = phase9LibMembershipClass();
    $catalog = phase9LibCatalog($user, copies: 1);
    $borrower = phase9LibStudentMember($user, $calendar['academic_year_id'], $class);
    $waiter = phase9LibStudentMember($user, $calendar['academic_year_id'], $class);

    $issue = phase9LibIssue($user, (int) $catalog['copies'][0]->getKey(), (int) $borrower->getKey());

    app(ReserveBook::class)->handle([
        'book_id' => (int) $catalog['book']->getKey(),
        'library_member_id' => (int) $waiter->getKey(),
        'reserved_on' => '2031-03-05',
    ], phase9LibActor($user));

    expect(fn () => app(RenewIssue::class)->handle([
        'issue_id' => (int) $issue->getKey(),
        'renewed_on' => '2031-03-10',
    ], phase9LibActor($user)))->toThrow(DomainException::class, 'reservation');
});

it('promotes the queue head on return, parks the copy, notifies via the outbox, and holds it against other members', function (): void {
    $user = phase9LibLibrarian();
    $calendar = phase9LibCalendar();
    $class = phase9LibMembershipClass();
    $catalog = phase9LibCatalog($user, copies: 1);
    $borrower = phase9LibStudentMember($user, $calendar['academic_year_id'], $class);
    $waiter = phase9LibStudentMember($user, $calendar['academic_year_id'], $class);
    $stranger = phase9LibStudentMember($user, $calendar['academic_year_id'], $class);

    $copyId = (int) $catalog['copies'][0]->getKey();
    $issue = phase9LibIssue($user, $copyId, (int) $borrower->getKey());

    $reservation = app(ReserveBook::class)->handle([
        'book_id' => (int) $catalog['book']->getKey(),
        'library_member_id' => (int) $waiter->getKey(),
        'reserved_on' => '2031-03-05',
    ], phase9LibActor($user));

    $outboxBefore = (int) DB::table('outbox_messages')->count();

    app(ReturnBook::class)->handle([
        'issue_id' => (int) $issue->getKey(),
        'returned_on' => '2031-03-10',
    ], phase9LibActor($user));

    $reservation->refresh();

    expect($reservation->status)->toBe(LibraryReservationStatus::Ready)
        ->and($reservation->book_copy_id)->toBe($copyId)
        ->and(BookCopy::query()->findOrFail($copyId)->status)->toBe(BookCopyStatus::Reserved)
        // 00-core §3: the notification degrades to a QUEUED outbox row.
        ->and((int) DB::table('outbox_messages')->count())->toBe($outboxBefore + 1)
        ->and((int) DB::table('outbox_messages')
            ->where('subject_type', 'library_reservation')
            ->where('subject_id', $reservation->getKey())
            ->where('status', 'queued')
            ->count())->toBe(1);

    // The parked copy is HELD: another member cannot take it...
    expect(fn () => phase9LibIssue($user, $copyId, (int) $stranger->getKey(), '2031-03-11'))
        ->toThrow(DomainException::class, 'held for a reservation');

    // ...but the reservation holder collects it, fulfilling the reservation.
    $collected = phase9LibIssue($user, $copyId, (int) $waiter->getKey(), '2031-03-11');

    expect($collected->status)->toBe(IssueStatus::Open)
        ->and($reservation->refresh()->status)->toBe(LibraryReservationStatus::Fulfilled);
});

it('caps live reservations per member and backs the one-live-reservation rule with uq_live_reservation', function (): void {
    $user = phase9LibLibrarian();
    $calendar = phase9LibCalendar();
    $single = phase9LibMembershipClass(['max_reservations' => 1]);
    $double = phase9LibMembershipClass(['max_reservations' => 2]);
    $catalogA = phase9LibCatalog($user, copies: 1);
    $catalogB = phase9LibCatalog($user, copies: 1);
    $memberA = phase9LibStudentMember($user, $calendar['academic_year_id'], $single);
    $memberB = phase9LibStudentMember($user, $calendar['academic_year_id'], $double);

    app(ReserveBook::class)->handle([
        'book_id' => (int) $catalogA['book']->getKey(),
        'library_member_id' => (int) $memberA->getKey(),
        'reserved_on' => '2031-03-05',
    ], phase9LibActor($user));

    // The class allows ONE live reservation.
    expect(fn () => app(ReserveBook::class)->handle([
        'book_id' => (int) $catalogB['book']->getKey(),
        'library_member_id' => (int) $memberA->getKey(),
        'reserved_on' => '2031-03-05',
    ], phase9LibActor($user)))->toThrow(DomainException::class, 'reservations');

    // A member allowed two may not queue TWICE for the SAME title: the
    // NULL-unique generated key is the last defence.
    app(ReserveBook::class)->handle([
        'book_id' => (int) $catalogA['book']->getKey(),
        'library_member_id' => (int) $memberB->getKey(),
        'reserved_on' => '2031-03-05',
    ], phase9LibActor($user));

    expect(fn () => app(ReserveBook::class)->handle([
        'book_id' => (int) $catalogA['book']->getKey(),
        'library_member_id' => (int) $memberB->getKey(),
        'reserved_on' => '2031-03-06',
    ], phase9LibActor($user)))->toThrow(QueryException::class);

    // Reference-only titles take no reservations at all.
    $reference = phase9LibCatalog($user, copies: 1, bookOverrides: ['is_reference_only' => true]);

    expect(fn () => app(ReserveBook::class)->handle([
        'book_id' => (int) $reference['book']->getKey(),
        'library_member_id' => (int) $memberA->getKey(),
        'reserved_on' => '2031-03-05',
    ], phase9LibActor($user)))->toThrow(DomainException::class, 'reference-only');
});

it('expires uncollected ready reservations and releases the parked copy - idempotently', function (): void {
    $user = phase9LibLibrarian();
    $calendar = phase9LibCalendar();
    $class = phase9LibMembershipClass();
    $catalog = phase9LibCatalog($user, copies: 1);
    $borrower = phase9LibStudentMember($user, $calendar['academic_year_id'], $class);
    $waiter = phase9LibStudentMember($user, $calendar['academic_year_id'], $class);

    $copyId = (int) $catalog['copies'][0]->getKey();
    $issue = phase9LibIssue($user, $copyId, (int) $borrower->getKey());

    $reservation = app(ReserveBook::class)->handle([
        'book_id' => (int) $catalog['book']->getKey(),
        'library_member_id' => (int) $waiter->getKey(),
        'reserved_on' => '2031-03-05',
    ], phase9LibActor($user));

    // Return parks the copy and stamps expires_on = returned + 3 days.
    app(ReturnBook::class)->handle([
        'issue_id' => (int) $issue->getKey(),
        'returned_on' => '2031-03-10',
    ], phase9LibActor($user));

    expect($reservation->refresh()->expires_on)->toBe('2031-03-13');

    // Not yet stale on the expiry day itself.
    expect(app(ExpireReservations::class)->handle('2031-03-13', phase9LibActor($user)))->toBe(0);

    // Stale the day after: reservation lapses, the copy goes back on the shelf.
    expect(app(ExpireReservations::class)->handle('2031-03-14', phase9LibActor($user)))->toBe(1)
        ->and($reservation->refresh()->status)->toBe(LibraryReservationStatus::Expired)
        ->and(BookCopy::query()->findOrFail($copyId)->status)->toBe(BookCopyStatus::Available);

    // Idempotent: the sweep finds nothing the second time.
    expect(app(ExpireReservations::class)->handle('2031-03-14', phase9LibActor($user)))->toBe(0);
});

it('promotes open loans past due to the PERSISTED overdue state - never on the due day, and idempotently', function (): void {
    $user = phase9LibLibrarian();
    $calendar = phase9LibCalendar();
    $class = phase9LibMembershipClass(); // due 2031-03-16
    $catalog = phase9LibCatalog($user, copies: 2);
    $member = phase9LibStudentMember($user, $calendar['academic_year_id'], $class);

    $issue = phase9LibIssue($user, (int) $catalog['copies'][0]->getKey(), (int) $member->getKey(), '2031-03-02');

    // On the due date the loan is NOT overdue.
    expect(app(PromoteOverdueIssues::class)->handle('2031-03-16', phase9LibActor($user)))->toBe(0)
        ->and($issue->refresh()->status)->toBe(IssueStatus::Open);

    // The day after it is - and the state PERSISTS on the row.
    expect(app(PromoteOverdueIssues::class)->handle('2031-03-17', phase9LibActor($user)))->toBe(1)
        ->and($issue->refresh()->status)->toBe(IssueStatus::Overdue);

    // Idempotent: an already-overdue issue is untouched.
    expect(app(PromoteOverdueIssues::class)->handle('2031-03-18', phase9LibActor($user)))->toBe(0);
});

it('returns a copy to the shelf when nobody waits, and refuses time travel', function (): void {
    $user = phase9LibLibrarian();
    $calendar = phase9LibCalendar();
    $class = phase9LibMembershipClass();
    $catalog = phase9LibCatalog($user, copies: 1);
    $member = phase9LibStudentMember($user, $calendar['academic_year_id'], $class);

    $copyId = (int) $catalog['copies'][0]->getKey();
    $issue = phase9LibIssue($user, $copyId, (int) $member->getKey(), '2031-03-02');

    expect(fn () => app(ReturnBook::class)->handle([
        'issue_id' => (int) $issue->getKey(),
        'returned_on' => '2031-03-01',
    ], phase9LibActor($user)))->toThrow(DomainException::class, 'before it was issued');

    $returned = app(ReturnBook::class)->handle([
        'issue_id' => (int) $issue->getKey(),
        'returned_on' => '2031-03-10',
    ], phase9LibActor($user));

    expect($returned->status)->toBe(IssueStatus::Returned)
        ->and($returned->returned_on)->toBe('2031-03-10')
        ->and(BookCopy::query()->findOrFail($copyId)->status)->toBe(BookCopyStatus::Available)
        ->and(LibraryReservation::query()->count())->toBe(0);

    // A returned loan cannot return twice.
    expect(fn () => app(ReturnBook::class)->handle([
        'issue_id' => (int) $issue->getKey(),
        'returned_on' => '2031-03-11',
    ], phase9LibActor($user)))->toThrow(DomainException::class, 'No open issue');
});
