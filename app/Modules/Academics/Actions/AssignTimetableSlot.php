<?php

declare(strict_types=1);

namespace App\Modules\Academics\Actions;

use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Academics\Models\ClassGroup;
use App\Modules\Academics\Models\ClassLevel;
use App\Modules\Academics\Models\Room;
use App\Modules\Academics\Models\Subject;
use App\Modules\Academics\Models\TimetablePeriod;
use App\Modules\Academics\Models\TimetableSlot;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Put a subject·teacher·room into one cell of the weekly grid.
 *
 * 09-ui §8.6: "Conflict detection is an invariant, not a warning." The three
 * rules — slot_taken, teacher_busy, room_double_booked — are UNIQUE
 * constraints on timetable_slots, NOT application checks. This Action's only
 * job on conflict is to translate the violated index into a domain error a
 * human can act on; it never pre-checks and races.
 *
 * The teacher is validated through DB::table('staff_members'): StaffMember is
 * an HR model and the module-boundary test forbids importing it here.
 */
final class AssignTimetableSlot
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(
        int $classGroupId,
        int $dayOfWeek,
        int $timetablePeriodId,
        int $subjectId,
        int $staffMemberId,
        ?int $roomId = null,
        ?string $effectiveFrom = null,
        ?string $effectiveTo = null,
    ): TimetableSlot {
        Gate::authorize(Permission::TimetableManage->value);

        if ($dayOfWeek < 1 || $dayOfWeek > 6) {
            throw ValidationException::withMessages([
                'day_of_week' => 'The timetable week runs Monday (1) to Saturday (6).',
            ]);
        }

        /** @var ClassGroup $classGroup */
        $classGroup = ClassGroup::query()->findOrFail($classGroupId);

        /** @var TimetablePeriod $period */
        $period = TimetablePeriod::query()->findOrFail($timetablePeriodId);

        /** @var ClassLevel $level */
        $level = ClassLevel::query()->findOrFail($classGroup->class_level_id);

        if ($period->school_section_id !== $level->school_section_id) {
            throw ValidationException::withMessages([
                'timetable_period_id' => 'This period belongs to another section\'s bell schedule.',
            ]);
        }

        if ($period->is_break) {
            throw ValidationException::withMessages([
                'timetable_period_id' => 'Break rows take no lessons.',
            ]);
        }

        $subject = Subject::query()->findOrFail($subjectId);

        if (! $subject->is_active) {
            throw ValidationException::withMessages([
                'subject_id' => 'This subject is inactive.',
            ]);
        }

        $staff = DB::table('staff_members')->where('id', $staffMemberId)->first();

        if ($staff === null || (string) $staff->status !== 'active') {
            throw ValidationException::withMessages([
                'staff_member_id' => 'The selected teacher does not exist or is not active.',
            ]);
        }

        if ($roomId !== null) {
            Room::query()->findOrFail($roomId);
        }

        $from = $effectiveFrom === null
            ? Carbon::parse(
                AcademicYear::query()->findOrFail($classGroup->academic_year_id)
                    ->starts_on->toDateString()
            )
            : Carbon::parse($effectiveFrom);

        $to = $effectiveTo === null ? null : Carbon::parse($effectiveTo);

        if ($to !== null && $to->lt($from)) {
            throw ValidationException::withMessages([
                'effective_to' => 'The effective range ends before it starts.',
            ]);
        }

        try {
            return DB::transaction(function () use (
                $classGroup, $dayOfWeek, $timetablePeriodId, $subjectId,
                $staffMemberId, $roomId, $from, $to
            ): TimetableSlot {
                $slot = TimetableSlot::query()->create([
                    'class_group_id' => (int) $classGroup->getKey(),
                    'academic_year_id' => $classGroup->academic_year_id,
                    'day_of_week' => $dayOfWeek,
                    'timetable_period_id' => $timetablePeriodId,
                    'subject_id' => $subjectId,
                    'staff_member_id' => $staffMemberId,
                    'room_id' => $roomId,
                    'effective_from' => $from->toDateString(),
                    'effective_to' => $to?->toDateString(),
                    // Gate::authorize above has already rejected guests, so an
                    // authenticated id is guaranteed here.
                    'created_by' => (int) auth()->id(),
                ]);

                $this->audit->handle(
                    action: AuditAction::Created,
                    module: 'Academics',
                    auditableType: TimetableSlot::class,
                    auditableId: (int) $slot->getKey(),
                    after: [
                        'class_group_id' => (int) $classGroup->getKey(),
                        'day_of_week' => $dayOfWeek,
                        'timetable_period_id' => $timetablePeriodId,
                        'subject_id' => $subjectId,
                        'staff_member_id' => $staffMemberId,
                        'room_id' => $roomId,
                    ],
                    actor: auth()->user()?->toAuditActor() ?? Actor::system(),
                );

                return $slot;
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw new DomainException($this->conflictMessage($exception));
        }
    }

    /**
     * Name the violated conflict rule from the violated index — the message
     * MySQL raises carries the key name.
     */
    private function conflictMessage(UniqueConstraintViolationException $exception): string
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'uq_slot_staff')) {
            return 'teacher_busy: this teacher is already booked in another class for that day and period.';
        }

        if (str_contains($message, 'uq_slot_room')) {
            return 'room_double_booked: this room is already occupied for that day and period.';
        }

        return 'slot_taken: this class group already has an entry for that day and period.';
    }
}
