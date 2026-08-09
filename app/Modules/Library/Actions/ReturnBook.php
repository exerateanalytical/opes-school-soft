<?php

declare(strict_types=1);

namespace App\Modules\Library\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Library\Domain\BookCopyStatus;
use App\Modules\Library\Domain\IssueStatus;
use App\Modules\Library\Domain\LibraryPermission;
use App\Modules\Library\Domain\LibraryReservationStatus;
use App\Modules\Library\Domain\ReturnCondition;
use App\Modules\Library\Models\BookCopy;
use App\Modules\Library\Models\LibraryIssue;
use App\Modules\Library\Models\LibraryReservation;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 06-assets-stores.md §10.4 - the return desk.
 *
 * On return the copy either goes back on the shelf, is parked for the
 * queue-head reservation (`reserved` + reservation `ready` + member
 * notified through the Communication outbox - degrading to a queued row,
 * never a blocking error, 00-core §3), or lands in `damaged`. Damage and
 * loss FINES are separate deliberate acts (LevyFine / MarkIssueLost), not
 * side effects of a scan.
 */
final class ReturnBook
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param array{
     *     issue_id?: int|null,
     *     barcode?: string|null,
     *     returned_on: string,
     *     condition?: string,
     * } $data
     */
    public function handle(array $data, Actor $actor): LibraryIssue
    {
        Gate::authorize(LibraryPermission::CIRCULATE);

        return DB::transaction(function () use ($data, $actor): LibraryIssue {
            $issue = $this->lockOpenIssue($data);

            /** @var BookCopy $copy */
            $copy = BookCopy::query()->lockForUpdate()->findOrFail($issue->book_copy_id);

            $condition = ReturnCondition::from($data['condition'] ?? ReturnCondition::Good->value);

            if ($condition === ReturnCondition::Lost) {
                throw new DomainException(
                    'A lost book is not a return: use MarkIssueLost, which levies the replacement-cost fine (§10.5).'
                );
            }

            if ($data['returned_on'] < $issue->issued_on) {
                throw new DomainException('A copy cannot return before it was issued.');
            }

            $issue->forceFill([
                'status' => IssueStatus::Returned,
                'returned_on' => $data['returned_on'],
                'received_by' => $actor->id,
                'return_condition' => $condition,
            ])->save();

            $promoted = null;

            if ($condition === ReturnCondition::Damaged) {
                $copy->forceFill(['status' => BookCopyStatus::Damaged])->save();
            } else {
                $promoted = $this->promoteReservation($issue, $copy, $data['returned_on']);

                $copy->forceFill([
                    'status' => $promoted === null ? BookCopyStatus::Available : BookCopyStatus::Reserved,
                ])->save();
            }

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Library',
                auditableType: LibraryIssue::class,
                auditableId: (int) $issue->getKey(),
                before: ['status' => 'open'],
                after: [
                    'status' => 'returned',
                    'returned_on' => $data['returned_on'],
                    'condition' => $condition->value,
                    'reservation_promoted' => $promoted?->getKey(),
                ],
                actor: $actor,
            );

            return $issue->refresh();
        });
    }

    /**
     * The §10.4 queue: the waiting head takes the copy, goes `ready`, and
     * is notified via the Communication outbox.
     */
    private function promoteReservation(LibraryIssue $issue, BookCopy $copy, string $returnedOn): ?LibraryReservation
    {
        /** @var LibraryReservation|null $head */
        $head = LibraryReservation::query()
            ->where('book_id', $copy->book_id)
            ->where('status', LibraryReservationStatus::Waiting->value)
            ->orderBy('position')
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if ($head === null) {
            return null;
        }

        $head->forceFill([
            'status' => LibraryReservationStatus::Ready,
            'book_copy_id' => (int) $copy->getKey(),
            'notified_at' => now(),
            'expires_on' => $head->expires_on ?? Carbon::parse($returnedOn)->addDays(3)->toDateString(),
        ])->save();

        $this->queueNotification($head, $copy);

        return $head;
    }

    /**
     * 00-core §3: messaging degrades to a queued outbox, never a blocking
     * error. No gateway is configured in Phase 9, so the row simply waits
     * for Phase 12's Communication dispatcher to drain it.
     */
    private function queueNotification(LibraryReservation $reservation, BookCopy $copy): void
    {
        /** @var object{member_no: string, external_contact: string|null}|null $member */
        $member = DB::table('library_members')
            ->where('id', $reservation->library_member_id)
            ->first(['member_no', 'external_contact']);

        $title = (string) DB::table('books')->where('id', $copy->book_id)->value('title');

        DB::table('outbox_messages')->insert([
            'channel' => 'sms',
            'recipient' => $member?->external_contact ?? ($member->member_no ?? 'unknown'),
            'subject_type' => 'library_reservation',
            'subject_id' => (int) $reservation->getKey(),
            'language' => 'en',
            'subject_line' => null,
            'body' => sprintf(
                "Your reserved book '%s' is ready for collection at the library (copy %s).",
                $title,
                $copy->accession_no,
            ),
            'status' => 'queued',
            'attempts' => 0,
            'queued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function lockOpenIssue(array $data): LibraryIssue
    {
        $query = LibraryIssue::query()
            ->whereIn('status', [IssueStatus::Open->value, IssueStatus::Overdue->value])
            ->lockForUpdate();

        if (($data['issue_id'] ?? null) !== null) {
            $issue = $query->find((int) $data['issue_id']);
        } elseif (($data['barcode'] ?? null) !== null) {
            $copyId = DB::table('book_copies')->where('barcode', (string) $data['barcode'])->value('id');
            $issue = $copyId === null ? null : $query->where('book_copy_id', (int) $copyId)->first();
        } else {
            throw new DomainException('Scan the copy or name the issue to return.');
        }

        if ($issue === null) {
            throw new DomainException('No open issue matches that scan.');
        }

        return $issue;
    }
}
