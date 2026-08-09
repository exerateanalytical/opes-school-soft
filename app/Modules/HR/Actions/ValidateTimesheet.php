<?php

declare(strict_types=1);

namespace App\Modules\HR\Actions;

use App\Modules\HR\Domain\HrPermission;
use App\Modules\HR\Domain\TimesheetStatus;
use App\Modules\HR\Models\TeachingHoursLog;
use App\Modules\HR\Models\Timesheet;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/05-hr-payroll.md 5.5: `hours_planned` and `hours_taught` are
 * PROPOSALS; only `hours_validated` on a `validated` row ever reaches
 * payroll. Validation is a conditional UPDATE from `submitted` with an
 * affected-rows check (00-core 10.4) - never read-then-write.
 */
final class ValidateTimesheet
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /** Validates a non-teaching hourly timesheet row. */
    public function timesheet(int $timesheetId, string $hoursValidated, Actor $actor): Timesheet
    {
        Gate::authorize(HrPermission::TIMESHEET_VALIDATE);

        $this->assertNonNegative($hoursValidated);

        return DB::transaction(function () use ($timesheetId, $hoursValidated, $actor): Timesheet {
            /** @var Timesheet $sheet */
            $sheet = Timesheet::query()->whereKey($timesheetId)->lockForUpdate()->firstOrFail();

            $updated = Timesheet::query()
                ->whereKey($sheet->id)
                ->where('status', TimesheetStatus::Submitted->value)
                ->update([
                    'status' => TimesheetStatus::Validated->value,
                    'hours_validated' => $hoursValidated,
                    'validated_by' => $actor->id,
                    'validated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw ValidationException::withMessages([
                    'status' => "Only a submitted timesheet can be validated; this one is '{$sheet->status->value}'.",
                ]);
            }

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'HR',
                auditableType: Timesheet::class,
                auditableId: (int) $sheet->getKey(),
                before: ['status' => $sheet->status->value, 'hours_validated' => $sheet->hours_validated],
                after: ['status' => TimesheetStatus::Validated->value, 'hours_validated' => $hoursValidated],
                actor: $actor,
            );

            return $sheet->refresh();
        });
    }

    /** Validates one teaching-hours segment row. */
    public function teachingLog(int $teachingHoursLogId, string $hoursValidated, Actor $actor): TeachingHoursLog
    {
        Gate::authorize(HrPermission::TIMESHEET_VALIDATE);

        $this->assertNonNegative($hoursValidated);

        return DB::transaction(function () use ($teachingHoursLogId, $hoursValidated, $actor): TeachingHoursLog {
            /** @var TeachingHoursLog $log */
            $log = TeachingHoursLog::query()->whereKey($teachingHoursLogId)->lockForUpdate()->firstOrFail();

            $updated = TeachingHoursLog::query()
                ->whereKey($log->id)
                ->where('status', TimesheetStatus::Submitted->value)
                ->update([
                    'status' => TimesheetStatus::Validated->value,
                    'hours_validated' => $hoursValidated,
                    'validated_by' => $actor->id,
                    'validated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw ValidationException::withMessages([
                    'status' => "Only a submitted teaching-hours log can be validated; this one is '{$log->status->value}'.",
                ]);
            }

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'HR',
                auditableType: TeachingHoursLog::class,
                auditableId: (int) $log->getKey(),
                before: ['status' => $log->status->value, 'hours_validated' => $log->hours_validated],
                after: ['status' => TimesheetStatus::Validated->value, 'hours_validated' => $hoursValidated],
                actor: $actor,
            );

            return $log->refresh();
        });
    }

    private function assertNonNegative(string $hours): void
    {
        if ((float) $hours < 0) {
            throw ValidationException::withMessages([
                'hours_validated' => 'Validated hours cannot be negative.',
            ]);
        }
    }
}
