<?php

declare(strict_types=1);

use App\Modules\Academics\Models\ClassGroup;
use App\Modules\Academics\Models\TimetablePeriod;
use App\Modules\Academics\Models\TimetableSlot;
use App\Modules\HR\Actions\SeedTeachingHoursFromTimetable;
use App\Modules\HR\Actions\ValidateTimesheet;
use App\Modules\HR\Domain\HrPermission;
use App\Modules\HR\Domain\TimesheetStatus;
use App\Modules\HR\Domain\WorkingTime;
use App\Modules\HR\Models\TeachingHoursLog;
use App\Modules\HR\Models\Timesheet;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/P11F4TestHelpers.php';

uses(RefreshDatabase::class);

/*
 * docs/specs/05-hr-payroll.md 5.5 (C6): planned and taught hours are
 * PROPOSALS; only hours_validated on a validated row reaches payroll, and
 * the timetable seeds the plan.
 */

it('validates a submitted timesheet and only a submitted one', function () {
    $validator = p11declUser(HrPermission::TIMESHEET_VALIDATE);
    $contract = p11declContract();

    $sheet = Timesheet::query()->create([
        'staff_contract_id' => $contract->id,
        'payroll_month' => '2031-03-01',
        'hours_worked' => '82.50',
        'status' => TimesheetStatus::Submitted,
    ]);

    $validated = app(ValidateTimesheet::class)->timesheet($sheet->id, '80.00', p11declActor($validator));

    expect($validated->status)->toBe(TimesheetStatus::Validated)
        ->and($validated->hours_validated)->toBe('80.00')
        ->and($validated->validated_by)->toBe($validator->id);

    // A second validation is a state error, not a silent overwrite.
    expect(fn () => app(ValidateTimesheet::class)->timesheet($sheet->id, '81.00', p11declActor($validator)))
        ->toThrow(ValidationException::class);
});

it('requires timesheet.validate to validate hours', function () {
    $user = p11declUser(HrPermission::MANAGE);
    $contract = p11declContract();

    $log = TeachingHoursLog::query()->create([
        'staff_contract_id' => $contract->id,
        'payroll_month' => '2031-03-01',
        'hours_planned' => '20.00',
        'hours_taught' => '18.00',
        'status' => TimesheetStatus::Submitted,
    ]);

    expect(fn () => app(ValidateTimesheet::class)->teachingLog($log->id, '18.00', p11declActor($user)))
        ->toThrow(AuthorizationException::class);
});

it('seeds hourly teaching plans from the timetable, idempotently', function () {
    p11declUser(HrPermission::TIMESHEET_VALIDATE);

    $staff = p11declStaff();
    $contract = p11declContract($staff, [
        'working_time' => WorkingTime::Hourly->value,
        'starts_on' => '2030-09-01',
        'seniority_reference_date' => '2030-09-01',
    ]);

    $period = TimetablePeriod::factory()->create(['duration_minutes' => 60]);
    $slot = TimetableSlot::factory()->create([
        'staff_member_id' => $staff->id,
        'timetable_period_id' => $period->id,
        'day_of_week' => 1, // Mondays
    ]);

    // Point the slot's academic year at the payroll month under test.
    DB::table('academic_years')
        ->where('id', ClassGroup::query()->findOrFail($slot->class_group_id)->academic_year_id)
        ->update(['starts_on' => '2030-09-01', 'ends_on' => '2031-07-31']);

    $seeded = app(SeedTeachingHoursFromTimetable::class)->handle('2031-03-01');

    expect($seeded)->toBe(1);

    $log = TeachingHoursLog::query()->firstOrFail();

    // March 2031 has five Mondays (3, 10, 17, 24, 31) x 60 minutes.
    expect($log->staff_contract_id)->toBe($contract->id)
        ->and($log->hours_planned)->toBe('5.00')
        ->and($log->status)->toBe(TimesheetStatus::Draft)
        ->and($log->timetable_slot_id)->toBe($slot->id);

    // Re-seeding never duplicates (uq_thl_segment).
    expect(app(SeedTeachingHoursFromTimetable::class)->handle('2031-03-01'))->toBe(0)
        ->and(TeachingHoursLog::query()->count())->toBe(1);
});

it('seeds nothing for salaried staff - hourly pay is the vacataire path', function () {
    p11declUser(HrPermission::TIMESHEET_VALIDATE);

    $staff = p11declStaff();
    p11declContract($staff, [
        'working_time' => WorkingTime::FullTime->value,
        'starts_on' => '2030-09-01',
        'seniority_reference_date' => '2030-09-01',
    ]);

    $slot = TimetableSlot::factory()->create(['staff_member_id' => $staff->id]);

    DB::table('academic_years')
        ->where('id', ClassGroup::query()->findOrFail($slot->class_group_id)->academic_year_id)
        ->update(['starts_on' => '2030-09-01', 'ends_on' => '2031-07-31']);

    expect(app(SeedTeachingHoursFromTimetable::class)->handle('2031-03-01'))->toBe(0)
        ->and(TeachingHoursLog::query()->count())->toBe(0);
});
