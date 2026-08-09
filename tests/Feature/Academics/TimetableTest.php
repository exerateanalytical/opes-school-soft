<?php

declare(strict_types=1);

use App\Modules\Academics\Actions\AssignTimetableSlot;
use App\Modules\Academics\Actions\DefineTimetablePeriods;
use App\Modules\Academics\Actions\RemoveTimetableSlot;
use App\Modules\Academics\Actions\SetClassGroupAttendanceMode;
use App\Modules\Academics\Domain\AttendanceMode;
use App\Modules\Academics\Livewire\Timetable\Index as TimetableIndex;
use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Academics\Models\ClassGroup;
use App\Modules\Academics\Models\ClassLevel;
use App\Modules\Academics\Models\Room;
use App\Modules\Academics\Models\SchoolSection;
use App\Modules\Academics\Models\Subject;
use App\Modules\Academics\Models\TimetablePeriod;
use App\Modules\Academics\Models\TimetableSlot;
use App\Modules\Assessment\Models\AssessmentFramework;
use App\Modules\HR\Models\StaffMember;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

if (! function_exists('phase8F1UserAs')) {
    function phase8F1UserAs(Role $role): User
    {
        (new \Database\Seeders\RolePermissionSeeder())->run();
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user->fresh() ?? $user;
    }
}

if (! function_exists('phase8F1Fixture')) {
    /**
     * Section + level + current year + class group + subject + teacher + room,
     * with a four-row bell schedule (three periods, one break). Must be called
     * while acting as a user holding timetable.manage.
     *
     * @return array{
     *     section: SchoolSection, level: ClassLevel, year: AcademicYear,
     *     group: ClassGroup, subject: Subject, staff: StaffMember, room: Room,
     *     periods: list<TimetablePeriod>,
     * }
     */
    function phase8F1Fixture(): array
    {
        $section = SchoolSection::factory()->create();
        $level = ClassLevel::factory()->create(['school_section_id' => $section->getKey()]);
        $year = AcademicYear::factory()->current()->create([
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-06-30',
        ]);
        $group = ClassGroup::factory()->create([
            'class_level_id' => $level->getKey(),
            'academic_year_id' => $year->getKey(),
            'name' => 'Form 1 Alpha',
        ]);

        $periods = app(DefineTimetablePeriods::class)->handle((int) $section->getKey(), [
            ['name' => 'Period 1', 'starts_at' => '07:30', 'ends_at' => '08:20'],
            ['name' => 'Period 2', 'starts_at' => '08:20', 'ends_at' => '09:10'],
            ['name' => 'BREAK', 'starts_at' => '09:10', 'ends_at' => '09:30', 'is_break' => true],
            ['name' => 'Period 3', 'starts_at' => '09:30', 'ends_at' => '10:20'],
        ]);

        return [
            'section' => $section,
            'level' => $level,
            'year' => $year,
            'group' => $group,
            'subject' => Subject::factory()->create(['name' => 'Mathematics']),
            'staff' => StaffMember::factory()->create(['first_name' => 'John', 'last_name' => 'Anderson']),
            'room' => Room::factory()->create(['name' => 'Room 204']),
            'periods' => $periods,
        ];
    }
}

// ── Bell schedule ───────────────────────────────────────────────────────

it('defines an ordered bell schedule with stored durations', function () {
    actingAs(phase8F1UserAs(Role::Administrator));
    $fixture = phase8F1Fixture();

    $periods = $fixture['periods'];

    expect($periods)->toHaveCount(4)
        ->and($periods[0]->sequence)->toBe(1)
        ->and($periods[0]->duration_minutes)->toBe(50)
        ->and($periods[2]->is_break)->toBeTrue()
        ->and($periods[2]->duration_minutes)->toBe(20);
});

it('rejects an overlapping or inverted bell schedule', function () {
    actingAs(phase8F1UserAs(Role::Administrator));
    $section = SchoolSection::factory()->create();

    $define = app(DefineTimetablePeriods::class);

    expect(fn () => $define->handle((int) $section->getKey(), [
        ['name' => 'Period 1', 'starts_at' => '07:30', 'ends_at' => '08:20'],
        ['name' => 'Period 2', 'starts_at' => '08:00', 'ends_at' => '08:50'],
    ]))->toThrow(ValidationException::class);

    expect(fn () => $define->handle((int) $section->getKey(), [
        ['name' => 'Period 1', 'starts_at' => '08:20', 'ends_at' => '07:30'],
    ]))->toThrow(ValidationException::class);
});

it('refuses to redefine a bell schedule that slots already hang off', function () {
    actingAs(phase8F1UserAs(Role::Administrator));
    $fixture = phase8F1Fixture();

    app(AssignTimetableSlot::class)->handle(
        classGroupId: (int) $fixture['group']->getKey(),
        dayOfWeek: 1,
        timetablePeriodId: (int) $fixture['periods'][0]->getKey(),
        subjectId: (int) $fixture['subject']->getKey(),
        staffMemberId: (int) $fixture['staff']->getKey(),
    );

    // FK RESTRICT surfaces as a domain error, not a silent re-pointing of
    // cells at renumbered periods.
    expect(fn () => app(DefineTimetablePeriods::class)->handle((int) $fixture['section']->getKey(), [
        ['name' => 'Period 1', 'starts_at' => '08:00', 'ends_at' => '09:00'],
    ]))->toThrow(DomainException::class, 'remove them before redefining');
});

