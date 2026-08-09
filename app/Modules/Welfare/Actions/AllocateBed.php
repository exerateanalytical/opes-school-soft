<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Welfare\Domain\AllocationStatus;
use App\Modules\Welfare\Domain\HostelPermission;
use App\Modules\Welfare\Models\HostelAllocation;
use App\Modules\Welfare\Models\HostelBed;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/plans/phase-10.md §4 (W2). Puts a student (an ENROLLMENT - the
 * year-scoped identity, 07-students line 39) into a hostel bed.
 *
 *  - The enrollment must exist and be ACTIVE - read via DB::table joined
 *    to students for the gender gate, never through the Students Models
 *    (ModuleBoundaryTest).
 *  - The bed must be active, in an active hostel, and the hostel's gender
 *    rule must admit the student.
 *  - The bed must be FREE, and any prior active allocation the student
 *    holds is ENDED atomically in the same transaction. The schema's two
 *    NULL-unique generated keys back BOTH invariants against every other
 *    code path, so a race loses with a duplicate-key error instead of a
 *    silent double booking.
 *
 * No billing here: boarding fees flow through Phase 6 audience criteria
 * (`boarding_status`), and a mid-year change is the operator-driven
 * supplementary-invoice flow (04-fees timing rule H).
 */
final class AllocateBed
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(
        int $enrollmentId,
        int $bedId,
        Carbon $startsOn,
        Actor $actor,
    ): HostelAllocation {
        Gate::authorize(HostelPermission::MANAGE);

        return DB::transaction(function () use ($enrollmentId, $bedId, $startsOn, $actor): HostelAllocation {
            /** @var object{id: int|string, status: string, gender: string}|null $enrollment */
            $enrollment = DB::table('enrollments as e')
                ->join('students as s', 's.id', '=', 'e.student_id')
                ->where('e.id', $enrollmentId)
                ->lockForUpdate()
                ->first(['e.id', 'e.status', 's.gender']);

            if ($enrollment === null) {
                throw new DomainException('The enrollment does not exist.');
            }

            if ($enrollment->status !== 'active') {
                throw new DomainException(
                    'Only an ACTIVE enrollment can be allocated a hostel bed; '
                    ."this one is '{$enrollment->status}'."
                );
            }

            /** @var HostelBed $bed */
            $bed = HostelBed::query()->lockForUpdate()->findOrFail($bedId);

            if (! $bed->is_active) {
                throw new DomainException("Bed '{$bed->label}' is inactive and cannot be allocated.");
            }

            $room = $bed->room()->firstOrFail();
            $hostel = $room->hostel()->firstOrFail();

            if (! $hostel->is_active) {
                throw new DomainException("Hostel {$hostel->code} is inactive and accepts no new residents.");
            }

            if (! $hostel->gender->admits($enrollment->gender)) {
                throw new DomainException(
                    "Hostel {$hostel->code} admits {$hostel->gender->value} only; "
                    ."this student's gender does not match."
                );
            }

            // Friendly capacity check before the UNIQUE(active_bed_key)
            // safety net: a taken bed is a normal operator mistake, not a
            // race, and deserves a readable message. The student's OWN
            // active allocation doesn't count - it is ended below, so
            // re-allocating the same bed (a fresh stay) stays legal.
            $occupied = HostelAllocation::query()
                ->where('bed_id', $bedId)
                ->where('status', AllocationStatus::Active->value)
                ->where('enrollment_id', '!=', $enrollmentId)
                ->lockForUpdate()
                ->exists();

            if ($occupied) {
                throw new DomainException(
                    "Bed '{$bed->label}' in room '{$room->name}' is already occupied."
                );
            }

            $prior = HostelAllocation::query()
                ->where('enrollment_id', $enrollmentId)
                ->where('status', AllocationStatus::Active->value)
                ->lockForUpdate()
                ->first();

            if ($prior !== null) {
                // End the day before the new bed starts, clamped so a
                // same-day move never violates CHECK(ends_on >= starts_on).
                $endsOn = $startsOn->copy()->subDay();

                if ($endsOn->lessThan($prior->starts_on)) {
                    $endsOn = $prior->starts_on->copy();
                }

                $prior->fill([
                    'status' => AllocationStatus::Ended,
                    'ends_on' => $endsOn,
                ])->save();
            }

            $allocation = HostelAllocation::query()->create([
                'enrollment_id' => $enrollmentId,
                'bed_id' => $bedId,
                'starts_on' => $startsOn,
                'status' => AllocationStatus::Active,
                'allocated_by' => $actor->id,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Welfare',
                auditableType: HostelAllocation::class,
                auditableId: (int) $allocation->getKey(),
                before: $prior === null ? null : [
                    'ended_allocation_id' => (int) $prior->getKey(),
                ],
                after: [
                    'enrollment_id' => $enrollmentId,
                    'hostel' => $hostel->code,
                    'room' => $room->name,
                    'bed' => $bed->label,
                    'starts_on' => $startsOn->toDateString(),
                ],
                actor: $actor,
            );

            return $allocation->refresh();
        });
    }
}
