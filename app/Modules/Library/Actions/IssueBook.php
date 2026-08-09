<?php

declare(strict_types=1);

namespace App\Modules\Library\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Library\Actions\Concerns\ChecksMemberStanding;
use App\Modules\Library\Domain\BookCopyStatus;
use App\Modules\Library\Domain\IssueStatus;
use App\Modules\Library\Domain\LibraryPermission;
use App\Modules\Library\Domain\LibraryReservationStatus;
use App\Modules\Library\Domain\MemberStatus;
use App\Modules\Library\Models\BookCopy;
use App\Modules\Library\Models\LibraryIssue;
use App\Modules\Library\Models\LibraryMember;
use App\Modules\Library\Models\LibraryReservation;
use App\Modules\Library\Models\MembershipClass;
use App\Support\Audit\Actor;
use App\Support\Sequence\SequenceAllocator;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 06-assets-stores.md §10.4 - the issue desk, keyboard-first: scan member,
 * scan copy, one request per pair.
 *
 * Locking: `FOR UPDATE` on the book_copies row FIRST, then the member row
 * - in that fixed order (00-core §11), so two desks never deadlock. The
 * generated `uq_open_issue` key is the LAST line of defence: even if a
 * race slips past the status check, the second INSERT dies on the unique
 * key, not on a double loan.
 */
final class IssueBook
{
    use ChecksMemberStanding;

    public function __construct(
        private readonly SequenceAllocator $sequence,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param array{
     *     book_copy_id?: int|null,
     *     barcode?: string|null,
     *     library_member_id?: int|null,
     *     member_no?: string|null,
     *     issued_on: string,
     *     idempotency_key?: string|null,
     * } $data
     */
    public function handle(array $data, Actor $actor): LibraryIssue
    {
        Gate::authorize(LibraryPermission::CIRCULATE);

        $idempotencyKey = $data['idempotency_key'] ?? null;

        if ($idempotencyKey !== null) {
            /** @var LibraryIssue|null $existing */
            $existing = LibraryIssue::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($data, $actor, $idempotencyKey): LibraryIssue {
            // Fixed lock order: copy row, THEN member row (§10.4).
            $copy = $this->lockCopy($data);
            $member = $this->lockMember($data);

            /** @var MembershipClass $class */
            $class = MembershipClass::query()->findOrFail($member->membership_class_id);

            if ($member->status !== MemberStatus::Active) {
                throw new DomainException(
                    "Member {$member->member_no} is {$member->status->value}; only active members borrow."
                );
            }

            if ($member->expires_on !== null && $data['issued_on'] > $member->expires_on) {
                throw new DomainException("Membership {$member->member_no} expired on {$member->expires_on}.");
            }

            $book = $copy->book()->firstOrFail();

            if ($book->is_reference_only && ! $class->can_borrow_reference) {
                throw new DomainException(
                    "'{$book->title}' is reference-only; it never circulates for this membership class (§10.1)."
                );
            }

            if ($copy->status === BookCopyStatus::Reserved) {
                $holder = LibraryReservation::query()
                    ->where('book_copy_id', $copy->getKey())
                    ->where('status', LibraryReservationStatus::Ready->value)
                    ->orderBy('position')
                    ->first();

                if ($holder !== null && (int) $holder->library_member_id !== (int) $member->getKey()) {
                    throw new DomainException(
                        "Copy {$copy->accession_no} is held for a reservation by another member."
                    );
                }
            } elseif ($copy->status !== BookCopyStatus::Available) {
                throw new DomainException(
                    "Copy {$copy->accession_no} is {$copy->status->value}, not available."
                );
            }

            // Under FOR UPDATE on the member row (§10.2's "both directions").
            $openCount = LibraryIssue::query()
                ->where('library_member_id', $member->getKey())
                ->whereIn('status', [IssueStatus::Open->value, IssueStatus::Overdue->value])
                ->count();

            if ($openCount >= $class->max_concurrent_issues) {
                throw new DomainException(sprintf(
                    'Member %s already holds %d of %d allowed concurrent issues.',
                    $member->member_no,
                    $openCount,
                    $class->max_concurrent_issues,
                ));
            }

            $this->assertNoBlockingFine($member, $class, 'Issue');

            $issueNo = sprintf(
                'ISS/%s/%06d',
                Carbon::parse($data['issued_on'])->format('Y'),
                $this->sequence->allocate('library.issue_no'),
            );

            // uq_open_issue catches the race this INSERT could lose.
            /** @var LibraryIssue $issue */
            $issue = LibraryIssue::query()->create([
                'issue_no' => $issueNo,
                'book_copy_id' => (int) $copy->getKey(),
                'library_member_id' => (int) $member->getKey(),
                'issued_on' => $data['issued_on'],
                'due_on' => Carbon::parse($data['issued_on'])->addDays($class->loan_days)->toDateString(),
                'issued_by' => $actor->id,
                'status' => IssueStatus::Open,
                'idempotency_key' => $idempotencyKey,
            ]);

            $copy->forceFill(['status' => BookCopyStatus::Issued])->save();

            // A ready reservation this member held for the copy is fulfilled.
            LibraryReservation::query()
                ->where('book_copy_id', $copy->getKey())
                ->where('library_member_id', $member->getKey())
                ->where('status', LibraryReservationStatus::Ready->value)
                ->update(['status' => LibraryReservationStatus::Fulfilled->value]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Library',
                auditableType: LibraryIssue::class,
                auditableId: (int) $issue->getKey(),
                after: [
                    'issue_no' => $issueNo,
                    'accession_no' => $copy->accession_no,
                    'member_no' => $member->member_no,
                    'due_on' => $issue->due_on,
                ],
                actor: $actor,
            );

            return $issue;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function lockCopy(array $data): BookCopy
    {
        $query = BookCopy::query()->lockForUpdate();

        if (($data['book_copy_id'] ?? null) !== null) {
            $copy = $query->find((int) $data['book_copy_id']);
        } elseif (($data['barcode'] ?? null) !== null) {
            $copy = $query->where('barcode', (string) $data['barcode'])->first();
        } else {
            throw new DomainException('Scan a copy: book_copy_id or barcode is required.');
        }

        if ($copy === null) {
            throw new DomainException('No such book copy.');
        }

        return $copy;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function lockMember(array $data): LibraryMember
    {
        $query = LibraryMember::query()->lockForUpdate();

        if (($data['library_member_id'] ?? null) !== null) {
            $member = $query->find((int) $data['library_member_id']);
        } elseif (($data['member_no'] ?? null) !== null) {
            $member = $query->where('member_no', (string) $data['member_no'])->first();
        } else {
            throw new DomainException('Scan a member: library_member_id or member_no is required.');
        }

        if ($member === null) {
            throw new DomainException('No such library member.');
        }

        return $member;
    }
}
