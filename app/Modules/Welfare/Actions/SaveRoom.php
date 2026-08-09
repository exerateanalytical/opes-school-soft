<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Welfare\Domain\HostelPermission;
use App\Modules\Welfare\Models\Hostel;
use App\Modules\Welfare\Models\HostelRoom;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/plans/phase-10.md §4 (W2). Creates or updates a room inside a
 * hostel. Capacity can never drop below the number of beds already
 * standing in the room - shrink the bed set first (SaveBeds), which in
 * turn refuses while any of those beds is occupied.
 */
final class SaveRoom
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(?int $roomId, array $data, Actor $actor): HostelRoom
    {
        Gate::authorize(HostelPermission::MANAGE);

        return DB::transaction(function () use ($roomId, $data, $actor): HostelRoom {
            $existing = null;

            if ($roomId !== null) {
                /** @var HostelRoom $existing */
                $existing = HostelRoom::query()->lockForUpdate()->findOrFail($roomId);
                // A room never moves between buildings; history would lie.
                unset($data['hostel_id']);
            }

            $this->validate($data, $existing);

            if ($existing !== null) {
                $existing->fill($data)->save();
                $room = $existing;
                $auditAction = AuditAction::Updated;
            } else {
                $room = HostelRoom::query()->create($data);
                $auditAction = AuditAction::Created;
            }

            $this->audit->handle(
                action: $auditAction,
                module: 'Welfare',
                auditableType: HostelRoom::class,
                auditableId: (int) $room->getKey(),
                after: [
                    'hostel_id' => $room->hostel_id,
                    'name' => $room->name,
                    'capacity' => $room->capacity,
                ],
                actor: $actor,
            );

            return $room->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validate(array $data, ?HostelRoom $existing): void
    {
        if ($existing === null) {
            $hostelId = $data['hostel_id'] ?? null;

            if (! is_numeric($hostelId)) {
                throw ValidationException::withMessages([
                    'hostel_id' => 'A room must belong to a hostel.',
                ]);
            }

            if (Hostel::query()->whereKey((int) $hostelId)->doesntExist()) {
                throw ValidationException::withMessages([
                    'hostel_id' => 'The referenced hostel does not exist.',
                ]);
            }

            if (trim((string) ($data['name'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    'name' => 'A room requires a name.',
                ]);
            }
        }

        $name = $data['name'] ?? null;

        if ($name !== null) {
            $hostelId = $existing !== null ? $existing->hostel_id : (int) $data['hostel_id'];

            $clash = HostelRoom::query()
                ->where('hostel_id', $hostelId)
                ->where('name', (string) $name)
                ->when($existing !== null, fn ($q) => $q->whereKeyNot($existing?->getKey()))
                ->exists();

            if ($clash) {
                throw ValidationException::withMessages([
                    'name' => 'A room with this name already exists in the hostel.',
                ]);
            }
        }

        $capacity = $existing === null
            ? ($data['capacity'] ?? null)
            : ($data['capacity'] ?? $existing->capacity);

        if (! is_numeric($capacity) || (int) $capacity < 1) {
            throw ValidationException::withMessages([
                'capacity' => 'Room capacity must be at least one bed.',
            ]);
        }

        if ($existing !== null) {
            $bedCount = (int) $existing->beds()->count();

            if ((int) $capacity < $bedCount) {
                throw ValidationException::withMessages([
                    'capacity' => "Capacity cannot drop below the {$bedCount} beds already in the room; remove beds first.",
                ]);
            }
        }
    }
}
