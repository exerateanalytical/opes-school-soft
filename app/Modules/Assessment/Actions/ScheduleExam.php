<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Actions;

use App\Modules\Assessment\Models\Exam;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Schedule a sitting — docs/specs/01-assessment.md 16.1.
 *
 * ── What this Action is for ───────────────────────────────────────────────
 *
 * 16.1's whole point is that a date, a start time, a duration, a room and a
 * mark scheme have nowhere to live on an `AssessmentPeriod`, which is a
 * calendar window shared by every subject in the school. This Action is where
 * a sitting acquires them, and where the two facts that relate the sitting to
 * its window are checked:
 *
 *   1. the period must be a LEAF (6.1 invariant 3 — no Mark may hang off a
 *      non-leaf period, and an exam produces marks). Scheduling "Form 1A sits
 *      Maths in First Term" is not a sitting, it is a wish.
 *   2. the sitting date must fall INSIDE the window. A Séquence 3 paper sat in
 *      April is either a typo or a re-sit that belongs to a different period,
 *      and both are better refused than silently recorded.
 *
 * ── Not checked here, deliberately ────────────────────────────────────────
 *
 * Two exams in one room at the same hour are NOT rejected. Two class groups
 * sharing a hall is ordinary practice in a Cameroonian secondary school; what
 * must not happen is more candidates than chairs, and that is a seat count,
 * enforced in GenerateSeating across every overlapping exam in the room. A
 * room-clash rule here would forbid the normal case in order to catch a case
 * the capacity invariant already catches properly.
 */
final class ScheduleExam
{
    public function handle(
        int $examTypeId,
        int $assessmentPeriodId,
        int $subjectAllocationId,
        int $classGroupId,
        string $scheduledOn,
        string $startsAt,
        int $durationMinutes,
        string $maxScore,
        ?int $roomId = null,
        ?int $markSchemeId = null,
        string $status = Exam::STATUS_PLANNED,
    ): Exam {
        // The exams workstream has no permission of its own yet; scheduling
        // shapes the academic calendar, which is what academics.manage
        // governs. When an `exams.manage` permission lands this is the one
        // line that changes.
        Gate::authorize(Permission::AcademicsManage->value);

        if (! in_array($status, [...Exam::LIVE_STATUSES, Exam::STATUS_CANCELLED], true)) {
            throw ValidationException::withMessages([
                'status' => 'Unknown exam status "'.$status.'".',
            ]);
        }

        if ($durationMinutes <= 0) {
            // Also a CHECK constraint. Rejected here so the operator gets a
            // sentence instead of a driver exception - and because a
            // zero-length sitting would make the half-open overlap interval
            // empty, quietly disabling invariant 17 for that row.
            throw ValidationException::withMessages([
                'duration_minutes' => 'A sitting must last at least one minute.',
            ]);
        }

        if ((float) $maxScore <= 0.0) {
            throw ValidationException::withMessages([
                'max_score' => 'A paper must be marked out of a positive maximum.',
            ]);
        }

        $start = $this->normaliseTime($startsAt);
        $date = $this->normaliseDate($scheduledOn);

        return DB::transaction(function () use (
            $examTypeId, $assessmentPeriodId, $subjectAllocationId, $classGroupId,
            $date, $start, $durationMinutes, $maxScore, $roomId, $markSchemeId, $status
        ): Exam {
            $this->assertLeafPeriodContaining($assessmentPeriodId, $date);
            $this->assertActiveAllocation($subjectAllocationId);
            $this->assertExists('class_groups', $classGroupId, 'class_group_id', 'class group');

            if ($roomId !== null) {
                $this->assertExists('rooms', $roomId, 'room_id', 'room');
            }

            // The UNIQUE index is the real guard; this read turns the driver's
            // integrity error into 16.1's sentence. Both are needed - the read
            // alone is a race, the index alone is unreadable.
            $duplicate = DB::table('exams')
                ->where('assessment_period_id', $assessmentPeriodId)
                ->where('subject_allocation_id', $subjectAllocationId)
                ->where('class_group_id', $classGroupId)
                ->where('exam_type_id', $examTypeId)
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'subject_allocation_id' => 'This class group already has an exam of this type '
                        .'for this subject in this period.',
                ]);
            }

            $actor = $this->currentActor();

