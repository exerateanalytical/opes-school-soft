<?php

declare(strict_types=1);

use App\Modules\Assessment\Actions\ComputeClassStatistics;
use App\Modules\Assessment\Actions\ComputePeriodResults;
use App\Modules\Assessment\Actions\ComputeRanking;
use App\Modules\Assessment\Domain\SubjectResult;
use App\Modules\Assessment\Models\AssessmentFramework;
use App\Modules\Assessment\Models\ClassStatistic;
use App\Modules\Assessment\Models\PeriodResult;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Support\Score\Score;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/**
 * docs/specs/01-assessment.md 10.7 - the statistics block, computed over
 * RANKED, NON-NULL students only.
 *
 * The a4* helpers come from RankingTest.php, guarded by function_exists so
 * either suite runs alone or both run together. See that file's header for why
 * they are duplicated rather than required.
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

if (! function_exists('a4GeneralStatistic')) {
    function a4GeneralStatistic(int $periodId, int $classGroupId): ClassStatistic
    {
        /** @var ClassStatistic $row */
        $row = ClassStatistic::query()
            ->where('assessment_period_id', $periodId)
            ->where('class_group_id', $classGroupId)
            ->where('subject_allocation_id', ClassStatistic::GENERAL)
            ->firstOrFail();

        return $row;
    }
}

it('computes mean, cote, median, population stdev and pass rate over the ranked cohort', function () {
    actingAs(a4ExamsOfficer());

    $framework = a4Framework();
    $period = a4Period($framework);
    $levelId = a4ClassLevel($framework);
    $groupId = a4ClassGroup($framework, $levelId);

    $payload = [];

    foreach (['12.000', '14.000', '16.000'] as $score) {
        $payload[a4Enrollment($framework, $levelId, $groupId)] = [a4Subject(1, $score, 100)];
    }

    app(ComputePeriodResults::class)->handle($period, $payload);
    app(ComputeRanking::class)->handle($period);
    app(ComputeClassStatistics::class)->handle($period);

    $row = a4GeneralStatistic($period, $groupId);

    // sigma = sqrt(((-2)^2 + 0 + 2^2) / 3) = sqrt(8/3) = 1.63299...
    // Divisor n, NOT n-1: the sample form would give 2.000 here, and the two
    // figures would be irreconcilable between two schools (10.7).
    expect($row->n)->toBe(3)
        ->and($row->mean)->toBe('14.000')
        ->and($row->min_score)->toBe('12.000')
        ->and($row->max_score)->toBe('16.000')
        ->and($row->median)->toBe('14.000')
        ->and($row->stdev_population)->toBe('1.6330')
        ->and($row->pass_count)->toBe(3)
        ->and($row->pass_rate)->toBe('100.00');
});

it('takes the LOWER median for an even cohort, as 10.7 states', function () {
    actingAs(a4ExamsOfficer());

    $framework = a4Framework();
    $period = a4Period($framework);
    $levelId = a4ClassLevel($framework);
    $groupId = a4ClassGroup($framework, $levelId);

    $payload = [];

    foreach (['10.000', '12.000', '14.000', '16.000'] as $score) {
        $payload[a4Enrollment($framework, $levelId, $groupId)] = [a4Subject(1, $score, 100)];
    }

    app(ComputePeriodResults::class)->handle($period, $payload);
    app(ComputeRanking::class)->handle($period);
    app(ComputeClassStatistics::class)->handle($period);

    // 12.000, not the 13.000 a mean-of-the-two-middles convention would give.
    // The choice is arbitrary; leaving it unstated is not.
    expect(a4GeneralStatistic($period, $groupId)->median)->toBe('12.000');
});

it('counts the pass rate from PassRule, not from GradeBand.is_pass', function () {
    actingAs(a4ExamsOfficer());

    // pass_score 10.000. A student exactly ON the pass mark passes (10.3's
    // `>=`), which is precisely the boundary v1's three sources disagreed at.
    $framework = a4Framework();
    $period = a4Period($framework);
    $levelId = a4ClassLevel($framework);
    $groupId = a4ClassGroup($framework, $levelId);

    $payload = [];

    foreach (['09.999', '10.000', '13.000', '17.000'] as $score) {
        $payload[a4Enrollment($framework, $levelId, $groupId)] = [a4Subject(1, $score, 100)];
    }

    app(ComputePeriodResults::class)->handle($period, $payload);
    app(ComputeRanking::class)->handle($period);
    app(ComputeClassStatistics::class)->handle($period);

    $row = a4GeneralStatistic($period, $groupId);

    // 9.999 rounds to 10.00 and therefore PASSES - the pass verdict reads the
    // same number the card prints (invariant 9), so a card showing 10.00 beside
    // a fail would be inexplicable.
    expect($row->n)->toBe(4)
        ->and($row->pass_count)->toBe(4)
        ->and($row->pass_rate)->toBe('100.00');
});

