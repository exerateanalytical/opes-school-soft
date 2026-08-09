<?php

declare(strict_types=1);

use App\Modules\Academics\Actions\AssignTimetableSlot;
use App\Modules\Academics\Actions\DefineTimetablePeriods;
use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Academics\Models\ClassGroup;
use App\Modules\Academics\Models\ClassLevel;
use App\Modules\Academics\Models\Room;
use App\Modules\Academics\Models\SchoolSection;
use App\Modules\Academics\Models\Subject;
use App\Modules\Academics\Models\TimetableSlot;
use App\Modules\HR\Models\StaffMember;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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

if (! function_exists('phase8F1ConflictFixture')) {
    /**
     * Two class groups in ONE section and year, one shared bell schedule -
     * the smallest world in which all three conflict rules can fire.
     *
     * @return array{
     *     year: AcademicYear, groupA: ClassGroup, groupB: ClassGroup,
     *     periodId: int, subject: Subject,
     *     teacher1: StaffMember, teacher2: StaffMember, room: Room,
     * }
     */
    function phase8F1ConflictFixture(): array
    {
    $section = SchoolSection::factory()->create();
    $level = ClassLevel::factory()->create(['school_section_id' => $section->getKey()]);
    $year = AcademicYear::factory()->current()->create([
        'starts_on' => '2026-09-01',
        'ends_on' => '2027-06-30',
    ]);

    $periods = app(DefineTimetablePeriods::class)->handle((int) $section->getKey(), [
        ['name' => 'Period 1', 'starts_at' => '07:30', 'ends_at' => '08:20'],
    ]);

    return [
        'year' => $year,
        'groupA' => ClassGroup::factory()->create([
            'class_level_id' => $level->getKey(),
            'academic_year_id' => $year->getKey(),
            'name' => 'Form 1 A',
        ]),
        'groupB' => ClassGroup::factory()->create([
            'class_level_id' => $level->getKey(),
            'academic_year_id' => $year->getKey(),
            'name' => 'Form 1 B',
        ]),
        'periodId' => (int) $periods[0]->getKey(),
        'subject' => Subject::factory()->create(),
            'teacher1' => StaffMember::factory()->create(),
            'teacher2' => StaffMember::factory()->create(),
            'room' => Room::factory()->create(),
        ];
    }
}

it('rejects slot_taken - the class group already has an entry for that day and period', function () {
    actingAs(phase8F1UserAs(Role::Administrator));
    $f = phase8F1ConflictFixture();
    $assign = app(AssignTimetableSlot::class);

    $assign->handle(
        classGroupId: (int) $f['groupA']->getKey(),
        dayOfWeek: 1,
        timetablePeriodId: $f['periodId'],
        subjectId: (int) $f['subject']->getKey(),
        staffMemberId: (int) $f['teacher1']->getKey(),
    );

    expect(fn () => $assign->handle(
        classGroupId: (int) $f['groupA']->getKey(),
        dayOfWeek: 1,
        timetablePeriodId: $f['periodId'],
        subjectId: (int) $f['subject']->getKey(),
        staffMemberId: (int) $f['teacher2']->getKey(),
    ))->toThrow(DomainException::class, 'slot_taken');

    expect(TimetableSlot::query()->count())->toBe(1);
});

it('rejects teacher_busy - the teacher is already booked in another class', function () {
    actingAs(phase8F1UserAs(Role::Administrator));
    $f = phase8F1ConflictFixture();
    $assign = app(AssignTimetableSlot::class);

    $assign->handle(
        classGroupId: (int) $f['groupA']->getKey(),
        dayOfWeek: 2,
        timetablePeriodId: $f['periodId'],
        subjectId: (int) $f['subject']->getKey(),
        staffMemberId: (int) $f['teacher1']->getKey(),
    );

    expect(fn () => $assign->handle(
        classGroupId: (int) $f['groupB']->getKey(),
        dayOfWeek: 2,
        timetablePeriodId: $f['periodId'],
        subjectId: (int) $f['subject']->getKey(),
        staffMemberId: (int) $f['teacher1']->getKey(),
    ))->toThrow(DomainException::class, 'teacher_busy');
});

it('rejects room_double_booked - the room is already occupied', function () {
    actingAs(phase8F1UserAs(Role::Administrator));
    $f = phase8F1ConflictFixture();
    $assign = app(AssignTimetableSlot::class);

    $assign->handle(
        classGroupId: (int) $f['groupA']->getKey(),
        dayOfWeek: 3,
        timetablePeriodId: $f['periodId'],
        subjectId: (int) $f['subject']->getKey(),
        staffMemberId: (int) $f['teacher1']->getKey(),
        roomId: (int) $f['room']->getKey(),
    );

    expect(fn () => $assign->handle(
        classGroupId: (int) $f['groupB']->getKey(),
        dayOfWeek: 3,
        timetablePeriodId: $f['periodId'],
        subjectId: (int) $f['subject']->getKey(),
        staffMemberId: (int) $f['teacher2']->getKey(),
        roomId: (int) $f['room']->getKey(),
    ))->toThrow(DomainException::class, 'room_double_booked');
});

