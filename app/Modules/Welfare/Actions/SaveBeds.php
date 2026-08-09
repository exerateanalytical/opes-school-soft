<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Welfare\Domain\HostelPermission;
use App\Modules\Welfare\Models\HostelBed;
use App\Modules\Welfare\Models\HostelRoom;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/plans/phase-10.md §4 (W2). Declares the bed set of a room as a
 * list of labels, reconciled idempotently BY LABEL:
 *
 *  - labels already present are kept (their allocation history intact),
 *  - new labels are created,
 *  - labels omitted are removed - refused when the bed carries ANY
 *    allocation row (an occupied or historical bed is deactivated via
 *    `is_active`, never deleted).
 *
 * The list may never exceed the room's physical capacity, and labels must
 * be unique within the call (UNIQUE(room_id,label) backs this in schema).
 */
final class SaveBeds
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  list<string>  $labels
     * @return list<HostelBed> the room's beds after reconciliation, in label order
     */
    public function handle(int $roomId, array $labels, Actor $actor): array
    {
        Gate::authorize(HostelPermission::MANAGE);

        $labels = array_map(
            static fn (string $label): string => trim($label),
            $labels,
        );

        $this->validate($labels);

        return DB::transaction(function () use ($roomId, $labels, $actor): array {
            /** @var HostelRoom $room */
            $room = HostelRoom::query()->lockForUpdate()->findOrFail($roomId);

            if (count($labels) > $room->capacity) {
                throw ValidationException::withMessages([
                    'labels' => count($labels)." beds exceed the room's capacity of {$room->capacity}.",
                ]);
            }

            $existing = $room->beds()->lockForUpdate()->get();
            $keep = array_flip($labels);

            $removed = 0;

            foreach ($existing as $bed) {
                if (isset($keep[$bed->label])) {
                    continue;
                }

                $referenced = DB::table('hostel_allocations')
                    ->where('bed_id', $bed->getKey())
                    ->exists();

                if ($referenced) {
                    throw new DomainException(
                        "Bed '{$bed->label}' carries allocation history and cannot "
                        .'be removed; deactivate it instead.'
                    );
                }

                $bed->delete();
                $removed++;
            }

            $present = [];

            foreach ($room->beds()->get() as $bed) {
                $present[$bed->label] = $bed;
            }

            $created = 0;

            foreach ($labels as $label) {
                if (! isset($present[$label])) {
                    $room->beds()->create(['label' => $label, 'is_active' => true]);
                    $created++;
                }
            }

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Welfare',
                auditableType: HostelRoom::class,
                auditableId: (int) $room->getKey(),
                after: [
                    'beds' => $labels,
                    'created' => $created,
                    'removed' => $removed,
                ],
                actor: $actor,
            );

            /** @var list<HostelBed> $beds */
            $beds = $room->beds()->get()->all();

            return $beds;
        });
    }

    /**
     * @param  list<string>  $labels
     */
    private function validate(array $labels): void
    {
        if ($labels === []) {
            throw ValidationException::withMessages([
                'labels' => 'A room needs at least one bed label.',
            ]);
        }

        foreach ($labels as $label) {
            if ($label === '') {
                throw ValidationException::withMessages([
                    'labels' => 'Every bed requires a label.',
                ]);
            }
        }

        if (count($labels) !== count(array_unique($labels))) {
            throw ValidationException::withMessages([
                'labels' => 'Bed labels must be unique within the room.',
            ]);
        }
    }
}