it('T4d: NULL-average and NC students are absent from every statistic', function () {
    actingAs(a4ExamsOfficer());

    $framework = a4Framework();
    $sequenceOne = a4Period($framework, '2026-11-30');
    $sequenceTwo = a4Period($framework, '2027-01-31');
    $levelId = a4ClassLevel($framework);
    $groupId = a4ClassGroup($framework, $levelId);

    $payload = [];

    foreach (['12.000', '14.000', '16.000'] as $score) {
        $payload[a4Enrollment($framework, $levelId, $groupId)] = [a4Subject(1, $score, 100)];
    }

    // Sigma-coef = 0: no average at all.
    $payload[a4Enrollment($framework, $levelId, $groupId)] = [a4Subject(1, null, 400)];

    // NC: an average of 2.000 that must not drag the class mean down, because
    // the student is not being compared with this class at all (10.5).
    $lateArrival = a4Enrollment(
        $framework,
        $levelId,
        $groupId,
        null,
        '2026-09-05',
        ['assessable_from_period_id' => $sequenceTwo],
    );
    $payload[$lateArrival] = [a4Subject(1, '2.000', 100)];

    app(ComputePeriodResults::class)->handle($sequenceOne, $payload);
    app(ComputeRanking::class)->handle($sequenceOne);
    app(ComputeClassStatistics::class)->handle($sequenceOne);

    $row = a4GeneralStatistic($sequenceOne, $groupId);

    // n = 3 out of 5 rows. The mean is 14.000; including the NC student would
    // have printed 11.000 and including a zero for the unassessed one, 8.800.
    expect($row->n)->toBe(3)
        ->and($row->mean)->toBe('14.000')
        ->and($row->min_score)->toBe('12.000')
        ->and($row->pass_count)->toBe(3)
        ->and($row->pass_rate)->toBe('100.00');
});

it('computes a separate statistics row per subject allocation', function () {
    actingAs(a4ExamsOfficer());

    $framework = a4Framework();
    $period = a4Period($framework);
    $levelId = a4ClassLevel($framework);
    $groupId = a4ClassGroup($framework, $levelId);

    $one = a4Enrollment($framework, $levelId, $groupId);
    $two = a4Enrollment($framework, $levelId, $groupId);

    app(ComputePeriodResults::class)->handle($period, [
        $one => [a4Subject(11, '18.000', 400), a4Subject(22, '8.000', 200)],
        $two => [a4Subject(11, '12.000', 400), a4Subject(22, '6.000', 200)],
    ]);
    app(ComputeRanking::class)->handle($period);
    app(ComputeClassStatistics::class)->handle($period);

    /** @var ClassStatistic $maths */
    $maths = ClassStatistic::query()
        ->where('assessment_period_id', $period)
        ->where('subject_allocation_id', 11)
        ->firstOrFail();

    /** @var ClassStatistic $arts */
    $arts = ClassStatistic::query()
        ->where('assessment_period_id', $period)
        ->where('subject_allocation_id', 22)
        ->firstOrFail();

    // 10.6 / 10.7: the per-subject figures read the NORMALISED, framework
    // -scaled subject score, so the cote printed beside a subject line is
    // comparable with the subject line itself.
    expect($maths->n)->toBe(2)
        ->and($maths->mean)->toBe('15.000')
        ->and($maths->min_score)->toBe('12.000')
        ->and($maths->max_score)->toBe('18.000')
        ->and($arts->mean)->toBe('7.000')
        // The subject pass rate is the subject's own, not the class moyenne's.
        ->and($arts->pass_count)->toBe(0)
        ->and($arts->pass_rate)->toBe('0.00');
});

