<?php

declare(strict_types=1);

namespace App\Modules\Library\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Library\Actions\Concerns\ChecksMemberStanding;
use App\Modules\Library\Domain\IssueStatus;
use App\Modules\Library\Domain\LibraryPermission;
use App\Modules\Library\Domain\LibraryReservationStatus;
use App\Modules\Library\Models\LibraryIssue;
use App\Modules\Library\Models\LibraryMember;
use App\Modules\Library\Models\LibraryRenewal;
use App\Modules\Library\Models\MembershipClass;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 06-assets-stores.md §10.4 - renewal. REFUSED when the title has an
 * active reservation (someone is waiting for exactly this copy to come
 * back) or the member's unpaid fines exceed the class threshold. The
 * renewal row is append-only history; the issue's due date moves.
 */
final class RenewIssue
{
    use ChecksMemberStanding;

    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param array{issue_id: int, renewed_on: string} $data
     */
    public function handle(array $data, Actor $actor): LibraryIssue
    {
        Gate::authorize(LibraryPermission::CIRCULATE);

        return DB::transaction(function () use ($data, $actor): LibraryIssue {
            /** @var LibraryIssue $issue */
            $issue = LibraryIssue::query()->lockForUpdate()->findOrFail($data['issue_id']);

            if (! in_array($issue->status, [IssueStatus::Open, IssueStatus::Overdue], true)) {
                throw new DomainException(
                    "Issue {$issue->issue_no} is {$issue->status->value}; only an open loan renews."
                );
            }

            /** @var LibraryMember $member */
            $member = LibraryMember::query()->lockForUpdate()->findOrFail($issue->library_member_id);

            /** @var MembershipClass $class */
            $class = MembershipClass::query()->findOrFail($member->membership_class_id);

            if ($issue->renewal_count >= $class->max_renewals) {
                throw new DomainException(sprintf(
                    'Issue %s has used all %d renewals for this membership class.',
                    $issue->issue_no,
                    $class->max_renewals,
                ));
            }

            $bookId = (int) DB::table('book_copies')->where('id', $issue->book_copy_id)->value('book_id');

            $reservationWaiting = DB::table('library_reservations')
                ->where('book_id', $bookId)
                ->whereIn('status', [
                    LibraryReservationStatus::Waiting->value,
                    LibraryReservationStatus::Ready->value,
                ])
                ->exists();

            if ($reservationWaiting) {
                throw new DomainException(
                    "Renewal refused: '{$issue->issue_no}' has an active reservation waiting for the title (§10.4)."
                );
            }

            $this->assertNoBlockingFine($member, $class, 'Renewal');

            $previousDue = $issue->due_on;
            $newDue = Carbon::parse($previousDue)->addDays($class->renewal_days)->toDateString();

            LibraryRenewal::query()->create([
                'library_issue_id' => (int) $issue->getKey(),
                'renewed_on' => $data['renewed_on'],
                'previous_due_on' => $previousDue,
                'new_due_on' => $newDue,
                'renewed_by' => $actor->id,
            ]);

            $issue->forceFill([
                'due_on' => $newDue,
                'renewal_count' => $issue->renewal_count + 1,
                // A renewed overdue loan is open again until the new date passes.
                'status' => IssueStatus::Open,
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Library',
                auditableType: LibraryIssue::class,
                auditableId: (int) $issue->getKey(),
                before: ['due_on' => $previousDue],
                after: ['due_on' => $newDue, 'renewal_count' => $issue->renewal_count],
                actor: $actor,
            );

            return $issue->refresh();
        });
    }
}
