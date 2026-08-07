<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Actions;

use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\ExamInvigilator;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/01-assessment.md 16.1 and invariant 17 — T24, first half.
 *
 * ══ The invariant, and why it is a locked read rather than a constraint ═══
 *
 *   "a staff member cannot invigilate two exams whose
 *    [starts_at, starts_at + duration) intervals overlap on the same date.
 *    Checked under lock on assignment."
 *
 * MySQL has no exclusion constraint, so a declarative version does not exist.
 * A plain read-then-insert is not a substitute: two clerks assigning the same
 * teacher to two overlapping halls at the same instant both read "no
 * conflict", both insert, and the school discovers on the morning that one
 * hall has no invigilator. So the candidate staff rows are locked FOR UPDATE
 * first, and the second transaction blocks on that lock until the first has
 * committed its row and is therefore visible to the probe.
 *
 * Staff ids are locked in ASCENDING order. Two transactions assigning
 * {7, 12} and {12, 7} in opposite orders would otherwise deadlock; ordering
 * makes the lock acquisition sequence identical for everyone.
 *
 * ══ The interval is HALF-OPEN, and that is the interesting boundary ═══════
 *
 * Overlap is `startA < endB AND startB < endA`. Under that test a pair that
 * merely TOUCHES — 08:00-10:00 and 10:00-12:00 — does not overlap, because
 * `10:00 < 10:00` is false. This is not a technicality: back-to-back papers
 * with the same invigilator are how an exam morning is actually timetabled,
 * and a closed interval would reject the entire normal schedule while
 * catching nothing extra. The touching case has its own test.
 *
 * The end time is computed IN SQL (`ADDTIME(starts_at, SEC_TO_TIME(...))`)
 * rather than in PHP, so the comparison happens inside the same statement -
 * and therefore the same locked read - that decides it.
 */
