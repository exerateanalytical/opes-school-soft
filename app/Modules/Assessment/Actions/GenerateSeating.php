<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Actions;

use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\ExamSeating;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/01-assessment.md 16.1 and T24, second half — the seating plan.
 *
 * ══ The invariant ════════════════════════════════════════════════════════
 *
 *   "seats assigned per room <= Room.capacity, enforced under FOR UPDATE on
 *    the room row"
 *
 * Chairs are counted across every LIVE exam that shares the room and overlaps
 * this one in time, not merely across this exam. Two class groups sitting
 * different papers in one hall at one hour is ordinary practice; what the
 * invariant protects is the hall, and the hall does not care which paper a
 * body is writing. Counting per-exam would let 40 + 40 candidates into a
 * 60-seat hall with both checks passing.
 *
 * The room row is locked FOR UPDATE before the count, in ascending id order,
 * for the same reason AssignInvigilators locks staff: two simultaneous
 * generations would otherwise both read "18 chairs free" and both fill them.
 *
 * ══ Refusals rather than truncation ══════════════════════════════════════
 *
 * If the candidates do not fit, the Action refuses and names the shortfall.
 * The alternative - seating whoever fits and stopping - produces a plan that
 * LOOKS complete and leaves a child standing in a corridor on exam morning.
 *
 * ══ Ordering ═════════════════════════════════════════════════════════════
 *
 * Candidates are seated in class-list order (surname, then first name), the
 * order the register is called in, so the attendance sheet and the seating
 * plan read down the same sequence. Anti-collusion shuffling is a policy this
 * product does not yet have a setting for, and inventing one silently would
 * make the two documents disagree.
 */
