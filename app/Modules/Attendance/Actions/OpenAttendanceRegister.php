<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Actions;

use App\Modules\Academics\Actions\ResolveCalendarDay;
use App\Modules\Attendance\Actions\Concerns\ResolvesRoster;
use App\Modules\Attendance\Domain\AttendanceMode;
use App\Modules\Attendance\Domain\RegisterSession;
use App\Modules\Attendance\Domain\RegisterStatus;
use App\Modules\Attendance\Models\AttendanceRegister;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Opens the first-class register header (07-students §9.3/§9.5/§9.9).
 *
 * Gates, in order:
 *  1. `attendance.take` — the outer permission.
 *  2. Teacher-assignment: a plain teacher may open a register only for a
 *     class group they hold a current subject allocation for (enforced HERE,
 *     not the UI — §9.9). Leadership holding `attendance.amend` bypasses it,
 *     the same outer-gate/inner-scope split as marks.enter.
 *  3. Calendar-day: the date must resolve (a missing calendar BLOCKS with a
 *     clear message, never defaults to "teaching") and its day_type must be
 *     teaching/exam. Any other type needs the override permission
 *     (`attendance.amend`) AND a reason — schools do hold Saturday and
 *     holiday classes, and refusing outright is worse than recording the
 *     exception (§9.3).
 *
 * `expected_count` is resolved in-transaction (§9.5) and FROZEN. Re-opening
 * the same (class, date, session, slot) is idempotent while the register is
 * still open; once submitted it is a refusal, and the uq_register_daily
 * UNIQUE is the concurrency backstop.
 */
final class OpenAttendanceRegister
{
    use ResolvesRoster;

    public function __construct(
        private readonly ResolveCalendarDay $resolveCalendarDay,
        private readonly WriteAuditEntry $audit,
    ) {}

    public function handle(
        int $classGroupId,
        ?string $date = null,
        RegisterSession $session = RegisterSession::FullDay,
        ?int $timetableSlotId = null,
        ?string $overrideReason = null,
    ): AttendanceRegister {
        Gate::authorize(Permission::AttendanceTake->value);

        // business_date at creation (§9.3): defaults to today.
        $day = Carbon::parse($date ?? now()->toDateString())->toDateString();

        $group = DB::table('class_groups')->where('id', $classGroupId)->first();

        if ($group === null) {
            throw ValidationException::withMessages([
                'class_group_id' => 'The selected class group does not exist.',
            ]);
        }

        $mode = AttendanceMode::from((string) $group->attendance_mode);

        $this->assertTakerMayOpen($group);

        $this->assertCalendarAllows($group, $day, $overrideReason);

        [$slot, $subjectId, $lessonMinutes] = $this->resolveSlot($mode, $classGroupId, $timetableSlotId);

        // Per-lesson registers are keyed by their slot, not a session half.
        if ($mode === AttendanceMode::PerLesson) {
            $session = RegisterSession::FullDay;
        }

        try {
            return DB::transaction(function () use (
                $classGroupId, $group, $day, $session, $mode, $slot, $subjectId, $lessonMinutes
            ): AttendanceRegister {
                $slotKey = $slot === null ? AttendanceRegister::SLOT_NONE : (int) $slot->id;

                $existing = AttendanceRegister::query()
                    ->where('class_group_id', $classGroupId)
                    ->whereDate('date', $day)
                    ->where('session', $session->value)
                    ->where('timetable_slot_id', $slotKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    if ($existing->status === RegisterStatus::Open) {
                        // Idempotent re-open: same draft, no second header.
                        return $existing;
                    }

                    throw ValidationException::withMessages([
                        'date' => sprintf(
                            'The register for this class on %s has already been submitted; '
                            .'corrections go through amendment, not a second register.',
                            $day,
                        ),
                    ]);
                }

                // §9.5 in-transaction; FROZEN on the header.
                $expected = count($this->rosterEnrollmentIds($classGroupId, $day));

                $register = AttendanceRegister::query()->create([
                    'class_group_id' => $classGroupId,
                    'academic_year_id' => (int) $group->academic_year_id,
                    'date' => $day,
                    'session' => $session,
                    'timetable_slot_id' => $slotKey,
                    'subject_id' => $subjectId,
                    'mode' => $mode,
                    'expected_count' => $expected,
                    // Default all present (§9.9); submit rewrites the counts
                    // with the exception rows in one transaction.
                    'present_count' => $expected,
                    'status' => RegisterStatus::Open,
                    'taken_by' => (int) auth()->id(),
                    'taken_at' => now(),
                    'lesson_duration_minutes' => $lessonMinutes,
                ]);

                $this->audit->handle(
                    action: AuditAction::Created,
                    module: 'Attendance',
                    auditableType: AttendanceRegister::class,
                    auditableId: (int) $register->getKey(),
                    after: [
                        'class_group_id' => $classGroupId,
                        'date' => $day,
                        'session' => $session->value,
                        'timetable_slot_id' => $slotKey,
                        'mode' => $mode->value,
                        'expected_count' => $expected,
                    ],
                    actor: auth()->user()?->toAuditActor(),
                );

                return $register;
            });
        } catch (UniqueConstraintViolationException) {
            // Two concurrent opens: the loser re-reads the winner's row and
            // gets the same idempotent/refusal semantics as above.
            return $this->handle($classGroupId, $day, $session, $timetableSlotId, $overrideReason);
        }
    }