final class AssignInvigilators
{
    /**
     * @param  list<array{staff_id: int, role?: string}>  $assignments
     * @return list<ExamInvigilator>
     */
    public function handle(int $examId, array $assignments): array
    {
        Gate::authorize(Permission::AcademicsManage->value);

        if ($assignments === []) {
            throw ValidationException::withMessages([
                'assignments' => 'Name at least one invigilator.',
            ]);
        }

        $normalised = $this->normalise($assignments);

        return DB::transaction(function () use ($examId, $normalised): array {
            /** @var Exam|null $exam */
            $exam = Exam::query()->lockForUpdate()->find($examId);

            if ($exam === null) {
                throw ValidationException::withMessages([
                    'exam_id' => 'The selected exam does not exist.',
                ]);
            }

            if ($exam->status === Exam::STATUS_CANCELLED) {
                throw ValidationException::withMessages([
                    'exam_id' => 'A cancelled sitting needs no invigilators.',
                ]);
            }

            $staffIds = array_keys($normalised);
            sort($staffIds);

            // ── The lock. Everything below reads under it. ────────────────
            $locked = DB::table('staff_members')
                ->whereIn('id', $staffIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            $missing = array_diff($staffIds, $locked);

            if ($missing !== []) {
                throw ValidationException::withMessages([
                    'assignments' => 'Unknown staff member(s): '.implode(', ', $missing).'.',
                ]);
            }

            $actor = $this->currentActor();
            $created = [];

            foreach ($staffIds as $staffId) {
                $role = $normalised[$staffId];

                $this->assertNotAlreadyOnThisPaper($examId, $staffId);
                $this->assertNoTemporalConflict($exam, $staffId);

                $row = ExamInvigilator::query()->create([
                    'exam_id' => $examId,
                    'staff_id' => $staffId,
                    'role' => $role,
                    'assigned_by' => $actor->id ?? $this->refuseUnattended(),
                ]);

                app(WriteAuditEntry::class)->handle(
                    action: AuditAction::Created,
                    module: 'Assessment',
                    auditableType: ExamInvigilator::class,
                    auditableId: (int) $row->getKey(),
                    before: null,
                    after: [
                        'exam_id' => $examId,
                        'staff_id' => $staffId,
                        'role' => $role,
                        'scheduled_on' => $exam->scheduled_on->toDateString(),
                        'window' => $exam->starts_at.'-'.$exam->endsAt(),
                    ],
                    actor: $actor,
                );

                $created[] = $row;
            }

            return $created;
        });
    }

    /**
     * @param  list<array{staff_id: int, role?: string}>  $assignments
     * @return array<int, string> staff id => role
     */
    private function normalise(array $assignments): array
    {
        $normalised = [];

        foreach ($assignments as $assignment) {
            $staffId = $assignment['staff_id'];
            $role = $assignment['role'] ?? ExamInvigilator::ROLE_ASSISTANT;

            if (! in_array($role, ExamInvigilator::ROLES, true)) {
                throw ValidationException::withMessages([
                    'assignments' => 'Unknown invigilator role "'.$role.'".',
                ]);
            }

            if (array_key_exists($staffId, $normalised)) {
                throw ValidationException::withMessages([
                    'assignments' => 'The same staff member is named twice in one assignment.',
                ]);
            }

            $normalised[$staffId] = $role;
        }

        return $normalised;
    }

    private function assertNotAlreadyOnThisPaper(int $examId, int $staffId): void
    {
        $already = DB::table('exam_invigilators')
            ->where('exam_id', $examId)
            ->where('staff_id', $staffId)
            ->exists();

        if ($already) {
            throw ValidationException::withMessages([
                'assignments' => $this->staffLabel($staffId).' is already invigilating this paper.',
            ]);
        }
    }

    /**
     * Invariant 17. See the class header for the half-open interval and why
     * the arithmetic is in SQL.
     */
    private function assertNoTemporalConflict(Exam $exam, int $staffId): void
    {
        $end = $exam->endsAt();

        $clash = DB::table('exam_invigilators as ei')
            ->join('exams as e', 'e.id', '=', 'ei.exam_id')
            ->where('ei.staff_id', $staffId)
            ->where('e.id', '!=', $exam->getKey())
            ->where('e.scheduled_on', '=', $exam->scheduled_on->toDateString())
            ->whereIn('e.status', Exam::LIVE_STATUSES)
            // startA < endB
            ->whereRaw('e.starts_at < ?', [$end])
            // startB < endA
            ->whereRaw('ADDTIME(e.starts_at, SEC_TO_TIME(e.duration_minutes * 60)) > ?', [$exam->starts_at])
            ->select([
                'e.id as exam_id',
                'e.starts_at as starts_at',
                'e.duration_minutes as duration_minutes',
                'e.room_id as room_id',
            ])
            ->first();

        if ($clash === null) {
            return;
        }

        /** @var array<string, mixed> $conflict */
        $conflict = (array) $clash;

        throw ValidationException::withMessages([
            'assignments' => $this->staffLabel($staffId).' is already invigilating exam #'
                .(int) $conflict['exam_id'].' on '.$exam->scheduled_on->toDateString()
                .' from '.(string) $conflict['starts_at'].' for '
                .(int) $conflict['duration_minutes'].' minutes, which overlaps '
                .$exam->starts_at.'-'.$end.'. One person cannot be in two rooms at once.',
        ]);
    }

    /**
     * The staff member's own name, read through the query builder: reaching
     * for HR\Models\StaffMember from the Assessment module is what
     * tests/Architecture/ModuleBoundaryTest.php forbids. An error message that
     * says "M. Tabi" instead of "staff #17" is worth the join.
     */
    private function staffLabel(int $staffId): string
    {
        $row = DB::table('staff_members')
            ->where('id', $staffId)
            ->select(['first_name', 'last_name'])
            ->first();

        if ($row === null) {
            return 'Staff #'.$staffId;
        }

        /** @var array<string, mixed> $staff */
        $staff = (array) $row;

        return trim((string) $staff['first_name'].' '.(string) $staff['last_name']);
    }

    private function refuseUnattended(): never
    {
        throw ValidationException::withMessages([
            'assigned_by' => 'An invigilation duty must be assigned by a signed-in user; it is '
                .'the record of who is answerable for the hall.',
        ]);
    }

    private function currentActor(): Actor
    {
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