it('lets any number of ROOM-LESS slots share a day and period - NULL never collides', function () {
    // The deliberate flip side of the room key: MySQL admits unlimited NULL
    // duplicates in a UNIQUE index, so slots without a room never conflict.
    actingAs(phase8F1UserAs(Role::Administrator));
    $f = phase8F1ConflictFixture();
    $assign = app(AssignTimetableSlot::class);

    $assign->handle(
        classGroupId: (int) $f['groupA']->getKey(),
        dayOfWeek: 4,
        timetablePeriodId: $f['periodId'],
        subjectId: (int) $f['subject']->getKey(),
        staffMemberId: (int) $f['teacher1']->getKey(),
    );

    $assign->handle(
        classGroupId: (int) $f['groupB']->getKey(),
        dayOfWeek: 4,
        timetablePeriodId: $f['periodId'],
        subjectId: (int) $f['subject']->getKey(),
        staffMemberId: (int) $f['teacher2']->getKey(),
    );

    expect(TimetableSlot::query()->where('day_of_week', 4)->count())->toBe(2);
});

it('rejects the conflict at the DATABASE even when the application layer is bypassed entirely', function () {
    // 09-ui acceptance 7: "Timetable conflicts are rejected by DB constraint,
    // proven by a concurrent-insert test." Two raw inserts stand in for two
    // concurrent requests: NEITHER passes through AssignTimetableSlot or any
    // app-level pre-check, so the only thing that can refuse the second row
    // is the uq_slot_class index itself - exactly what a lost race would rely
    // on. (Under RefreshDatabase both connections' writes sit in one
    // transaction; InnoDB enforces UNIQUE against uncommitted rows, so the
    // interleaving is equivalent to two racing sessions.)
    actingAs(phase8F1UserAs(Role::Administrator));
    $f = phase8F1ConflictFixture();

    $row = [
        'class_group_id' => (int) $f['groupA']->getKey(),
        'academic_year_id' => (int) $f['year']->getKey(),
        'day_of_week' => 5,
        'timetable_period_id' => $f['periodId'],
        'subject_id' => (int) $f['subject']->getKey(),
        'staff_member_id' => (int) $f['teacher1']->getKey(),
        'room_id' => null,
        'effective_from' => '2026-09-01',
        'effective_to' => null,
        'created_by' => (int) auth()->id(),
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('timetable_slots')->insert($row);

    // Request 2 books the same class/day/period with a DIFFERENT teacher and
    // room, so only uq_slot_class can be what refuses it.
    $second = array_merge($row, [
        'staff_member_id' => (int) $f['teacher2']->getKey(),
        'room_id' => (int) $f['room']->getKey(),
    ]);

    expect(fn () => DB::table('timetable_slots')->insert($second))
        ->toThrow(UniqueConstraintViolationException::class);

    expect((int) DB::table('timetable_slots')->where('day_of_week', 5)->count())->toBe(1);
});

it('scopes the conflict keys to the academic year - next year reuses the grid freely', function () {
    actingAs(phase8F1UserAs(Role::Administrator));
    $f = phase8F1ConflictFixture();
    $assign = app(AssignTimetableSlot::class);

    $assign->handle(
        classGroupId: (int) $f['groupA']->getKey(),
        dayOfWeek: 6,
        timetablePeriodId: $f['periodId'],
        subjectId: (int) $f['subject']->getKey(),
        staffMemberId: (int) $f['teacher1']->getKey(),
        roomId: (int) $f['room']->getKey(),
    );

    // Same level/section, NEXT year: same teacher, same room, same day and
    // period - no conflict, the keys are year-scoped.
    $nextYear = AcademicYear::factory()->create([
        'starts_on' => '2027-09-01',
        'ends_on' => '2028-06-30',
    ]);
    $nextGroup = ClassGroup::factory()->create([
        'class_level_id' => $f['groupA']->class_level_id,
        'academic_year_id' => $nextYear->getKey(),
        'name' => 'Form 1 A (next)',
    ]);

    $slot = $assign->handle(
        classGroupId: (int) $nextGroup->getKey(),
        dayOfWeek: 6,
        timetablePeriodId: $f['periodId'],
        subjectId: (int) $f['subject']->getKey(),
        staffMemberId: (int) $f['teacher1']->getKey(),
        roomId: (int) $f['room']->getKey(),
    );

    expect($slot->academic_year_id)->toBe((int) $nextYear->getKey());
});