it('drops an unassessed subject from that subject`s statistics without dropping the student', function () {
    actingAs(a4ExamsOfficer());

    $framework = a4Framework();
    $period = a4Period($framework);
    $levelId = a4ClassLevel($framework);
    $groupId = a4ClassGroup($framework, $levelId);

    $exempted = a4Enrollment($framework, $levelId, $groupId);
    $sat = a4Enrollment($framework, $levelId, $groupId);

    app(ComputePeriodResults::class)->handle($period, [
        // EPS exempt in every component (6.4 case 3): prints `Disp.`, leaves
        // both columns of the totals row, and leaves this subject's statistics.
        $exempted => [a4Subject(11, '14.000', 400), a4Subject(99, null, 100)],
        $sat => [a4Subject(11, '10.000', 400), a4Subject(99, '18.000', 100)],
    ]);
    app(ComputeRanking::class)->handle($period);
    app(ComputeClassStatistics::class)->handle($period);

    /** @var ClassStatistic $eps */
    $eps = ClassStatistic::query()
        ->where('assessment_period_id', $period)
        ->where('subject_allocation_id', 99)
        ->firstOrFail();

    // n = 1 for EPS, but the exempted student is still fully in the class
    // moyenne's sample, and their own Sigma-coef is 4.00, not 5.00.
    expect($eps->n)->toBe(1)
        ->and($eps->mean)->toBe('18.000')
        ->and(a4GeneralStatistic($period, $groupId)->n)->toBe(2)
        ->and(a4ResultFor($period, $exempted)->coefficient_sum)->toBe('4.00');
});

it('keeps two elective baskets in one class group as two separate statistics rows', function () {
    actingAs(a4ExamsOfficer());

    $framework = a4Framework(['rank_cohort_rule' => 'same_stream']);
    $period = a4Period($framework);
    $levelId = a4ClassLevel($framework);
    $groupId = a4ClassGroup($framework, $levelId);

    $sciences = a4Stream($framework, ['MATH', 'PHY']);
    $arts = a4Stream($framework, ['LIT', 'HIST']);

    $scienceOne = a4Enrollment($framework, $levelId, $groupId, $sciences);
    $scienceTwo = a4Enrollment($framework, $levelId, $groupId, $sciences);
    $artStudent = a4Enrollment($framework, $levelId, $groupId, $arts);

    app(ComputePeriodResults::class)->handle($period, [
        $scienceOne => [a4Subject(1, '10.000', 100)],
        $scienceTwo => [a4Subject(1, '12.000', 100)],
        $artStudent => [a4Subject(1, '18.000', 100)],
    ]);
    app(ComputeRanking::class)->handle($period);
    app(ComputeClassStatistics::class)->handle($period);

    /** @var list<ClassStatistic> $rows */
    $rows = ClassStatistic::query()
        ->where('assessment_period_id', $period)
        ->where('subject_allocation_id', ClassStatistic::GENERAL)
        ->orderBy('n')
        ->get()
        ->all();

    // A mean of 13.333 across both baskets would be the mean of two
    // non-comparable populations - the same reason a conseil rejects a mixed
    // ranking applies to the class mean printed beside it.
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->n)->toBe(1)
        ->and($rows[0]->mean)->toBe('18.000')
        ->and($rows[1]->n)->toBe(2)
        ->and($rows[1]->mean)->toBe('11.000');
});

