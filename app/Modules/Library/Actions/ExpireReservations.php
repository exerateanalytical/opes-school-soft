<?php

declare(strict_types=1);

namespace App\Modules\Library\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Library\Domain\BookCopyStatus;
use App\Modules\Library\Domain\LibraryPermission;
use App\Modules\Library\Domain\LibraryReservationStatus;
use App\Modules\Library\Models\BookCopy;
use App\Modules\Library\Models\LibraryReservation;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;

/**
 * 06-assets-stores.md §10.4 - the nightly reservation-expiry sweep. A
 * `ready` reservation past its expiry releases its parked copy back to
 * the shelf (or to the next waiter, which the next return/issue cycle
 * handles); a stale `waiting` one simply lapses. Idempotent.
 */
final class ExpireReservations
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /** @return int reservations expired */
    public function handle(string $asOf, Actor $actor): int
    {
        if ($actor->id !== null) {
            Gate::authorize(LibraryPermission::CIRCULATE);
        }

        return DB::transaction(function () use ($asOf, $actor): int {
            /** @var list<LibraryReservation> $stale */
            $stale = LibraryReservation::query()
                ->whereIn('status', [
                    LibraryReservationStatus::Waiting->value,
                    LibraryReservationStatus::Ready->value,
                ])
                ->whereNotNull('expires_on')
                ->where('expires_on', '<', $asOf)
                ->lockForUpdate()
                ->get()
                ->all();

            foreach ($stale as $reservation) {
                if ($reservation->status === LibraryReservationStatus::Ready
                    && $reservation->book_copy_id !== null) {
                    /** @var BookCopy|null $copy */
                    $copy = BookCopy::query()->lockForUpdate()->find($reservation->book_copy_id);

                    if ($copy !== null && $copy->status === BookCopyStatus::Reserved) {
                        $copy->forceFill(['status' => BookCopyStatus::Available])->save();
                    }
                }

                $reservation->forceFill(['status' => LibraryReservationStatus::Expired])->save();
            }

            if ($stale !== []) {
                $this->audit->handle(
                    action: AuditAction::Updated,
                    module: 'Library',
                    auditableType: LibraryReservation::class,
                    auditableId: null,
                    after: ['expired' => count($stale), 'as_of' => $asOf],
                    actor: $actor,
                );
            }

            return count($stale);
        });
    }
}
