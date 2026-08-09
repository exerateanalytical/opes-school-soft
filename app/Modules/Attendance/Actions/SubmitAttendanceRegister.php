<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Actions;

use App\Modules\Attendance\Actions\Concerns\ResolvesRoster;
use App\Modules\Attendance\Domain\AttendanceStatus;
use App\Modules\Attendance\Domain\RegisterStatus;
use App\Modules\Attendance\Jobs\RebuildAttendanceSummaryJob;
use App\Modules\Attendance\Models\AttendanceRegister;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The one-batched-save submit (07-students §9.9): the whole roster's marks
 * arrive in ONE request; only the exceptions are written (§9.4 — `present`
 * rows never exist); the header's counts are rewritten in the SAME
 * transaction; and the `open → submitted` flip is a conditional
 * `UPDATE … WHERE status='open'` with an affected-rows check (00-core §10.4)
 * so a double-tapped submit cannot write twice.
 *
 * Header count semantics: `present_count = expected_count − Σ(exception
 * rows)` (§9.3) — it counts the silently-present; the late are re-added as
 * present by the §9.6 rate formula, which reads the records, not this count.
 */
final class SubmitAttendanceRegister
{
    use ResolvesRoster;

    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  array<int, array{enrollment_id: int, status: string, minutes_late?: int|null, remark?: string|null}>  $marks
     *         Exceptions only, or the full roster — `present` entries are
     *         filtered out here, so the caller may send either shape.
     */
    public function handle(int $registerId, array $marks): AttendanceRegister
    {
        Gate::authorize(Permission::AttendanceTake->value);

        $register = DB::transaction(function () use ($registerId, $marks): AttendanceRegister {
            /** @var AttendanceRegister $register */
            $register = AttendanceRegister::query()
                ->lockForUpdate()
                ->findOrFail($registerId);

            if ($register->taken_by !== (int) auth()->id()
                && ! Gate::allows(Permission::AttendanceAmend->value)) {
                throw new AuthorizationException(
                    'Only the teacher who opened this register (or attendance leadership) may submit it.',
                );
            }

            $rows = $this->exceptionRows($register, $marks);

            $counts = $this->headerCounts($register->expected_count, $rows);

            // 00-core §10.4: the conditional UPDATE is the idempotency key.
            $affected = AttendanceRegister::query()
                ->whereKey($register->getKey())
                ->where('status', RegisterStatus::Open->value)
                ->update([
                    'status' => RegisterStatus::Submitted->value,
                    'submitted_at' => now(),
                    ...$counts,
                ]);

            if ($affected === 0) {
                throw ValidationException::withMessages([
                    'register' => 'This register has already been submitted; a double-tapped '
                        .'submit writes nothing. Corrections go through amendment.',
                ]);
            }

            if ($rows !== []) {
                // ONE batched insert — ≤1 statement for 45 students (§9.9).
                DB::table('attendance_records')->insert($rows);
            }

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Attendance',
                auditableType: AttendanceRegister::class,
                auditableId: (int) $register->getKey(),
                before: ['status' => RegisterStatus::Open->value],
                after: [
                    'status' => RegisterStatus::Submitted->value,
                    'exception_rows' => count($rows),
                    ...$counts,
                ],
                actor: auth()->user()?->toAuditActor(),
            );

            return $register->refresh();
        });

        // §9.8: the per-period summary is rebuilt by a queued job on submit,
        // after the register's own transaction has committed.
        RebuildAttendanceSummaryJob::dispatch((int) $register->getKey());

        return $register;
    }

    /**
     * Validates the marks against the enum and the §9.5 roster, drops the
     * `present` entries (§9.4 storage rule), and shapes the batch insert.
     *
     * @param  array<int, array{enrollment_id: int, status: string, minutes_late?: int|null, remark?: string|null}>  $marks
     * @return list<array<string, mixed>>
     */
    private function exceptionRows(AttendanceRegister $register, array $marks): array
    {
        $roster = $this->rosterEnrollmentIds(
            $register->class_group_id,
            $register->date->toDateString(),
        );
        $rosterSet = array_flip($roster);

        $rows = [];
        $seen = [];

        foreach ($marks as $mark) {
            $enrollmentId = (int) $mark['enrollment_id'];
            $status = AttendanceStatus::tryFrom($mark['status']);

            if ($status === null) {
                throw ValidationException::withMessages([
                    'marks' => sprintf('"%s" is not an attendance status.', $mark['status']),
                ]);
            }

            if (! isset($rosterSet[$enrollmentId])) {
                throw ValidationException::withMessages([
                    'marks' => sprintf(
                        'Enrollment %d is not on this register\'s roster for %s.',
                        $enrollmentId,
                        $register->date->toDateString(),
                    ),
                ]);
            }

            if (isset($seen[$enrollmentId])) {
                throw ValidationException::withMessages([
                    'marks' => sprintf('Enrollment %d is marked twice.', $enrollmentId),
                ]);
            }
            $seen[$enrollmentId] = true;

            if (! $status->isExceptionRow()) {
                continue; // present ⇒ no row, ever (§9.4).
            }

            $rows[] = [
                'attendance_register_id' => (int) $register->getKey(),
                'enrollment_id' => $enrollmentId,
                'status' => $status->value,
                'is_justified' => false,
                'minutes_late' => $status === AttendanceStatus::Late
                    ? ($mark['minutes_late'] ?? null)
                    : null,
                'remark' => $mark['remark'] ?? null,
                'recorded_by' => (int) auth()->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return $rows;
    }

    /**
     * §9.3's maintained counts, derived from the exception rows.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{present_count: int, absent_count: int, late_count: int, excused_count: int}
     */
    private function headerCounts(int $expected, array $rows): array
    {
        $absent = 0;
        $late = 0;
        $excused = 0;

        foreach ($rows as $row) {
            $status = AttendanceStatus::from((string) $row['status']);

            if ($status->isCountableAbsence()) {
                $absent++;
            } elseif ($status === AttendanceStatus::Late) {
                $late++;
            } elseif ($status === AttendanceStatus::Excused) {
                $excused++;
            }
        }

        return [
            'present_count' => max(0, $expected - count($rows)),
            'absent_count' => $absent,
            'late_count' => $late,
            'excused_count' => $excused,
        ];
    }
}
