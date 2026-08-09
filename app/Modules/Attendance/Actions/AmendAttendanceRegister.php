<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Actions;

use App\Modules\Attendance\Actions\Concerns\ResolvesRoster;
use App\Modules\Attendance\Domain\AttendanceStatus;
use App\Modules\Attendance\Domain\RegisterStatus;
use App\Modules\Attendance\Jobs\RebuildAttendanceSummaryJob;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Models\AttendanceRegister;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Amendment after submit (07-students §9.9): requires a reason, sets
 * `status='amended'`, writes an AuditLog whose before/after payload keeps
 * the original counts recoverable, and re-queues the summary rebuild.
 *
 * `expected_count` stays FROZEN (§9.5) unless the amendment reason is a
 * roster correction — the one case where the denominator itself was wrong
 * at open.
 *
 * Justifications on replaced rows do not survive: an amendment restates
 * what happened in the room, and a justification accepted for a mark that
 * no longer exists would be attached to a fiction. They are re-entered
 * through JustifyAbsence against the new rows.
 */
final class AmendAttendanceRegister
{
    use ResolvesRoster;

    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  array<int, array{enrollment_id: int, status: string, minutes_late?: int|null, remark?: string|null}>  $marks
     *         The full restated register — exceptions only or roster-shaped,
     *         as for submit.
     */
    public function handle(
        int $registerId,
        array $marks,
        string $reason,
        bool $rosterCorrection = false,
    ): AttendanceRegister {
        Gate::authorize(Permission::AttendanceAmend->value);

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'An amendment must say why the submitted register was wrong.',
            ]);
        }

        $register = DB::transaction(function () use (
            $registerId, $marks, $reason, $rosterCorrection
        ): AttendanceRegister {
            /** @var AttendanceRegister $register */
            $register = AttendanceRegister::query()
                ->lockForUpdate()
                ->findOrFail($registerId);

            if ($register->status === RegisterStatus::Open) {
                throw ValidationException::withMessages([
                    'register' => 'An open register is not amended — it has not been '
                        .'submitted yet; submit it instead.',
                ]);
            }

            $before = [
                'status' => $register->status->value,
                'expected_count' => $register->expected_count,
                'present_count' => $register->present_count,
                'absent_count' => $register->absent_count,
                'late_count' => $register->late_count,
                'excused_count' => $register->excused_count,
                'records' => $register->records()
                    ->get()
                    ->map(fn (AttendanceRecord $record): array => [
                        'enrollment_id' => $record->enrollment_id,
                        'status' => $record->status->value,
                        'is_justified' => $record->is_justified,
                    ])
                    ->all(),
            ];

            $expected = $register->expected_count;

            if ($rosterCorrection) {
                // §9.5: the ONLY path that recomputes a frozen denominator.
                $expected = count($this->rosterEnrollmentIds(
                    $register->class_group_id,
                    $register->date->toDateString(),
                ));
            }

            $rows = $this->restatedRows($register, $marks);
            $counts = $this->countsFor($expected, $rows);

            // Replace wholesale inside the transaction: the DELETE is safe
            // because the register row itself survives (the observer guards
            // register deletion, not restatement of its exception rows).
            AttendanceRecord::query()
                ->where('attendance_register_id', $register->getKey())
                ->delete();

            if ($rows !== []) {
                DB::table('attendance_records')->insert($rows);
            }

            $register->fill([
                'status' => RegisterStatus::Amended,
                'expected_count' => $expected,
                'amended_by' => (int) auth()->id(),
                'amended_at' => now(),
                'amendment_reason' => $reason,
                ...$counts,
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Attendance',
                auditableType: AttendanceRegister::class,
                auditableId: (int) $register->getKey(),
                before: $before,
                after: [
                    'status' => RegisterStatus::Amended->value,
                    'reason' => $reason,
                    'roster_correction' => $rosterCorrection,
                    'expected_count' => $expected,
                    ...$counts,
                ],
                actor: auth()->user()?->toAuditActor(),
            );

            return $register;
        });

        RebuildAttendanceSummaryJob::dispatch((int) $register->getKey());

        return $register;
    }

    /**
     * @param  array<int, array{enrollment_id: int, status: string, minutes_late?: int|null, remark?: string|null}>  $marks
     * @return list<array<string, mixed>>
     */
    private function restatedRows(AttendanceRegister $register, array $marks): array
    {
        $roster = array_flip($this->rosterEnrollmentIds(
            $register->class_group_id,
            $register->date->toDateString(),
        ));

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

            if (! isset($roster[$enrollmentId])) {
                throw ValidationException::withMessages([
                    'marks' => sprintf(
                        'Enrollment %d was not on this register\'s roster for %s.',
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
                continue;
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
     * @param  list<array<string, mixed>>  $rows
     * @return array{present_count: int, absent_count: int, late_count: int, excused_count: int}
     */
    private function countsFor(int $expected, array $rows): array
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