            $exam = Exam::query()->create([
                'exam_type_id' => $examTypeId,
                'assessment_period_id' => $assessmentPeriodId,
                'subject_allocation_id' => $subjectAllocationId,
                'class_group_id' => $classGroupId,
                'scheduled_on' => $date->toDateString(),
                'starts_at' => $start,
                'duration_minutes' => $durationMinutes,
                'room_id' => $roomId,
                'mark_scheme_id' => $markSchemeId,
                'max_score' => $maxScore,
                'status' => $status,
                'created_by' => $actor->id ?? $this->systemUserId(),
                'version' => 1,
            ]);

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Created,
                module: 'Assessment',
                auditableType: Exam::class,
                auditableId: (int) $exam->getKey(),
                before: null,
                after: [
                    'assessment_period_id' => $assessmentPeriodId,
                    'subject_allocation_id' => $subjectAllocationId,
                    'class_group_id' => $classGroupId,
                    'exam_type_id' => $examTypeId,
                    'scheduled_on' => $date->toDateString(),
                    'starts_at' => $start,
                    'duration_minutes' => $durationMinutes,
                    'room_id' => $roomId,
                    'max_score' => $maxScore,
                    'status' => $status,
                ],
                actor: $actor,
            );

            return $exam;
        });
    }

    /**
     * 6.1 invariant 3 plus the 16.1 containment rule, in one read.
     */
    private function assertLeafPeriodContaining(int $periodId, Carbon $date): void
    {
        $period = DB::table('assessment_periods')->where('id', $periodId)->first();

        if ($period === null) {
            throw ValidationException::withMessages([
                'assessment_period_id' => 'The selected assessment period does not exist.',
            ]);
        }

        /** @var array<string, mixed> $row */
        $row = (array) $period;

        $hasChildren = DB::table('assessment_periods')->where('parent_id', $periodId)->exists();

        if ($hasChildren) {
            throw ValidationException::withMessages([
                'assessment_period_id' => 'An exam is sat inside a leaf period (a sequence or an '
                    .'evaluation), never inside a term or a year: a mark may not hang off a '
                    .'non-leaf period.',
            ]);
        }

        $opens = Carbon::parse((string) $row['starts_on'])->startOfDay();
        $closes = Carbon::parse((string) $row['ends_on'])->startOfDay();

        if ($date->lt($opens) || $date->gt($closes)) {
            throw ValidationException::withMessages([
                'scheduled_on' => 'The sitting falls outside its assessment period, which runs '
                    .$opens->toDateString().' to '.$closes->toDateString().'.',
            ]);
        }
    }

    private function assertActiveAllocation(int $allocationId): void
    {
        $row = DB::table('subject_allocations')->where('id', $allocationId)->first();

        if ($row === null) {
            throw ValidationException::withMessages([
                'subject_allocation_id' => 'The selected subject allocation does not exist.',
            ]);
        }

        /** @var array<string, mixed> $allocation */
        $allocation = (array) $row;

        if ((bool) $allocation['is_active'] === false) {
            // 5.1: a deactivated allocation is closed out, not deleted. New
            // sittings against it would produce marks for a subject that is no
            // longer on the class list.
            throw ValidationException::withMessages([
                'subject_allocation_id' => 'This subject allocation is no longer active; a new '
                    .'sitting cannot be scheduled against it.',
            ]);
        }
    }

    private function assertExists(string $table, int $id, string $field, string $label): void
    {
        if (! DB::table($table)->where('id', $id)->exists()) {
            throw ValidationException::withMessages([
                $field => 'The selected '.$label.' does not exist.',
            ]);
        }
    }

    private function normaliseTime(string $value): string
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:([0-5]\d))?$/', $value) !== 1) {
            throw ValidationException::withMessages([
                'starts_at' => 'The start time must read HH:MM on a 24-hour clock.',
            ]);
        }

        return mb_strlen($value) === 5 ? $value.':00' : $value;
    }

    private function normaliseDate(string $value): Carbon
    {
        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'scheduled_on' => 'The sitting date is not a date.',
            ]);
        }
    }

    /**
     * `exams.created_by` is NOT NULL and RESTRICTs onto users, so an
     * unattended scheduling run (an import, a seeder) still needs a real row.
     * Refusing is better than inventing one.
     */
    private function systemUserId(): int
    {
        throw ValidationException::withMessages([
            'created_by' => 'An exam must be scheduled by a signed-in user; the timetable is an '
                .'accountable document.',
        ]);
    }

    private function currentActor(): Actor
    {
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