final class GenerateSeating
{
    /**
     * @param  list<int>|null  $roomIds  overflow halls in fill order; defaults
     *                                   to the exam's own room
     * @return list<ExamSeating>
     */
    public function handle(int $examId, ?array $roomIds = null): array
    {
        Gate::authorize(Permission::AcademicsManage->value);

        return DB::transaction(function () use ($examId, $roomIds): array {
            /** @var Exam|null $exam */
            $exam = Exam::query()->lockForUpdate()->find($examId);

            if ($exam === null) {
                throw ValidationException::withMessages([
                    'exam_id' => 'The selected exam does not exist.',
                ]);
            }

            if ($exam->status === Exam::STATUS_CANCELLED) {
                throw ValidationException::withMessages([
                    'exam_id' => 'A cancelled sitting is not seated.',
                ]);
            }

            $rooms = $this->resolveRoomIds($exam, $roomIds);

            if (DB::table('exam_seatings')->where('exam_id', $examId)->exists()) {
                // Regenerating would need a policy for candidates who have
                // already been told their seat number. Refusing names the
                // situation; a silent re-shuffle would not.
                throw ValidationException::withMessages([
                    'exam_id' => 'This sitting already has a seating plan. Clear it before '
                        .'generating a new one.',
                ]);
            }

            $candidates = $this->candidates($exam);

            if ($candidates === []) {
                throw ValidationException::withMessages([
                    'exam_id' => 'No candidate is enrolled in this class group, so there is '
                        .'nothing to seat.',
                ]);
            }

            // ── The lock, ascending, before any count. ────────────────────
            $roomRows = DB::table('rooms')
                ->whereIn('id', $rooms)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $free = [];
            $total = 0;

            foreach ($rooms as $roomId) {
                $room = $roomRows->get($roomId);

                if ($room === null) {
                    throw ValidationException::withMessages([
                        'room_id' => 'Room #'.$roomId.' does not exist.',
                    ]);
                }

                /** @var array<string, mixed> $roomRow */
                $roomRow = (array) $room;

                $capacity = (int) $roomRow['capacity'];
                $occupied = $this->occupiedSeats($exam, $roomId);
                $available = max(0, $capacity - $occupied);

                $free[$roomId] = [
                    'code' => (string) $roomRow['code'],
                    'capacity' => $capacity,
                    'occupied' => $occupied,
                    'available' => $available,
                ];

                $total += $available;
            }

            if ($total < count($candidates)) {
                throw ValidationException::withMessages([
                    'room_id' => 'The chosen room(s) seat '.$total.' more candidate(s) at this '
                        .'hour, but '.count($candidates).' are entered for this paper. '
                        .$this->capacityBreakdown($free),
                ]);
            }

            $actor = $this->currentActor();
            $seatings = [];
            $queue = $candidates;

            foreach ($free as $roomId => $room) {
                $index = 0;

                while ($room['available'] > $index && $queue !== []) {
                    /** @var array{enrollment_id: int} $candidate */
                    $candidate = array_shift($queue);
                    $index++;

                    $seatings[] = ExamSeating::query()->create([
                        'exam_id' => $examId,
                        'enrollment_id' => $candidate['enrollment_id'],
                        'room_id' => $roomId,
                        'seat_label' => $room['code'].'-'.str_pad((string) ($room['occupied'] + $index), 3, '0', STR_PAD_LEFT),
                    ]);
                }
            }

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Created,
                module: 'Assessment',
                auditableType: Exam::class,
                auditableId: $examId,
                before: null,
                after: [
                    'seating_generated' => count($seatings),
                    'rooms' => array_keys($free),
                    'scheduled_on' => $exam->scheduled_on->toDateString(),
                ],
                actor: $actor,
            );

            return $seatings;
        });
    }

    /**
     * @param  list<int>|null  $roomIds
     * @return list<int>
     */
    private function resolveRoomIds(Exam $exam, ?array $roomIds): array
    {
        if ($roomIds !== null && $roomIds !== []) {
            return array_values(array_unique($roomIds));
        }

        if ($exam->room_id === null) {
            throw ValidationException::withMessages([
                'room_id' => 'This sitting has no room, so it cannot be seated. Set the room on '
                    .'the exam or name the halls explicitly.',
            ]);
        }

        return [$exam->room_id];
    }

    /**
     * Chairs already taken in this room at this hour — see the class header
     * for why the count spans overlapping exams rather than this one.
     */
    private function occupiedSeats(Exam $exam, int $roomId): int
    {
        $end = $exam->endsAt();

        return DB::table('exam_seatings as s')
            ->join('exams as e', 'e.id', '=', 's.exam_id')
            ->where('s.room_id', $roomId)
            ->where('e.id', '!=', $exam->getKey())
            ->where('e.scheduled_on', '=', $exam->scheduled_on->toDateString())
            ->whereIn('e.status', Exam::LIVE_STATUSES)
            ->whereRaw('e.starts_at < ?', [$end])
            ->whereRaw('ADDTIME(e.starts_at, SEC_TO_TIME(e.duration_minutes * 60)) > ?', [$exam->starts_at])
            ->count();
    }

    /**
     * The class list, through the query builder: `Enrollment` is a Students
     * model and the module boundary test forbids naming it from here.
     *
     * The OPEN segment resolves "who is in this class today" (07-students
     * 5.2); a student who transferred out in January is not a candidate.
     *
     * @return list<array{enrollment_id: int}>
     */
    private function candidates(Exam $exam): array
    {
        $rows = DB::table('enrollment_segments as seg')
            ->join('enrollments as enr', 'enr.id', '=', 'seg.enrollment_id')
            ->join('students as st', 'st.id', '=', 'enr.student_id')
            ->where('seg.class_group_id', $exam->class_group_id)
            ->whereNull('seg.ends_on')
            ->whereIn('enr.status', ['pending', 'active', 'suspended'])
            ->orderBy('st.last_name')
            ->orderBy('st.first_name')
            ->orderBy('enr.id')
            ->select(['enr.id as enrollment_id'])
            ->get();

        $candidates = [];

        foreach ($rows as $row) {
            /** @var object{enrollment_id: int|string} $row */
            $candidates[] = ['enrollment_id' => (int) $row->enrollment_id];
        }

        return $candidates;
    }

    /**
     * @param  array<int, array{code: string, capacity: int, occupied: int, available: int}>  $free
     */
    private function capacityBreakdown(array $free): string
    {
        $parts = [];

        foreach ($free as $room) {
            $parts[] = $room['code'].': '.$room['available'].' free of '.$room['capacity']
                .' ('.$room['occupied'].' taken by an overlapping sitting)';
        }

        return implode('; ', $parts).'.';
    }

    private function currentActor(): Actor
    {
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