    /**
     * Gate 2 — §9.9: enforced in the Action, not the UI. Assignment lives in
     * subject_allocation_teachers (user-keyed, the marks.enter precedent),
     * scoped to the class group's year, level and stream (sentinel 0 =
     * whole level).
     */
    private function assertTakerMayOpen(stdClass $group): void
    {
        if (Gate::allows(Permission::AttendanceAmend->value)) {
            return;
        }

        $streamIds = [0];

        if ($group->stream_id !== null) {
            $streamIds[] = (int) $group->stream_id;
        }

        $assigned = DB::table('subject_allocation_teachers as sat')
            ->join('subject_allocations as sa', 'sa.id', '=', 'sat.subject_allocation_id')
            ->where('sat.user_id', (int) auth()->id())
            ->where('sa.academic_year_id', (int) $group->academic_year_id)
            ->where('sa.class_level_id', (int) $group->class_level_id)
            ->whereIn('sa.stream_id', $streamIds)
            ->where('sa.is_active', true)
            ->exists();

        if (! $assigned) {
            throw new AuthorizationException(
                'You are not assigned to teach this class group; only its teachers '
                .'(or attendance leadership) may open a register for it.',
            );
        }
    }

    /** Gate 3 — the calendar-day gate (§9.2/§9.3). */
    private function assertCalendarAllows(stdClass $group, string $day, ?string $overrideReason): void
    {
        $sectionId = DB::table('class_levels')
            ->where('id', (int) $group->class_level_id)
            ->value('school_section_id');

        $calendarDay = $this->resolveCalendarDay->handle(
            (int) $group->academic_year_id,
            $day,
            $sectionId === null ? null : (int) $sectionId,
        );

        if ($calendarDay === null) {
            throw ValidationException::withMessages([
                'date' => sprintf(
                    'No school calendar entry exists for %s — the calendar must be seeded '
                    .'before registers can be taken. It never defaults to "teaching".',
                    $day,
                ),
            ]);
        }

        if ($calendarDay['allows_register']) {
            return;
        }

        $canOverride = Gate::allows(Permission::AttendanceAmend->value);
        $reason = trim((string) $overrideReason);

        if (! $canOverride || $reason === '') {
            throw ValidationException::withMessages([
                'date' => sprintf(
                    '%s is a %s day; opening a register on it requires the attendance.amend '
                    .'override and a reason.',
                    $day,
                    $calendarDay['day_type'],
                ),
            ]);
        }
    }

    /**
     * Per-lesson registers must name their timetable slot (the register's
     * subject and lesson duration are denormalised from it, §9.3/§9.7);
     * daily registers must not.
     *
     * @return array{stdClass|null, int|null, int|null} [slot row, subject id, lesson minutes]
     */
    private function resolveSlot(AttendanceMode $mode, int $classGroupId, ?int $timetableSlotId): array
    {
        if ($mode === AttendanceMode::Daily) {
            if ($timetableSlotId !== null) {
                throw ValidationException::withMessages([
                    'timetable_slot_id' => 'This class group takes daily attendance; a register '
                        .'is per day and session, not per lesson slot.',
                ]);
            }

            return [null, null, null];
        }

        if ($timetableSlotId === null) {
            throw ValidationException::withMessages([
                'timetable_slot_id' => 'This class group takes per-lesson attendance; the '
                    .'timetable slot being taught must be named.',
            ]);
        }

        $slot = DB::table('timetable_slots')
            ->where('id', $timetableSlotId)
            ->where('class_group_id', $classGroupId)
            ->first();

        if ($slot === null) {
            throw ValidationException::withMessages([
                'timetable_slot_id' => 'The named timetable slot does not exist for this class group.',
            ]);
        }

        $duration = DB::table('timetable_periods')
            ->where('id', (int) $slot->timetable_period_id)
            ->value('duration_minutes');

        return [
            $slot,
            (int) $slot->subject_id,
            $duration === null ? null : (int) $duration,
        ];
    }
}
