<?php

declare(strict_types=1);

use App\Modules\Assessment\Actions\AssignInvigilators;
use App\Modules\Assessment\Actions\GenerateSeating;
use App\Modules\Assessment\Actions\ScheduleExam;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\ExamInvigilator;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Database\Factories\ExamFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function examUserAs(Role $role): User
{
    (new \Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user->fresh() ?? $user;
}

function examStaffId(string $lastName = 'Tabi'): int
{
    return (int) DB::table('staff_members')->insertGetId([
        'staff_no' => 'ST-'.Str::upper(Str::random(8)),
        'first_name' => 'Marie',
        'last_name' => $lastName,
        'gender' => 'female',
        'phone' => '+237'.random_int(600000000, 699999999),
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function examRoomId(int $capacity, string $code): int
{
    return (int) DB::table('rooms')->insertGetId([
        'code' => $code,
        'name' => 'Hall '.$code,
        'capacity' => $capacity,
        'type' => 'hall',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** Seats `n` candidates into `$classGroupId` via open enrolment segments. */
function examCandidates(int $classGroupId, int $n): void
{
    $yearId = ExamFactory::academicYearId();
    $classLevelId = (int) DB::table('class_groups')->where('id', $classGroupId)->value('class_level_id');
    $sectionId = (int) DB::table('class_levels')->where('id', $classLevelId)->value('school_section_id');

    for ($i = 0; $i < $n; $i++) {
        $studentId = (int) DB::table('students')->insertGetId([
            'matricule' => 'MAT'.Str::upper(Str::random(10)),
            'admission_no' => 'ADM'.Str::upper(Str::random(10)),
            'first_name' => 'Candidate',
            'last_name' => str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            'gender' => 'male',
            'date_of_birth' => '2012-04-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrollmentId = (int) DB::table('enrollments')->insertGetId([
            'student_id' => $studentId,
            'academic_year_id' => $yearId,
            'class_level_id' => $classLevelId,
            'school_section_id' => $sectionId,
            'status' => 'active',
            'is_repeat' => 0,
            'enrollment_type' => 'new',
            'enrolled_on' => '2026-09-05',
            'boarding_status' => 'day',
            'financial_clearance' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('enrollment_segments')->insert([
            'enrollment_id' => $enrollmentId,
            'class_group_id' => $classGroupId,
            'starts_on' => '2026-09-05',
            'ends_on' => null,
            'reason' => 'initial',
            'capacity_override' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}



// ---- 16.1: an Exam is a sitting, not an AssessmentPeriod ----------------

it('schedules a sitting with a date, a time, a duration, a room and a maximum', function () {
    $user = examUserAs(Role::Administrator);
    actingAs($user);

    $exam = ExamFactory::new()->makeOne();
    $roomId = examRoomId(60, 'H1');

    $scheduled = app(ScheduleExam::class)->handle(
        examTypeId: 1,
        assessmentPeriodId: $exam->assessment_period_id,
        subjectAllocationId: $exam->subject_allocation_id,
        classGroupId: $exam->class_group_id,
        scheduledOn: '2027-01-20',
        startsAt: '08:00',
        durationMinutes: 120,
        maxScore: '20.000',
        roomId: $roomId,
    );

    expect($scheduled->scheduled_on->toDateString())->toBe('2027-01-20')
        ->and($scheduled->starts_at)->toBe('08:00:00')
        ->and($scheduled->endsAt())->toBe('10:00:00')
        ->and($scheduled->room_id)->toBe($roomId)
        ->and($scheduled->created_by)->toBe($user->id);
});

it('refuses to schedule a sitting under a non-leaf period', function () {
    actingAs(examUserAs(Role::Administrator));

    $exam = ExamFactory::new()->makeOne();

    // Give the period a child: it is now a term, not a sequence, and 6.1
    // invariant 3 forbids a mark - hence an exam - under it.
    DB::table('assessment_periods')->insert([
        'academic_year_id' => (int) DB::table('assessment_periods')
            ->where('id', $exam->assessment_period_id)->value('academic_year_id'),
        'parent_id' => $exam->assessment_period_id,
        'type' => 'sequence',
        'code' => 'CHILD',
        'name' => 'Child',
        'name_fr' => 'Enfant',
        'order_index' => 1,
        'starts_on' => '2027-01-05',
        'ends_on' => '2027-02-06',
        'weight' => '1.0000',
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => app(ScheduleExam::class)->handle(
        1, $exam->assessment_period_id, $exam->subject_allocation_id,
        $exam->class_group_id, '2027-01-20', '08:00', 120, '20.000',
    ))->toThrow(ValidationException::class);
});

it('refuses a sitting dated outside its assessment period', function () {
    actingAs(examUserAs(Role::Administrator));

    $exam = ExamFactory::new()->makeOne();

    expect(fn () => app(ScheduleExam::class)->handle(
        1, $exam->assessment_period_id, $exam->subject_allocation_id,
        $exam->class_group_id, '2027-04-20', '08:00', 120, '20.000',
    ))->toThrow(ValidationException::class);
});

it('refuses a second sitting of the same type for the same subject and class group', function () {
    actingAs(examUserAs(Role::Administrator));

    $first = ExamFactory::new()->createOne();

    expect(fn () => app(ScheduleExam::class)->handle(
        $first->exam_type_id, $first->assessment_period_id, $first->subject_allocation_id,
        $first->class_group_id, '2027-01-21', '08:00', 120, '20.000',
    ))->toThrow(ValidationException::class);
});

it('refuses to schedule without academics.manage', function () {
    actingAs(examUserAs(Role::Librarian));

    $exam = ExamFactory::new()->makeOne();

    expect(fn () => app(ScheduleExam::class)->handle(
        1, $exam->assessment_period_id, $exam->subject_allocation_id,
        $exam->class_group_id, '2027-01-20', '08:00', 120, '20.000',
    ))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});



// ---- T24, first half: invigilator overlap (invariant 17) ----------------

it('T24: rejects an invigilator already booked into an overlapping sitting', function () {
    actingAs(examUserAs(Role::Administrator));

    $staffId = examStaffId('Ngo');

    $morning = ExamFactory::new()->at('2027-01-20', '08:00', 120)->createOne();
    $clashing = ExamFactory::new()->at('2027-01-20', '09:30', 90)->createOne();

    app(AssignInvigilators::class)->handle((int) $morning->getKey(), [
        ['staff_id' => $staffId, 'role' => ExamInvigilator::ROLE_CHIEF],
    ]);

    expect(fn () => app(AssignInvigilators::class)->handle((int) $clashing->getKey(), [
        ['staff_id' => $staffId],
    ]))->toThrow(ValidationException::class);

    // Nothing was written for the rejected paper: the refusal is not partial.
    expect(DB::table('exam_invigilators')->where('exam_id', $clashing->getKey())->count())->toBe(0);
});

it('T24 boundary: two sittings that merely touch end-to-start are not an overlap', function () {
    actingAs(examUserAs(Role::Administrator));

    $staffId = examStaffId('Tabi');

    // [08:00, 10:00) then [10:00, 12:00). The invigilator walks out of one
    // hall and into the next; this is the normal exam morning and a closed
    // interval would reject it.
    $first = ExamFactory::new()->at('2027-01-20', '08:00', 120)->createOne();
    $second = ExamFactory::new()->at('2027-01-20', '10:00', 120)->createOne();

    app(AssignInvigilators::class)->handle((int) $first->getKey(), [['staff_id' => $staffId]]);
    $created = app(AssignInvigilators::class)->handle((int) $second->getKey(), [['staff_id' => $staffId]]);

    expect($created)->toHaveCount(1)
        ->and(DB::table('exam_invigilators')->where('staff_id', $staffId)->count())->toBe(2);
});

it('T24 boundary: one minute of overlap is still an overlap', function () {
    actingAs(examUserAs(Role::Administrator));

    $staffId = examStaffId('Fon');

    $first = ExamFactory::new()->at('2027-01-20', '08:00', 120)->createOne();
    $second = ExamFactory::new()->at('2027-01-20', '09:59', 60)->createOne();

    app(AssignInvigilators::class)->handle((int) $first->getKey(), [['staff_id' => $staffId]]);

    expect(fn () => app(AssignInvigilators::class)->handle((int) $second->getKey(), [
        ['staff_id' => $staffId],
    ]))->toThrow(ValidationException::class);
});

it('T24: the same clock hours on a different date are not an overlap', function () {
    actingAs(examUserAs(Role::Administrator));

    $staffId = examStaffId('Bello');

    $monday = ExamFactory::new()->at('2027-01-20', '08:00', 120)->createOne();
    $tuesday = ExamFactory::new()->at('2027-01-21', '08:00', 120)->createOne();

    app(AssignInvigilators::class)->handle((int) $monday->getKey(), [['staff_id' => $staffId]]);
    app(AssignInvigilators::class)->handle((int) $tuesday->getKey(), [['staff_id' => $staffId]]);

    expect(DB::table('exam_invigilators')->where('staff_id', $staffId)->count())->toBe(2);
});

it('a cancelled sitting does not occupy an invigilator', function () {
    actingAs(examUserAs(Role::Administrator));

    $staffId = examStaffId('Njoya');

    $cancelled = ExamFactory::new()->at('2027-01-20', '08:00', 120)->createOne();
    $live = ExamFactory::new()->at('2027-01-20', '08:30', 120)->createOne();

    app(AssignInvigilators::class)->handle((int) $cancelled->getKey(), [['staff_id' => $staffId]]);
    $cancelled->update(['status' => Exam::STATUS_CANCELLED]);

    app(AssignInvigilators::class)->handle((int) $live->getKey(), [['staff_id' => $staffId]]);

    expect(DB::table('exam_invigilators')->where('exam_id', $live->getKey())->count())->toBe(1);
});

it('refuses to list the same staff member twice on one paper', function () {
    actingAs(examUserAs(Role::Administrator));

    $staffId = examStaffId('Eyong');
    $exam = ExamFactory::new()->createOne();

    app(AssignInvigilators::class)->handle((int) $exam->getKey(), [['staff_id' => $staffId]]);

    expect(fn () => app(AssignInvigilators::class)->handle((int) $exam->getKey(), [
        ['staff_id' => $staffId],
    ]))->toThrow(ValidationException::class);
});



// ---- T24, second half: seat capacity ------------------------------------

it('T24: rejects a seating plan with more candidates than chairs', function () {
    actingAs(examUserAs(Role::Administrator));

    $roomId = examRoomId(20, 'SMALL');
    $exam = ExamFactory::new()->at('2027-01-20', '08:00', 120)->createOne(['room_id' => $roomId]);

    examCandidates($exam->class_group_id, 25);

    expect(fn () => app(GenerateSeating::class)->handle((int) $exam->getKey()))
        ->toThrow(ValidationException::class);

    expect(DB::table('exam_seatings')->count())->toBe(0);
});

it('seats every candidate in class-list order with a labelled chair', function () {
    actingAs(examUserAs(Role::Administrator));

    $roomId = examRoomId(60, 'BIG');
    $exam = ExamFactory::new()->at('2027-01-20', '08:00', 120)->createOne(['room_id' => $roomId]);

    examCandidates($exam->class_group_id, 42);

    $seatings = app(GenerateSeating::class)->handle((int) $exam->getKey());

    expect($seatings)->toHaveCount(42)
        ->and($seatings[0]->seat_label)->toBe('BIG-001')
        ->and($seatings[41]->seat_label)->toBe('BIG-042');

    // Every candidate exactly once, every chair exactly once.
    expect(DB::table('exam_seatings')->where('exam_id', $exam->getKey())
        ->distinct()->count('enrollment_id'))->toBe(42);
});

it('T24: counts chairs across every overlapping sitting that shares the room', function () {
    // Two class groups in one hall at one hour is ordinary practice; what must
    // not happen is more bodies than chairs. A per-exam count would let 30+30
    // into a 40-seat hall with both checks passing.
    actingAs(examUserAs(Role::Administrator));

    $roomId = examRoomId(40, 'SHARED');

    $first = ExamFactory::new()->at('2027-01-20', '08:00', 120)->createOne(['room_id' => $roomId]);
    $second = ExamFactory::new()->at('2027-01-20', '09:00', 120)->createOne(['room_id' => $roomId]);

    examCandidates($first->class_group_id, 30);
    examCandidates($second->class_group_id, 30);

    app(GenerateSeating::class)->handle((int) $first->getKey());

    expect(fn () => app(GenerateSeating::class)->handle((int) $second->getKey()))
        ->toThrow(ValidationException::class);
});

it('a sitting in the same room later the same day has the hall to itself', function () {
    actingAs(examUserAs(Role::Administrator));

    $roomId = examRoomId(40, 'SEQ');

    $morning = ExamFactory::new()->at('2027-01-20', '08:00', 120)->createOne(['room_id' => $roomId]);
    $afternoon = ExamFactory::new()->at('2027-01-20', '10:00', 120)->createOne(['room_id' => $roomId]);

    examCandidates($morning->class_group_id, 35);
    examCandidates($afternoon->class_group_id, 35);

    app(GenerateSeating::class)->handle((int) $morning->getKey());
    $second = app(GenerateSeating::class)->handle((int) $afternoon->getKey());

    expect($second)->toHaveCount(35)
        ->and($second[0]->seat_label)->toBe('SEQ-001');
});

it('spills a large cohort across the halls it is given, in order', function () {
    actingAs(examUserAs(Role::Administrator));

    $a = examRoomId(30, 'HA');
    $b = examRoomId(30, 'HB');

    $exam = ExamFactory::new()->at('2027-01-20', '08:00', 120)->createOne(['room_id' => $a]);
    examCandidates($exam->class_group_id, 45);

    $seatings = app(GenerateSeating::class)->handle((int) $exam->getKey(), [$a, $b]);

    expect($seatings)->toHaveCount(45)
        ->and(DB::table('exam_seatings')->where('room_id', $a)->count())->toBe(30)
        ->and(DB::table('exam_seatings')->where('room_id', $b)->count())->toBe(15);
});

it('refuses to seat a sitting that has no room', function () {
    actingAs(examUserAs(Role::Administrator));

    $exam = ExamFactory::new()->createOne(['room_id' => null]);
    examCandidates($exam->class_group_id, 3);

    expect(fn () => app(GenerateSeating::class)->handle((int) $exam->getKey()))
        ->toThrow(ValidationException::class);
});

it('refuses to regenerate a seating plan that already exists', function () {
    actingAs(examUserAs(Role::Administrator));

    $roomId = examRoomId(60, 'AGAIN');
    $exam = ExamFactory::new()->createOne(['room_id' => $roomId]);
    examCandidates($exam->class_group_id, 5);

    app(GenerateSeating::class)->handle((int) $exam->getKey());

    expect(fn () => app(GenerateSeating::class)->handle((int) $exam->getKey()))
        ->toThrow(ValidationException::class);
});

