<?php

declare(strict_types=1);

use App\Modules\Assessment\Actions\ComputePeriodResults;
use App\Modules\Assessment\Domain\SubjectResult;
use App\Modules\Assessment\Models\AssessmentFramework;
use App\Modules\Assessment\Models\GradeBand;
use App\Modules\Assessment\Models\PeriodResult;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Support\Score\Score;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/**
 * docs/specs/01-assessment.md 11 - grade points and the coefficient-weighted
 * GPA - and 3.3's banding rules, which are what turn a score into a point.
 *
 * The a4* helpers are duplicated from RankingTest.php under function_exists
 * guards rather than required: requiring a Pest file would re-register its
 * tests. Whichever suite Pest loads first wins, and each file still runs alone.
 */
if (! function_exists('a4ExamsOfficer')) {
    function a4ExamsOfficer(): User
    {
        (new \Database\Seeders\RolePermissionSeeder)->run();
        $user = User::factory()->create(['name' => 'Exams Officer']);
        $user->assignRole(Role::ExamsOfficer->value);

        return $user->fresh() ?? $user;
    }
}

if (! function_exists('a4Framework')) {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function a4Framework(array $overrides = []): AssessmentFramework
    {
        return AssessmentFramework::factory()->create($overrides);
    }
}

if (! function_exists('a4Period')) {
    function a4Period(
        AssessmentFramework $framework,
        string $endsOn = '2026-12-15',
        ?int $parentId = null,
        string $type = 'sequence',
    ): int {
        return (int) DB::table('assessment_periods')->insertGetId([
            'academic_year_id' => $framework->academic_year_id,
            'framework_id' => $framework->getKey(),
            'parent_id' => $parentId,
            'type' => $type,
            'code' => Str::upper(Str::random(8)),
            'name' => 'Sequence',
            'name_fr' => 'Sequence',
            'order_index' => 1,
            'starts_on' => '2026-09-05',
            'ends_on' => $endsOn,
            'weight' => '1.0000',
            'counts_toward_parent' => true,
            'is_reporting_period' => true,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('a4ClassLevel')) {
    function a4ClassLevel(AssessmentFramework $framework): int
    {
        return (int) DB::table('class_levels')->insertGetId([
            'school_section_id' => $framework->school_section_id,
            'code' => 'L'.Str::upper(Str::random(6)),
            'name' => 'Form 4',
            'name_fr' => 'Troisieme',
            'order_index' => 4,
            'is_exam_class' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('a4ClassGroup')) {
    function a4ClassGroup(AssessmentFramework $framework, int $classLevelId): int
    {
        return (int) DB::table('class_groups')->insertGetId([
            'class_level_id' => $classLevelId,
            'academic_year_id' => $framework->academic_year_id,
            'name' => 'Group '.Str::upper(Str::random(8)),
            'capacity' => 60,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('a4Stream')) {
    /**
     * @param  list<string>  $basket
     */
    function a4Stream(AssessmentFramework $framework, array $basket): int
    {
        return (int) DB::table('streams')->insertGetId([
            'school_section_id' => $framework->school_section_id,
            'code' => 'S'.Str::upper(Str::random(6)),
            'name' => 'Stream',
            'name_fr' => 'Serie',
            'subject_basket' => (string) json_encode($basket),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('a4Enrollment')) {
    /**
     * An enrollment plus the open `initial` segment that 07-students 5.2 says
     * every live enrollment carries. Rank is resolved through that segment, so
     * an enrollment without one is not a fixture this module can use.
     *
     * @param  array<string, mixed>  $overrides
     */
    function a4Enrollment(
        AssessmentFramework $framework,
        int $classLevelId,
        int $classGroupId,
        ?int $streamId = null,
        string $segmentStartsOn = '2026-09-05',
        array $overrides = [],
    ): int {
        $suffix = Str::upper(Str::random(10));

        $studentId = (int) DB::table('students')->insertGetId([
            'matricule' => 'OS-26-'.$suffix,
            'matricule_is_official' => true,
            'admission_no' => 'HA/ADM/2026/'.$suffix,
            'first_name' => 'Test',
            'last_name' => 'Student '.$suffix,
            'date_of_birth' => '2012-04-11',
            'place_of_birth' => 'Bamenda',
            'gender' => 'male',
            'nationality' => 'CM',
            'status' => 'prospective',
            'is_archived' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrollmentId = (int) DB::table('enrollments')->insertGetId(array_merge([
            'student_id' => $studentId,
            'academic_year_id' => $framework->academic_year_id,
            'class_level_id' => $classLevelId,
            'stream_id' => $streamId,
            'school_section_id' => $framework->school_section_id,
            'status' => 'active',
            'is_repeat' => false,
            'enrollment_type' => 'new',
            'enrolled_on' => '2026-09-05',
            'boarding_status' => 'day',
            'financial_clearance' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        DB::table('enrollment_segments')->insert([
            'enrollment_id' => $enrollmentId,
            'class_group_id' => $classGroupId,
            'starts_on' => $segmentStartsOn,
            'ends_on' => null,
            'reason' => 'initial',
            'capacity_override' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $enrollmentId;
    }
}

if (! function_exists('a4Subject')) {
    /**
     * A stage-4 SubjectResult. `$score` NULL is UNASSESSED (6.4 case 3), which
     * is what removes the coefficient from the denominator too.
     */
    function a4Subject(
        int|string $key,
        ?string $score,
        int $coefficientHundredths,
        bool $countsTowardAverage = true,
    ): SubjectResult {
        return new SubjectResult(
            $key,
            $score === null ? null : Score::of($score),
            $coefficientHundredths,
            $countsTowardAverage,
            [],
        );
    }
}

if (! function_exists('a4ResultFor')) {
    function a4ResultFor(int $periodId, int $enrollmentId): PeriodResult
    {
        /** @var PeriodResult $result */
        $result = PeriodResult::query()
            ->where('assessment_period_id', $periodId)
            ->where('enrollment_id', $enrollmentId)
            ->firstOrFail();

        return $result;
    }
}

// ---------------------------------------------------------------------------

if (! function_exists('a4Ladder')) {
    /**
     * 3.3's worked Family A /20 ladder: starts at 0, contiguous, closed at 20.
     */
    function a4Ladder(AssessmentFramework $framework, ?int $classLevelId = null): void
    {
        foreach (\Database\Factories\GradeBandFactory::familyAInternalLadder() as $index => $band) {
            GradeBand::factory()->create([
                'framework_id' => $framework->getKey(),
                'class_level_id' => $classLevelId,
                'min_score' => $band['min_score'],
                'max_score' => $band['max_score'],
                'label' => $band['label'],
                'label_fr' => $band['label_fr'],
                'mention' => $band['label_fr'],
                'grade_point' => $band['grade_point'],
                'is_pass' => $band['is_pass'],
                'order_index' => $index,
            ]);
        }
    }
}

if (! function_exists('a4SixSubjectCard')) {
    /**
     * The 10.1 / 11 worked bulletin: six subjects, Sigma-coef 18, Sigma(M x Coef)
     * 234.25, moyenne 13.01 and GPA 3.11.
     *
     * @return list<SubjectResult>
     */
    function a4SixSubjectCard(): array
    {
        return [
            a4Subject(1, '13.000', 400),   // Assez Bien, 3.00
            a4Subject(2, '11.500', 300),   // Passable,   2.00
            a4Subject(3, '14.250', 300),   // Bien,       4.00
            a4Subject(4, '12.000', 400),   // Assez Bien, 3.00
            a4Subject(5, '15.000', 200),   // Bien,       4.00
            a4Subject(6, '13.500', 200),   // Assez Bien, 3.00
        ];
    }
}

it('computes the 11 worked example: 56.00 / 18 = 3.11', function () {
    actingAs(a4ExamsOfficer());

    $framework = a4Framework();
    a4Ladder($framework);
    $period = a4Period($framework);
    $levelId = a4ClassLevel($framework);
    $groupId = a4ClassGroup($framework, $levelId);
    $enrollmentId = a4Enrollment($framework, $levelId, $groupId);

    app(ComputePeriodResults::class)->handle($period, [
        $enrollmentId => a4SixSubjectCard(),
    ]);

    $result = a4ResultFor($period, $enrollmentId);

    // Both printed, neither derived from the other: the GPA is built from
    // BANDED points and is deliberately coarser than the moyenne, so the two
    // do not track linearly (11).
    expect($result->gpa)->toBe('3.11')
        ->and($result->general_average_rounded)->toBe('13.010')
        ->and($result->coefficient_sum)->toBe('18.00')
        ->and($result->weighted_total)->toBe('234.250')
        ->and($result->subjects_counted)->toBe(6);
});

it('bands the ROUNDED average, so the printed number explains the printed mention', function () {
    actingAs(a4ExamsOfficer());

    $framework = a4Framework();
    a4Ladder($framework);
    $period = a4Period($framework);
    $levelId = a4ClassLevel($framework);
    $groupId = a4ClassGroup($framework, $levelId);
    $enrollmentId = a4Enrollment($framework, $levelId, $groupId);

    // 3.3: 11.995 rounds to 12.00 BEFORE banding, so it bands Assez Bien -
    // not Passable, which is what banding the raw value would give and which
    // would print `12.00 / Passable` on a card whose own ladder starts Assez
    // Bien at 12.
    app(ComputePeriodResults::class)->handle($period, [
        $enrollmentId => [a4Subject(1, '11.995', 100)],
    ]);

    $result = a4ResultFor($period, $enrollmentId);

    /** @var GradeBand $band */
    $band = GradeBand::query()->findOrFail($result->grade_band_id);

    expect($result->general_average_rounded)->toBe('12.000')
        ->and($band->label_fr)->toBe('Assez Bien')
        ->and($result->gpa)->toBe('3.00');
});

it('bands a perfect score, because the top band is closed', function () {
    actingAs(a4ExamsOfficer());

    $framework = a4Framework();
    a4Ladder($framework);
    $period = a4Period($framework);
    $levelId = a4ClassLevel($framework);
    $groupId = a4ClassGroup($framework, $levelId);
    $enrollmentId = a4Enrollment($framework, $levelId, $groupId);

    app(ComputePeriodResults::class)->handle($period, [
        $enrollmentId => [a4Subject(1, '20.000', 100)],
    ]);

    $result = a4ResultFor($period, $enrollmentId);

    /** @var GradeBand $band */
    $band = GradeBand::query()->findOrFail($result->grade_band_id);

    // 3.3 clause 4: without the closed top band, 20.00 would band nowhere and
    // the card would print a blank grade beside a perfect mark.
    expect($band->label_fr)->toBe('Très Bien')
        ->and($result->gpa)->toBe('5.00');
});

it('returns a NULL GPA when any banded subject has a NULL grade point', function () {
    actingAs(a4ExamsOfficer());

    $framework = a4Framework();
    a4Ladder($framework);

    // One misconfigured band with no point. 11: the GPA is NULL rather than
    // silently computed over the subset that happens to be configured, which
    // would flatter or punish the student depending on WHICH band was left
    // blank.
    GradeBand::query()
        ->where('framework_id', $framework->getKey())
        ->where('min_score', '12.000')
        ->update(['grade_point' => null]);

    $period = a4Period($framework);
    $levelId = a4ClassLevel($framework);
    $groupId = a4ClassGroup($framework, $levelId);
    $enrollmentId = a4Enrollment($framework, $levelId, $groupId);

    app(ComputePeriodResults::class)->handle($period, [
        $enrollmentId => a4SixSubjectCard(),
    ]);

    $result = a4ResultFor($period, $enrollmentId);

    // The moyenne is unaffected: the two numbers are independent (11).
    expect($result->gpa)->toBeNull()
        ->and($result->general_average_rounded)->toBe('13.010');
});

it('returns a NULL GPA when a subject falls in no configured band at all', function () {
    actingAs(a4ExamsOfficer());

    $framework = a4Framework();

    // A framework with an INCOMPLETE ladder - 3.3 says such a framework cannot
    // be published against, and the GPA must not pretend otherwise by scoring
    // only the subjects that happened to land inside it.
    GradeBand::factory()->create([
        'framework_id' => $framework->getKey(),
        'min_score' => '10.000',
        'max_score' => '12.000',
        'grade_point' => '2.00',
    ]);

    $period = a4Period($framework);
    $levelId = a4ClassLevel($framework);
    $groupId = a4ClassGroup($framework, $levelId);
    $enrollmentId = a4Enrollment($framework, $levelId, $groupId);

    app(ComputePeriodResults::class)->handle($period, [
        $enrollmentId => [a4Subject(1, '11.000', 100), a4Subject(2, '18.000', 100)],
    ]);

    expect(a4ResultFor($period, $enrollmentId)->gpa)->toBeNull();
});

it('prefers a class-level band ladder over the framework-wide one', function () {
    actingAs(a4ExamsOfficer());

    $framework = a4Framework();
    a4Ladder($framework);

    $period = a4Period($framework);
    $levelId = a4ClassLevel($framework);
    $groupId = a4ClassGroup($framework, $levelId);
    $enrollmentId = a4Enrollment($framework, $levelId, $groupId);

    // 3.3's optional narrowing: a Form 5 ladder that is stricter than the
    // school's general one. A single 0-20 band keeps the fixture honest about
    // the coverage invariant (contiguous, starts at 0, closed at the ceiling).
    GradeBand::factory()->create([
        'framework_id' => $framework->getKey(),
        'class_level_id' => $levelId,
        'min_score' => '0.000',
        'max_score' => '20.000',
        'label' => 'Exam Class',
        'label_fr' => 'Classe d examen',
        'grade_point' => '1.50',
    ]);

    app(ComputePeriodResults::class)->handle($period, [
        $enrollmentId => [a4Subject(1, '19.000', 100)],
    ]);

    // 1.50, not the framework-wide ladder's 5.00 for Très Bien.
    expect(a4ResultFor($period, $enrollmentId)->gpa)->toBe('1.50');
});

it('returns a NULL GPA when the average itself is NULL', function () {
    actingAs(a4ExamsOfficer());

    $framework = a4Framework();
    a4Ladder($framework);
    $period = a4Period($framework);
    $levelId = a4ClassLevel($framework);
    $groupId = a4ClassGroup($framework, $levelId);
    $enrollmentId = a4Enrollment($framework, $levelId, $groupId);

    app(ComputePeriodResults::class)->handle($period, [
        $enrollmentId => [a4Subject(1, null, 400)],
    ]);

    $result = a4ResultFor($period, $enrollmentId);

    // 10.2 again, one level up: a student with nothing to assess has no GPA,
    // no band and no pass verdict - not a 0.00 GPA that reads as failure.
    expect($result->gpa)->toBeNull()
        ->and($result->grade_band_id)->toBeNull()
        ->and($result->is_pass)->toBeNull();
});

it('weights the GPA by coefficient, not by subject count', function () {
    actingAs(a4ExamsOfficer());

    $framework = a4Framework();
    a4Ladder($framework);
    $period = a4Period($framework);
    $levelId = a4ClassLevel($framework);
    $groupId = a4ClassGroup($framework, $levelId);
    $enrollmentId = a4Enrollment($framework, $levelId, $groupId);

    // Maths (coef 8) at Très Bien 5.00; Art (coef 1) at Très Faible 0.00.
    // Weighted: (5.00 x 8 + 0.00 x 1) / 9 = 4.44. Unweighted would be 2.50.
    app(ComputePeriodResults::class)->handle($period, [
        $enrollmentId => [a4Subject(1, '17.000', 800), a4Subject(2, '3.000', 100)],
    ]);

    expect(a4ResultFor($period, $enrollmentId)->gpa)->toBe('4.44');
});

it('excludes a subject that does not count toward the average from the GPA too', function () {
    actingAs(a4ExamsOfficer());

    $framework = a4Framework();
    a4Ladder($framework);
    $period = a4Period($framework);
    $levelId = a4ClassLevel($framework);
    $groupId = a4ClassGroup($framework, $levelId);
    $enrollmentId = a4Enrollment($framework, $levelId, $groupId);

    app(ComputePeriodResults::class)->handle($period, [
        $enrollmentId => [
            a4Subject(1, '17.000', 400),
            // 5.1: reported on the card, excluded from the moyenne. Letting it
            // into the GPA would make the two numbers describe different sets
            // of subjects while sitting side by side on one bulletin.
            a4Subject(2, '3.000', 400, false),
        ],
    ]);

    $result = a4ResultFor($period, $enrollmentId);

    expect($result->gpa)->toBe('5.00')
        ->and($result->general_average_rounded)->toBe('17.000')
        ->and($result->coefficient_sum)->toBe('4.00');
});

it('denies a teacher, who may enter marks but may not compile a GPA', function () {
    (new \Database\Seeders\RolePermissionSeeder)->run();
    $teacher = User::factory()->create(['name' => 'Subject Teacher']);
    $teacher->assignRole(Role::Teacher->value);
    actingAs($teacher->fresh() ?? $teacher);

    $result = PeriodResult::factory()->create();

    expect(fn () => app(ComputePeriodResults::class)->handle($result->assessment_period_id, []))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});
