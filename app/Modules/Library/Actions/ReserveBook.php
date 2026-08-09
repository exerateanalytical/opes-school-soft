<?php

declare(strict_types=1);

namespace App\Modules\Library\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Library\Domain\LibraryPermission;
use App\Modules\Library\Domain\LibraryReservationStatus;
use App\Modules\Library\Domain\MemberStatus;
use App\Modules\Library\Models\Book;
use App\Modules\Library\Models\LibraryMember;
use App\Modules\Library\Models\LibraryReservation;
use App\Modules\Library\Models\MembershipClass;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 06-assets-stores.md §10.4 - a TITLE-level reservation queue. The DB's
 * `uq_live_reservation` (NULL-unique on the generated active flag) is the
 * last defence against a member queuing twice for the same title.
 */
final class ReserveBook
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param array{
     *     book_id: int,
     *     library_member_id: int,
     *     reserved_on: string,
     *     expires_on?: string|null,
     * } $data
     */
    public function handle(array $data, Actor $actor): LibraryReservation
    {
        Gate::authorize(LibraryPermission::CIRCULATE);

        return DB::transaction(function () use ($data, $actor): LibraryReservation {
            /** @var Book $book */
            $book = Book::query()->findOrFail($data['book_id']);

            if ($book->is_reference_only) {
                throw new DomainException("'{$book->title}' is reference-only; it never circulates (§10.1).");
            }

            /** @var LibraryMember $member */
            $member = LibraryMember::query()->lockForUpdate()->findOrFail($data['library_member_id']);

            if ($member->status !== MemberStatus::Active) {
                throw new DomainException("Member {$member->member_no} is {$member->status->value}.");
            }

            /** @var MembershipClass $class */
            $class = MembershipClass::query()->findOrFail($member->membership_class_id);

            $liveCount = LibraryReservation::query()
                ->where('library_member_id', $member->getKey())
                ->whereIn('status', [
                    LibraryReservationStatus::Waiting->value,
                    LibraryReservationStatus::Ready->value,
                ])
                ->count();

            if ($liveCount >= $class->max_reservations) {
                throw new DomainException(sprintf(
                    'Member %s already holds %d of %d allowed reservations.',
                    $member->member_no,
                    $liveCount,
                    $class->max_reservations,
                ));
            }

            $position = 1 + (int) LibraryReservation::query()
                ->where('book_id', $book->getKey())
                ->whereIn('status', [
                    LibraryReservationStatus::Waiting->value,
                    LibraryReservationStatus::Ready->value,
                ])
                ->max('position');

            /** @var LibraryReservation $reservation */
            $reservation = LibraryReservation::query()->create([
                'book_id' => (int) $book->getKey(),
                'library_member_id' => (int) $member->getKey(),
                'reserved_on' => $data['reserved_on'],
                'expires_on' => $data['expires_on'] ?? null,
                'status' => LibraryReservationStatus::Waiting,
                'position' => $position,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Library',
                auditableType: LibraryReservation::class,
                auditableId: (int) $reservation->getKey(),
                after: [
                    'book_id' => (int) $book->getKey(),
                    'member_no' => $member->member_no,
                    'position' => $position,
                ],
                actor: $actor,
            );

            return $reservation;
        });
    }
}