it('counts a mid-period transfer in the statistics of the class they finished in', function () {
    actingAs(a4ExamsOfficer());

    $framework = a4Framework(['rank_cohort_rule' => 'all']);
    $period = a4Period($framework, '2026-12-15');
    $levelId = a4ClassLevel($framework);
    $oldGroup = a4ClassGroup($framework, $levelId);
    $newGroup = a4ClassGroup($framework, $levelId);

    $transferred = a4Enrollment($framework, $levelId, $oldGroup);

    DB::table('enrollment_segments')
        ->where('enrollment_id', $transferred)
        ->update(['ends_on' => '2026-11-13']);

    DB::table('enrollment_segments')->insert([
        'enrollment_id' => $transferred,
        'class_group_id' => $newGroup,
        'starts_on' => '2026-11-14',
        'ends_on' => null,
        'reason' => 'class_transfer',
        'capacity_override' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $stayedInOld = a4Enrollment($framework, $levelId, $oldGroup);

    app(ComputePeriodResults::class)->handle($period, [
        $transferred => [a4Subject(1, '20.000', 100)],
        $stayedInOld => [a4Subject(1, '10.000', 100)],
    ]);
    app(ComputeRanking::class)->handle($period);
    app(ComputeClassStatistics::class)->handle($period);

    // 12.6 rule 5: never on two rosters for one period. The old class's mean is
    // 10.000, not 15.000 - the transferred student left it entirely.
    expect(a4GeneralStatistic($period, $oldGroup)->n)->toBe(1)
        ->and(a4GeneralStatistic($period, $oldGroup)->mean)->toBe('10.000')
        ->and(a4GeneralStatistic($period, $newGroup)->n)->toBe(1)
        ->and(a4GeneralStatistic($period, $newGroup)->mean)->toBe('20.000');
});

it('writes no statistics at all for a period nobody could be assessed in', function () {
    actingAs(a4ExamsOfficer());

    $framework = a4Framework();
    $period = a4Period($framework);
    $levelId = a4ClassLevel($framework);
    $groupId = a4ClassGroup($framework, $levelId);

    app(ComputePeriodResults::class)->handle($period, [
        a4Enrollment($framework, $levelId, $groupId) => [a4Subject(1, null, 400)],
        a4Enrollment($framework, $levelId, $groupId) => [a4Subject(1, null, 400)],
    ]);
    app(ComputeRanking::class)->handle($period);

    // No row is the honest answer. A row with n = 0, mean 0.00 and a 0 % pass
    // rate would be indistinguishable from a class that genuinely all failed.
    expect(app(ComputeClassStatistics::class)->handle($period))->toBe([])
        ->and(ClassStatistic::query()->where('assessment_period_id', $period)->count())->toBe(0);
});

it('rebuilds rather than accumulates, so a vanished cohort leaves no stale mean', function () {
    actingAs(a4ExamsOfficer());

    $framework = a4Framework();
    $period = a4Period($framework);
    $levelId = a4ClassLevel($framework);
    $groupId = a4ClassGroup($framework, $levelId);
    $enrollmentId = a4Enrollment($framework, $levelId, $groupId);

    app(ComputePeriodResults::class)->handle($period, [$enrollmentId => [a4Subject(1, '15.000', 100)]]);
    app(ComputeRanking::class)->handle($period);
    app(ComputeClassStatistics::class)->handle($period);

    expect(a4GeneralStatistic($period, $groupId)->mean)->toBe('15.000');

    // The mark is corrected downward and the whole class group is recomputed
    // (15's amendment rule).
    app(ComputePeriodResults::class)->handle($period, [$enrollmentId => [a4Subject(1, '9.000', 100)]]);
    app(ComputeRanking::class)->handle($period);
    app(ComputeClassStatistics::class)->handle($period);

    // Two rows, not four: one general and one for the single subject. The
    // second run REPLACED the first rather than adding to it.
    expect(ClassStatistic::query()->where('assessment_period_id', $period)->count())->toBe(2)
        ->and(a4GeneralStatistic($period, $groupId)->mean)->toBe('9.000')
        ->and(a4GeneralStatistic($period, $groupId)->pass_count)->toBe(0)
        ->and(a4GeneralStatistic($period, $groupId)->pass_rate)->toBe('0.00');
});

it('the database refuses a statistics row claiming more passes than students', function () {
    $row = ClassStatistic::factory()->create();

    expect(fn () => DB::table('class_statistics')
        ->where('id', $row->getKey())
        ->update(['pass_count' => 99])
    )->toThrow(QueryException::class);
});

it('the database refuses figures on an empty sample', function () {
    $row = ClassStatistic::factory()->empty()->create();

    expect(fn () => DB::table('class_statistics')
        ->where('id', $row->getKey())
        ->update(['mean' => '12.000'])
    )->toThrow(QueryException::class);
});

it('denies a teacher, who may enter marks but may not compute class statistics', function () {
    (new \Database\Seeders\RolePermissionSeeder)->run();
    $teacher = User::factory()->create(['name' => 'Subject Teacher']);
    $teacher->assignRole(Role::Teacher->value);
    actingAs($teacher->fresh() ?? $teacher);

    $result = PeriodResult::factory()->create();

    expect(fn () => app(ComputeClassStatistics::class)->handle($result->assessment_period_id))
        ->toThrow(AuthorizationException::class);
});

