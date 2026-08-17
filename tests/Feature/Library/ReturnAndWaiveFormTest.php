<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Permission;
use App\Modules\Library\Domain\BookCopyStatus;
use App\Modules\Library\Domain\LibraryPermission;
use App\Modules\Library\Livewire\Index;
use App\Modules\Library\Models\BookCopy;
use App\Modules\Library\Models\LibraryIssue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__.'/LibraryTestHelpers.php';

uses(RefreshDatabase::class);

/*
 * The circulation desk used to fire a one-click return that hardcoded
 * `returned_on` to today and never passed a `condition` - so ReturnBook's
 * damaged-return branch (which moves the copy to BookCopyStatus::Damaged) was
 * unreachable from the UI, and a book handed in on Friday but keyed on Monday
 * was dated wrong. WaiveFine likewise received a canned reason literal, which
 * defeated the §10.6 audit control it exists to provide.
 */

if (! function_exists('libUiDeskUser')) {
    /** A desk user who can also open the screen (mount gates library.view). */
    function libUiDeskUser(): App\Modules\Identity\Models\User
    {
        return phase9LibUser(
            LibraryPermission::VIEW,
            LibraryPermission::MANAGE,
            LibraryPermission::CIRCULATE,
            LibraryPermission::WAIVE_FINE,
            Permission::FeeCollect->value,
            Permission::LedgerPost->value,
        );
    }
}

if (! function_exists('libUiOpenIssue')) {
    /** An open issue plus the desk user who made it. */
    function libUiOpenIssue(): array
    {
        $user = libUiDeskUser();
        $calendar = phase9LibCalendar();
        $class = phase9LibMembershipClass();
        $catalog = phase9LibCatalog($user, copies: 1);
        $member = phase9LibStudentMember($user, $calendar['academic_year_id'], $class);

        $issue = phase9LibIssue(
            $user,
            (int) $catalog['copies'][0]->getKey(),
            (int) $member->getKey(),
            '2031-03-02',
        );

        return ['user' => $user, 'issue' => $issue];
    }
}

it('returns a copy in good condition on a back-dated day', function (): void {
    ['issue' => $issue] = libUiOpenIssue();

    Livewire::test(Index::class)
        ->call('startReturn', $issue->getKey())
        ->assertSet('returnCondition', 'good')
        ->set('returnedOn', '2031-03-06')
        ->call('returnIssue')
        ->assertHasNoErrors();

    $issue->refresh();

    expect((string) $issue->returned_on)->toContain('2031-03-06')
        ->and(BookCopy::query()->findOrFail($issue->book_copy_id)->status)
        ->toBe(BookCopyStatus::Available);
});

it('marks the copy damaged when the return panel says so', function (): void {
    ['issue' => $issue] = libUiOpenIssue();

    Livewire::test(Index::class)
        ->call('startReturn', $issue->getKey())
        ->set('returnCondition', 'damaged')
        ->set('returnedOn', '2031-03-06')
        ->call('returnIssue')
        ->assertHasNoErrors();

    expect(BookCopy::query()->findOrFail($issue->book_copy_id)->status)
        ->toBe(BookCopyStatus::Damaged);
});

it('refuses a return with no date', function (): void {
    ['issue' => $issue] = libUiOpenIssue();

    Livewire::test(Index::class)
        ->call('startReturn', $issue->getKey())
        ->set('returnedOn', '')
        ->call('returnIssue')
        ->assertHasErrors(['returnedOn']);

    expect(LibraryIssue::query()->findOrFail($issue->getKey())->returned_on)->toBeNull();
});

it('refuses a waiver with no reason', function (): void {
    libUiDeskUser();

    // Validation runs before the Action, so an id is all this needs.
    Livewire::test(Index::class)
        ->set('waivingFineId', 1)
        ->set('waiveReason', '')
        ->set('waivedOn', '2031-03-06')
        ->call('waiveFine')
        ->assertHasErrors(['waiveReason']);
});

it('refuses a waiver with no date', function (): void {
    libUiDeskUser();

    Livewire::test(Index::class)
        ->set('waivingFineId', 1)
        ->set('waiveReason', 'Hardship, approved by the principal')
        ->set('waivedOn', '')
        ->call('waiveFine')
        ->assertHasErrors(['waivedOn']);
});