// ── Assigning slots ─────────────────────────────────────────────────────

it('assigns a slot, denormalising the year and writing the audit entry', function () {
    actingAs(phase8F1UserAs(Role::VicePrincipal));
    $fixture = phase8F1Fixture();

    $slot = app(AssignTimetableSlot::class)->handle(
        classGroupId: (int) $fixture['group']->getKey(),
        dayOfWeek: 2,
        timetablePeriodId: (int) $fixture['periods'][0]->getKey(),
        subjectId: (int) $fixture['subject']->getKey(),
        staffMemberId: (int) $fixture['staff']->getKey(),
        roomId: (int) $fixture['room']->getKey(),
    );

    expect($slot->academic_year_id)->toBe((int) $fixture['year']->getKey())
        ->and($slot->day_of_week)->toBe(2)
        ->and($slot->effective_from->toDateString())->toBe('2026-09-01');

    expect((int) AuditLog::query()
        ->where('auditable_type', TimetableSlot::class)
        ->where('action', 'created')
        ->count())->toBe(1);
});

it('refuses a break period, a foreign-section period and a Sunday', function () {
    actingAs(phase8F1UserAs(Role::Administrator));
    $fixture = phase8F1Fixture();
    $assign = app(AssignTimetableSlot::class);

    $base = [
        'classGroupId' => (int) $fixture['group']->getKey(),
        'dayOfWeek' => 1,
        'timetablePeriodId' => (int) $fixture['periods'][0]->getKey(),
        'subjectId' => (int) $fixture['subject']->getKey(),
        'staffMemberId' => (int) $fixture['staff']->getKey(),
    ];

    // BREAK rows take no lessons.
    expect(fn () => $assign->handle(...array_merge($base, [
        'timetablePeriodId' => (int) $fixture['periods'][2]->getKey(),
    ])))->toThrow(ValidationException::class);

    // A period from another section's bell schedule.
    $otherPeriod = TimetablePeriod::factory()->create();
    expect(fn () => $assign->handle(...array_merge($base, [
        'timetablePeriodId' => (int) $otherPeriod->getKey(),
    ])))->toThrow(ValidationException::class);

    // The week runs Monday(1)..Saturday(6).
    expect(fn () => $assign->handle(...array_merge($base, ['dayOfWeek' => 7])))
        ->toThrow(ValidationException::class);
});

it('refuses an inactive teacher', function () {
    actingAs(phase8F1UserAs(Role::Administrator));
    $fixture = phase8F1Fixture();

    $inactive = StaffMember::factory()->inactive()->create();

    expect(fn () => app(AssignTimetableSlot::class)->handle(
        classGroupId: (int) $fixture['group']->getKey(),
        dayOfWeek: 1,
        timetablePeriodId: (int) $fixture['periods'][0]->getKey(),
        subjectId: (int) $fixture['subject']->getKey(),
        staffMemberId: (int) $inactive->getKey(),
    ))->toThrow(ValidationException::class);
});

it('requires timetable.manage to assign - a teacher may only look', function () {
    actingAs(phase8F1UserAs(Role::Administrator));
    $fixture = phase8F1Fixture();

    actingAs(phase8F1UserAs(Role::Teacher));

    expect(fn () => app(AssignTimetableSlot::class)->handle(
        classGroupId: (int) $fixture['group']->getKey(),
        dayOfWeek: 1,
        timetablePeriodId: (int) $fixture['periods'][0]->getKey(),
        subjectId: (int) $fixture['subject']->getKey(),
        staffMemberId: (int) $fixture['staff']->getKey(),
    ))->toThrow(AuthorizationException::class);
});

it('removes a slot and preserves what the cell held in the audit trail', function () {
    actingAs(phase8F1UserAs(Role::Administrator));
    $fixture = phase8F1Fixture();

    $slot = app(AssignTimetableSlot::class)->handle(
        classGroupId: (int) $fixture['group']->getKey(),
        dayOfWeek: 3,
        timetablePeriodId: (int) $fixture['periods'][1]->getKey(),
        subjectId: (int) $fixture['subject']->getKey(),
        staffMemberId: (int) $fixture['staff']->getKey(),
    );

    app(RemoveTimetableSlot::class)->handle((int) $slot->getKey());

    expect(TimetableSlot::query()->count())->toBe(0);

    expect((int) AuditLog::query()
        ->where('auditable_type', TimetableSlot::class)
        ->where('action', 'deleted')
        ->count())->toBe(1);
});

// ── Attendance mode ─────────────────────────────────────────────────────

