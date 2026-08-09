<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Welfare\Domain\HostelPermission;
use App\Modules\Welfare\Domain\InspectionRating;
use App\Modules\Welfare\Models\Hostel;
use App\Modules\Welfare\Models\HostelInspection;
use App\Modules\Welfare\Models\HostelRoom;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/plans/phase-10.md §4 (W2). Files a dated walk-through of a hostel
 * or one of its rooms. Findings are operational text - any resulting
 * damage charge is the operator-driven fees flow (StudentObligationSource
 * is a tracked debt), never automated here. `resolve()` closes the loop
 * when the findings are confirmed fixed.
 */
final class RecordInspection
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  array{room_id?: int|null, inspected_by?: int|null, findings?: string|null}  $extra
     */
    public function handle(
        int $hostelId,
        Carbon $inspectedOn,
        InspectionRating $rating,
        array $extra,
        Actor $actor,
    ): HostelInspection {
        Gate::authorize(HostelPermission::MANAGE);

        return DB::transaction(function () use ($hostelId, $inspectedOn, $rating, $extra, $actor): HostelInspection {
            /** @var Hostel $hostel */
            $hostel = Hostel::query()->findOrFail($hostelId);

            $roomId = $extra['room_id'] ?? null;

            if ($roomId !== null) {
                /** @var HostelRoom $room */
                $room = HostelRoom::query()->findOrFail($roomId);

                if ($room->hostel_id !== (int) $hostel->getKey()) {
                    throw new DomainException(
                        "Room '{$room->name}' does not belong to hostel {$hostel->code}."
                    );
                }
            }

            $inspectedBy = $extra['inspected_by'] ?? $actor->id;

            if ($inspectedBy !== null
                && DB::table('users')->where('id', $inspectedBy)->doesntExist()) {
                throw ValidationException::withMessages([
                    'inspected_by' => 'The referenced inspector user does not exist.',
                ]);
            }

            $inspection = HostelInspection::query()->create([
                'hostel_id' => (int) $hostel->getKey(),
                'room_id' => $roomId,
                'inspected_on' => $inspectedOn,
                'inspected_by' => $inspectedBy,
                'rating' => $rating,
                'findings' => $extra['findings'] ?? null,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Welfare',
                auditableType: HostelInspection::class,
                auditableId: (int) $inspection->getKey(),
                after: [
                    'hostel' => $hostel->code,
                    'room_id' => $roomId,
                    'inspected_on' => $inspectedOn->toDateString(),
                    'rating' => $rating->value,
                ],
                actor: $actor,
            );

            return $inspection->refresh();
        });
    }

    /** Marks an inspection's findings as fixed. */
    public function resolve(int $inspectionId, Actor $actor): HostelInspection
    {
        Gate::authorize(HostelPermission::MANAGE);

        return DB::transaction(function () use ($inspectionId, $actor): HostelInspection {
            /** @var HostelInspection $inspection */
            $inspection = HostelInspection::query()->lockForUpdate()->findOrFail($inspectionId);

            if ($inspection->resolved_at !== null) {
                throw new DomainException('This inspection is already resolved.');
            }

            $inspection->fill(['resolved_at' => Carbon::now()])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Welfare',
                auditableType: HostelInspection::class,
                auditableId: (int) $inspection->getKey(),
                after: ['resolved_at' => $inspection->resolved_at?->toIso8601String()],
                actor: $actor,
            );

            return $inspection->refresh();
        });
    }
}
