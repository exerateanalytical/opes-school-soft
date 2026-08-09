<?php

declare(strict_types=1);

use App\Modules\Academics\Actions\ResolveCalendarDay;
use App\Modules\Academics\Actions\SeedSchoolCalendar;
use App\Modules\Academics\Actions\SetCalendarDayType;
use App\Modules\Academics\Domain\CalendarDayType;
use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Academics\Models\SchoolCalendarDay;
use App\Modules\Academics\Models\SchoolSection;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

if (! function_exists('phase8F1Year')) {
    /** A one-month year keeps the seeded row count reviewable by hand. */
    function phase8F1Year(): AcademicYear
    {
        return AcademicYear::factory()->current()->create([
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-30',
        ]);
    }
}

it('seeds every date of the year - Sundays weekend, the rest teaching', function () {
    actingAs(phase8F1UserAs(Role::Administrator));
    $year = phase8F1Year();

    $inserted = app(SeedSchoolCalendar::class)->handle((int) $year->getKey());

    // September 2026 has 30 days, of which 4 are Sundays (6, 13, 20, 27).
    expect($inserted)->toBe(30);

    expect(SchoolCalendarDay::query()
        ->where('academic_year_id', $year->getKey())
        ->where('day_type', CalendarDayType::Weekend->value)
        ->count())->toBe(4);

    expect(SchoolCalendarDay::query()
        ->where('academic_year_id', $year->getKey())
        ->where('day_type', CalendarDayType::Teaching->value)
        ->count())->toBe(26);

    // 09-ui §8.6: the six-day week is standard - Saturdays are teaching days.
    $saturday = SchoolCalendarDay::query()
        ->whereDate('date', '2026-09-05')
        ->firstOrFail();
    expect($saturday->day_type)->toBe(CalendarDayType::Teaching);
});

it('is idempotent - a re-run inserts nothing and overwrites no retyped day', function () {
    actingAs(phase8F1UserAs(Role::Administrator));
    $year = phase8F1Year();
    $yearId = (int) $year->getKey();

    app(SeedSchoolCalendar::class)->handle($yearId);

    // The school retypes a day (07-students §9.2: holidays are ENTERED, never
    // seeded)...
    app(SetCalendarDayType::class)->handle(
        $yearId, '2026-09-22', CalendarDayType::PublicHoliday, null, 'Decree holiday'
    );

    // ...and a second seed run must not resurrect "teaching" over it.
    expect(app(SeedSchoolCalendar::class)->handle($yearId))->toBe(0);

    $day = SchoolCalendarDay::query()->whereDate('date', '2026-09-22')->firstOrFail();
    expect($day->day_type)->toBe(CalendarDayType::PublicHoliday)
        ->and($day->label)->toBe('Decree holiday');
});

it('refuses to retype a date outside the academic year', function () {
    actingAs(phase8F1UserAs(Role::Administrator));
    $year = phase8F1Year();

    expect(fn () => app(SetCalendarDayType::class)->handle(
        (int) $year->getKey(), '2026-10-01', CalendarDayType::Closure
    ))->toThrow(ValidationException::class);
});

it('requires calendar.manage - a teacher can neither seed nor retype', function () {
    actingAs(phase8F1UserAs(Role::Teacher));
    $year = phase8F1Year();

    expect(fn () => app(SeedSchoolCalendar::class)->handle((int) $year->getKey()))
        ->toThrow(AuthorizationException::class);

    expect(fn () => app(SetCalendarDayType::class)->handle(
        (int) $year->getKey(), '2026-09-22', CalendarDayType::PublicHoliday
    ))->toThrow(AuthorizationException::class);
});

it('resolves section-specific over all-sections', function () {
    actingAs(phase8F1UserAs(Role::Administrator));
    $year = phase8F1Year();
    $yearId = (int) $year->getKey();
    $section = SchoolSection::factory()->create();
    $sectionId = (int) $section->getKey();

    app(SeedSchoolCalendar::class)->handle($yearId);

    // The whole school is teaching on the 10th, but this section sits exams.
    app(SetCalendarDayType::class)->handle(
        $yearId, '2026-09-10', CalendarDayType::Exam, $sectionId
    );

    $resolver = app(ResolveCalendarDay::class);

    $forSection = $resolver->handle($yearId, '2026-09-10', $sectionId);
    assert($forSection !== null);
    expect($forSection['day_type'])->toBe('exam')
        ->and($forSection['school_section_id'])->toBe($sectionId)
        ->and($forSection['allows_register'])->toBeTrue();

    // Another section still sees the all-sections teaching row.
    $otherSection = SchoolSection::factory()->create();
    $forOther = $resolver->handle($yearId, '2026-09-10', (int) $otherSection->getKey());
    assert($forOther !== null);
    expect($forOther['day_type'])->toBe('teaching')
        ->and($forOther['school_section_id'])->toBe(SchoolCalendarDay::SECTION_ALL);
});

it('reports a register-blocking day type through allows_register', function () {
    actingAs(phase8F1UserAs(Role::Administrator));
    $year = phase8F1Year();
    $yearId = (int) $year->getKey();

    app(SeedSchoolCalendar::class)->handle($yearId);
    app(SetCalendarDayType::class)->handle(
        $yearId, '2026-09-14', CalendarDayType::PublicHoliday, null, 'Holiday'
    );

    $resolved = app(ResolveCalendarDay::class)->handle($yearId, '2026-09-14');
    assert($resolved !== null);
    expect($resolved['allows_register'])->toBeFalse();
});

it('returns NULL for an unseeded date - the caller must block, not default to teaching', function () {
    actingAs(phase8F1UserAs(Role::Administrator));
    $year = phase8F1Year();

    // No seeding at all: §9.2 - a missing calendar blocks register creation
    // with a clear message rather than defaulting to "teaching".
    expect(app(ResolveCalendarDay::class)->handle((int) $year->getKey(), '2026-09-10'))
        ->toBeNull();
});

it('makes two all-sections rows for one date collide - the 0 sentinel guarantee', function () {
    actingAs(phase8F1UserAs(Role::Administrator));
    $year = phase8F1Year();

    SchoolCalendarDay::factory()->create([
        'academic_year_id' => $year->getKey(),
        'date' => '2026-09-03',
    ]);

    // Were school_section_id NULL, MySQL's UNIQUE index would treat each NULL
    // as distinct and both rows would land - the 04-fees NULL-in-UNIQUE trap.
    expect(fn () => DB::table('school_calendar_days')->insert([
        'academic_year_id' => $year->getKey(),
        'date' => '2026-09-03',
        'day_type' => 'closure',
        'school_section_id' => SchoolCalendarDay::SECTION_ALL,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('audits both the seed and the retype', function () {
    actingAs(phase8F1UserAs(Role::Administrator));
    $year = phase8F1Year();
    $yearId = (int) $year->getKey();

    app(SeedSchoolCalendar::class)->handle($yearId);
    app(SetCalendarDayType::class)->handle(
        $yearId, '2026-09-21', CalendarDayType::SchoolHoliday
    );

    expect((int) AuditLog::query()
        ->where('module', 'Academics')
        ->where('auditable_type', SchoolCalendarDay::class)
        ->count())->toBeGreaterThanOrEqual(2);
});
