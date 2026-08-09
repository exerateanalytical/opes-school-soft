<?php

declare(strict_types=1);

use App\Modules\Attendance\Actions\OpenAttendanceRegister;
use App\Modules\Attendance\Actions\SubmitAttendanceRegister;
use App\Modules\Attendance\Livewire\CoverageReport;
use App\Modules\Attendance\Livewire\Index as AttendanceIndex;
use App\Modules\Attendance\Livewire\TakeRegister;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Models\AttendanceRegister;
use App\Modules\Identity\Domain\Role;
use App\Modules\Students\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/AttendanceTestHelpers.php';

uses(RefreshDatabase::class);

// ── Take Attendance ────────────────────────────────────────────────────────

it('renders the roster for an assigned teacher', function () {
    $fixture = phase8F2Fixture();
    $enrollments = phase8F2Enroll($fixture, 3);
    actingAs(phase8F2Teacher($fixture));

    $student = Student::query()->findOrFail($enrollments[0]->student_id);

    Livewire::test(TakeRegister::class)
        ->set('classGroupId', (string) $fixture['group']->getKey())
        ->set('date', '2026-09-07')
        ->assertSee($student->first_name)
        ->assertSee(__('attendance.mark_all_present'))
        ->assertSee(__('attendance.save'));
});

it('saves the register and its exception rows in ONE request', function () {
    $fixture = phase8F2Fixture();
    $enrollments = phase8F2Enroll($fixture, 3);
    actingAs(phase8F2Teacher($fixture));

    $absentee = (int) $enrollments[1]->getKey();

    // ONE Livewire call: open + batched submit (§9.9's contract).
    Livewire::test(TakeRegister::class)
        ->set('classGroupId', (string) $fixture['group']->getKey())
        ->set('date', '2026-09-07')
        ->set("marks.{$absentee}", 'absent')
        ->call('save')
        ->assertHasNoErrors();

    $register = AttendanceRegister::query()->firstOrFail();

    expect($register->status->value)->toBe('submitted')
        ->and($register->expected_count)->toBe(3)
        ->and($register->present_count)->toBe(2)
        ->and($register->absent_count)->toBe(1);

    expect(AttendanceRecord::query()->count())->toBe(1)
        ->and(AttendanceRecord::query()->firstOrFail()->enrollment_id)->toBe($absentee);
});

it('surfaces the teacher-assignment refusal on save instead of writing', function () {
    $fixture = phase8F2Fixture();
    phase8F2Enroll($fixture, 2);
    actingAs(phase8F2UserAs(Role::Teacher)); // no allocation

    Livewire::test(TakeRegister::class)
        ->set('classGroupId', (string) $fixture['group']->getKey())
        ->set('date', '2026-09-07')
        ->call('save')
        ->assertHasErrors('save');

    expect(AttendanceRegister::query()->count())->toBe(0);
});

it('forbids the take screen without attendance.take', function () {
    phase8F2Fixture();
    actingAs(phase8F2UserAs(Role::Bursar));

    Livewire::test(TakeRegister::class)->assertForbidden();
});

// ── Attendance Management (Index) ─────────────────────────────────────────

it('renders the management KPIs as em dashes when no register has been taken', function () {
    $fixture = phase8F2Fixture();
    phase8F2Enroll($fixture, 4);
    actingAs(phase8F2UserAs(Role::VicePrincipal));

    // Total students is real data (4); the day KPIs and month rate are NOT
    // RECORDED — "—", never 0% (09-ui §8.7).
    Livewire::test(AttendanceIndex::class)
        ->assertSee(__('attendance.kpi_month_rate'))
        ->assertSee('—')
        ->assertSee(__('attendance.no_registers_today'));
});

it('shows the day counts once a register is taken today', function () {
    $fixture = phase8F2Fixture();
    $enrollments = phase8F2Enroll($fixture, 3);
    actingAs(phase8F2UserAs(Role::VicePrincipal));

    // "Today" must be a seeded teaching day for the register to open.
    // (Laravel's TestCase tearDown resets the frozen clock.)
    \Illuminate\Support\Carbon::setTestNow('2026-09-07 09:00:00');

    $register = app(OpenAttendanceRegister::class)->handle((int) $fixture['group']->getKey());
    app(SubmitAttendanceRegister::class)->handle((int) $register->getKey(), [
        ['enrollment_id' => (int) $enrollments[0]->getKey(), 'status' => 'absent'],
    ]);

    Livewire::test(AttendanceIndex::class)
        ->assertSee($fixture['group']->name)
        ->assertSee(__('attendance.register_status.submitted'));
});

it('forbids the management screen without attendance.view', function () {
    phase8F2Fixture();
    actingAs(phase8F2UserAs(Role::Bursar));

    Livewire::test(AttendanceIndex::class)->assertForbidden();
});

// ── Register coverage (§9.6 — first-class screen) ─────────────────────────

it('reports coverage per class group - the never-taken class reads Not covered, not 100%', function () {
    $fixture = phase8F2Fixture();
    phase8F2Period($fixture);
    $enrollments = phase8F2Enroll($fixture, 2);
    actingAs(phase8F2UserAs(Role::VicePrincipal));

    // A second class group whose teacher takes ONE register.
    $covered = \App\Modules\Academics\Models\ClassGroup::factory()->create([
        'class_level_id' => $fixture['level']->getKey(),
        'academic_year_id' => $fixture['year']->getKey(),
        'stream_id' => null,
        'capacity' => 60,
    ]);
    \App\Modules\Students\Models\EnrollmentSegment::query()
        ->where('enrollment_id', $enrollments[1]->getKey())
        ->update(['class_group_id' => $covered->getKey()]);

    $register = app(OpenAttendanceRegister::class)->handle((int) $covered->getKey(), '2026-09-07');
    app(SubmitAttendanceRegister::class)->handle((int) $register->getKey(), []);

    Livewire::test(CoverageReport::class)
        ->assertSee($fixture['group']->name)
        ->assertSee($covered->name)
        // September 2026 has 26 teaching + 0 exam days minus the seeded
        // holiday (21st): 25 expected days for both groups.
        ->assertSee('25')
        ->assertSee(__('attendance.coverage_poor'));
});

it('forbids the coverage screen without attendance.view', function () {
    phase8F2Fixture();
    actingAs(phase8F2UserAs(Role::Bursar));

    Livewire::test(CoverageReport::class)->assertForbidden();
});