it('switches a class group to per-lesson attendance', function () {
    actingAs(phase8F1UserAs(Role::VicePrincipal));
    $fixture = phase8F1Fixture();

    $group = app(SetClassGroupAttendanceMode::class)->handle(
        (int) $fixture['group']->getKey(),
        AttendanceMode::PerLesson,
    );

    expect($group->attendance_mode)->toBe(AttendanceMode::PerLesson);

    $reloaded = ClassGroup::query()->findOrFail((int) $fixture['group']->getKey());
    expect($reloaded->attendance_mode)->toBe(AttendanceMode::PerLesson);
});

it('rejects daily mode under a framework that requires per-lesson attendance, naming the blank bulletin blocks', function () {
    // 07-students §9.7: "Enabling a MINESEC framework on a class group with
    // attendance_mode='daily' is a configuration error rejected at save, with
    // the message naming the report-card blocks that would print blank."
    actingAs(phase8F1UserAs(Role::VicePrincipal));
    $fixture = phase8F1Fixture();

    AssessmentFramework::factory()->create([
        'school_section_id' => $fixture['section']->getKey(),
        'academic_year_id' => $fixture['year']->getKey(),
        'requires_per_lesson_attendance' => true,
    ]);

    app(SetClassGroupAttendanceMode::class)->handle(
        (int) $fixture['group']->getKey(),
        AttendanceMode::PerLesson,
    );

    expect(fn () => app(SetClassGroupAttendanceMode::class)->handle(
        (int) $fixture['group']->getKey(),
        AttendanceMode::Daily,
    ))->toThrow(DomainException::class, "heures d'absence");
});

// ── The screen ──────────────────────────────────────────────────────────

it('renders the timetable screen with grid, tabs and legend for a viewer', function () {
    actingAs(phase8F1UserAs(Role::Administrator));
    $fixture = phase8F1Fixture();

    app(AssignTimetableSlot::class)->handle(
        classGroupId: (int) $fixture['group']->getKey(),
        dayOfWeek: 1,
        timetablePeriodId: (int) $fixture['periods'][0]->getKey(),
        subjectId: (int) $fixture['subject']->getKey(),
        staffMemberId: (int) $fixture['staff']->getKey(),
        roomId: (int) $fixture['room']->getKey(),
    );

    Livewire::test(TimetableIndex::class)
        ->assertSee('Form 1 Alpha')
        ->assertSee('Mathematics')
        ->assertSee('John Anderson')
        ->assertSee('Room 204')
        ->assertSee('BREAK')
        ->assertSee(__('timetable.tab_exam'));
});

it('forbids the component without timetable.view', function () {
    actingAs(phase8F1UserAs(Role::Bursar));

    Livewire::test(TimetableIndex::class)->assertForbidden();
});

it('shows a clear notice for Generate Timetable instead of a silent no-op', function () {
    actingAs(phase8F1UserAs(Role::Administrator));
    phase8F1Fixture();

    Livewire::test(TimetableIndex::class)
        ->call('generate')
        ->assertSee(__('timetable.generate_unavailable'));
});

it('assigns a slot end-to-end through the component and surfaces conflicts inline', function () {
    actingAs(phase8F1UserAs(Role::Administrator));
    $fixture = phase8F1Fixture();

    Livewire::test(TimetableIndex::class)
        ->call('startAssign')
        ->set('classGroupId', (string) $fixture['group']->getKey())
        ->set('assignDay', '1')
        ->set('assignPeriodId', (string) $fixture['periods'][0]->getKey())
        ->set('assignSubjectId', (string) $fixture['subject']->getKey())
        ->set('assignStaffId', (string) $fixture['staff']->getKey())
        ->call('assign')
        ->assertHasNoErrors();

    expect(TimetableSlot::query()->count())->toBe(1);

    // The same cell again: the DB constraint's refusal renders inline.
    Livewire::test(TimetableIndex::class)
        ->call('startAssign')
        ->set('classGroupId', (string) $fixture['group']->getKey())
        ->set('assignDay', '1')
        ->set('assignPeriodId', (string) $fixture['periods'][0]->getKey())
        ->set('assignSubjectId', (string) $fixture['subject']->getKey())
        ->set('assignStaffId', (string) $fixture['staff']->getKey())
        ->call('assign')
        ->assertHasErrors('assign');

    expect(TimetableSlot::query()->count())->toBe(1);
});

it('keeps the two timetable language files structurally identical', function () {
    $en = require lang_path('en/timetable.php');
    $fr = require lang_path('fr/timetable.php');

    $flatten = function (array $tree, string $prefix = '') use (&$flatten): array {
        $keys = [];
        foreach ($tree as $key => $value) {
            $keys[] = is_array($value)
                ? $flatten($value, $prefix.$key.'.')
                : [$prefix.$key];
        }

        return array_merge(...($keys === [] ? [[]] : $keys));
    };

    expect($flatten($en))->toEqualCanonicalizing($flatten($fr));
});
