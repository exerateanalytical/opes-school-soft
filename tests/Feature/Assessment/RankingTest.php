<?php

declare(strict_types=1);

use App\Modules\Assessment\Actions\ComputePeriodResults;
use App\Modules\Assessment\Actions\ComputeRanking;
use App\Modules\Assessment\Domain\SubjectResult;
use App\Modules\Assessment\Models\AssessmentFramework;
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
 * docs/specs/01-assessment.md 10.2 (C3 / T4), 10.4 (T10, T11), 10.5 and 12.6.
 *
 * The helpers below are duplicated - guarded by function_exists - across the
 * three Assessment aggregation suites rather than required from a shared file,
 * because requiring a Pest file would re-register its tests. Whichever suite
 * Pest loads first wins, and each file still runs alone.
 *
 * Prerequisite rows for other modules (years, sections, levels, class groups,
 * streams) go in through the query builder: those tables belong to other
 * workstreams and this suite must not depend on their factories to stay green.
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
// T4 - the NULL discipline. Four separate consequences, asserted separately,
// because v1 satisfied none of them and a student who silently ranks last is a
// defect a parent notices before the school does.
// ---------------------------------------------------------------------------

it('T4a: a student with Sigma-coef = 0 gets a NULL average, not 0.00', function () {
    actingAs(a4ExamsOfficer());

    $framework = a4Framework();
    $period = a4Period($framework);
    $levelId = a4ClassLevel($framework);
    $groupId = a4ClassGroup($framework, $levelId);
    $enrollmentId = a4Enrollment($framework, $levelId, $groupId);

    app(ComputePeriodResults::class)->handle($period, [
        // Every subject exempt in every component: 6.4 case 3. The subject is
        // unassessed, so its coefficient leaves the denominator as well.
        $enrollmentId => [a4Subject(1, null, 400), a4Subject(2, null, 300)],
    ]);

    $result = a4ResultFor($period, $enrollmentId);

    expect($result->general_average)->toBeNull()
        ->and($result->general_average_rounded)->toBeNull()
        ->and($result->coefficient_sum)->toBe('0.00')
        ->and($result->is_pass)->toBeNull()
        ->and($result->isAssessed())->toBeFalse()
        ->and($result->nc_reason)->toBe(PeriodResult::NC_NULL_AVERAGE);
});

it('T4b: a NULL average receives no rank', function () {
    actingAs(a4ExamsOfficer());

    $framework = a4Framework();
    $period = a4Period($framework);
    $levelId = a4ClassLevel($framework);
    $groupId = a4ClassGroup($framework, $levelId);

    $assessed = a4Enrollment($framework, $levelId, $groupId);
    $unassessed = a4Enrollment($framework, $levelId, $groupId);

    app(ComputePeriodResults::class)->handle($period, [
        $assessed => [a4Subject(1, '15.000', 400)],
        $unassessed => [a4Subject(1, null, 400)],
    ]);

    app(ComputeRanking::class)->handle($period);

    expect(a4ResultFor($period, $assessed)->rank_position)->toBe(1)
        ->and(a4ResultFor($period, $unassessed)->rank_position)->toBeNull()
        ->and(a4ResultFor($period, $unassessed)->is_ranked)->toBeFalse();
});

it('T4c: a NULL average is absent from the ranking denominator', function () {
    actingAs(a4ExamsOfficer());

    $framework = a4Framework();
    $period = a4Period($framework);
    $levelId = a4ClassLevel($framework);
    $groupId = a4ClassGroup($framework, $levelId);

    $payload = [];

    // Six assessed students and two with nothing to assess: 10.4's worked
    // example prints `/ 6`, not `/ 8`.
    foreach (['15.200', '14.050', '13.600', '13.010', '13.010', '11.400'] as $score) {
        $payload[a4Enrollment($framework, $levelId, $groupId)] = [a4Subject(1, $score, 100)];
    }

    $nullOne = a4Enrollment($framework, $levelId, $groupId);
    $nullTwo = a4Enrollment($framework, $levelId, $groupId);
    $payload[$nullOne] = [a4Subject(1, null, 400)];
    $payload[$nullTwo] = [a4Subject(1, null, 400)];

    app(ComputePeriodResults::class)->handle($period, $payload);
    $ranked = app(ComputeRanking::class)->handle($period);

    expect($ranked)->toBe(6);

    $denominators = PeriodResult::query()
        ->where('assessment_period_id', $period)
        ->ranked()
        ->pluck('rank_denominator')
        ->unique()
        ->values()
        ->all();

    expect($denominators)->toBe([6])
        ->and(a4ResultFor($period, $nullOne)->rank_denominator)->toBeNull();
});

it('T4d: a NULL average is absent from every class statistic', function () {
    actingAs(a4ExamsOfficer());

    $framework = a4Framework();
    $period = a4Period($framework);
    $levelId = a4ClassLevel($framework);
    $groupId = a4ClassGroup($framework, $levelId);

    $payload = [];

    foreach (['12.000', '14.000', '16.000'] as $score) {
        $payload[a4Enrollment($framework, $levelId, $groupId)] = [a4Subject(1, $score, 100)];
    }

    $payload[a4Enrollment($framework, $levelId, $groupId)] = [a4Subject(1, null, 400)];

    app(ComputePeriodResults::class)->handle($period, $payload);
    app(ComputeRanking::class)->handle($period);
    $statistics = app(\App\Modules\Assessment\Actions\ComputeClassStatistics::class)->handle($period);

    $general = collect($statistics)->firstOrFail(fn ($row): bool => $row->isGeneral());

    // n = 3, not 4. The mean is 14.000, not 10.500 - which is what a fourth
    // student entering as a zero would have produced, and what v1 printed.
    expect($general->n)->toBe(3)
        ->and($general->mean)->toBe('14.000')
        ->and($general->min_score)->toBe('12.000')
        ->and($general->max_score)->toBe('16.000')
        ->and($general->pass_count)->toBe(3)
        ->and($general->pass_rate)->toBe('100.00');
});

it('T4: the database itself refuses to rank a NULL average', function () {
    // Belt and braces on the four assertions above: even a hand-written UPDATE
    // - a data fix, a future Action, an import - cannot reproduce v1's bug.
    $result = PeriodResult::factory()->unassessed()->create();

    expect(fn () => DB::table('period_results')
        ->where('id', $result->getKey())
        ->update(['is_ranked' => true, 'rank_position' => 30, 'rank_denominator' => 30])
    )->toThrow(QueryException::class);
});

// ---------------------------------------------------------------------------
// T10 / T11 - ties, exclusions and rounding-before-ordering.
// ---------------------------------------------------------------------------

it('T10: ties take competition ranking and the following rank is skipped', function () {
    actingAs(a4ExamsOfficer());

    $framework = a4Framework();
    $period = a4Period($framework);
    $levelId = a4ClassLevel($framework);
    $groupId = a4ClassGroup($framework, $levelId);

    // 10.4's worked example, in order.
    $students = [];
    $payload = [];

    foreach (['15.200', '14.050', '13.600', '13.010', '13.010', '11.400'] as $index => $score) {
        $id = a4Enrollment($framework, $levelId, $groupId);
        $students[$index] = $id;
        $payload[$id] = [a4Subject(1, $score, 100)];
    }

    app(ComputePeriodResults::class)->handle($period, $payload);
    app(ComputeRanking::class)->handle($period);

    $ranks = array_map(
        static fn (int $id): ?int => a4ResultFor($period, $id)->rank_position,
        $students,
    );

    // 1, 2, 3, 4, 4, 6 - rank 5 does not exist, which is the whole of
    // "competition ranking" and the opposite of dense ranking's 1,2,3,4,4,5.
    expect($ranks)->toBe([1, 2, 3, 4, 4, 6]);
});

it('T10: NC students are excluded from the rank and from the denominator', function () {
    actingAs(a4ExamsOfficer());

    // 10.5: a November arrival is not ranked against students who sat
    // Sequence 1. They still get a full card with their marks.
    $framework = a4Framework();
    $sequenceOne = a4Period($framework, '2026-11-30');
    $sequenceTwo = a4Period($framework, '2027-01-31');
    $levelId = a4ClassLevel($framework);
    $groupId = a4ClassGroup($framework, $levelId);

    $onTime = a4Enrollment($framework, $levelId, $groupId);
    $lateArrival = a4Enrollment(
        $framework,
        $levelId,
        $groupId,
        null,
        '2026-09-05',
        ['assessable_from_period_id' => $sequenceTwo],
    );

    app(ComputePeriodResults::class)->handle($sequenceOne, [
        $onTime => [a4Subject(1, '12.000', 100)],
        $lateArrival => [a4Subject(1, '18.000', 100)],
    ]);

    app(ComputeRanking::class)->handle($sequenceOne);

    $late = a4ResultFor($sequenceOne, $lateArrival);

    // The average EXISTS - the card prints 18.00 - but there is no rank, and
    // the on-time student's denominator is 1, not 2.
    expect($late->general_average_rounded)->toBe('18.000')
        ->and($late->nc_reason)->toBe(PeriodResult::NC_NOT_YET_ASSESSABLE)
        ->and($late->is_ranked)->toBeFalse()
        ->and($late->rank_position)->toBeNull()
        ->and(a4ResultFor($sequenceOne, $onTime)->rank_position)->toBe(1)
        ->and(a4ResultFor($sequenceOne, $onTime)->rank_denominator)->toBe(1);
});

it('T11: two students at raw 13.0138 and 13.0072 both print 13.01 and share rank 4', function () {
    actingAs(a4ExamsOfficer());

    $framework = a4Framework();
    $period = a4Period($framework);
    $levelId = a4ClassLevel($framework);
    $groupId = a4ClassGroup($framework, $levelId);

    $ahead = [];

    foreach (['15.200', '14.050', '13.600'] as $score) {
        $ahead[] = a4Enrollment($framework, $levelId, $groupId);
    }

    $atangana = a4Enrollment($framework, $levelId, $groupId);
    $tabi = a4Enrollment($framework, $levelId, $groupId);
    $njoya = a4Enrollment($framework, $levelId, $groupId);

    $payload = [];

    foreach (['15.200', '14.050', '13.600'] as $index => $score) {
        $payload[$ahead[$index]] = [a4Subject(1, $score, 100)];
    }

    // 10.1's table: 234.25 / 18 = 13.013888... The raw DECIMAL(6,3) value is
    // 13.014; the second student is a hair lower at 13.007. They differ in the
    // third decimal and in NOTHING a parent can see.
    $payload[$atangana] = [
        a4Subject(1, '13.000', 400),
        a4Subject(2, '11.500', 300),
        a4Subject(3, '14.250', 300),
        a4Subject(4, '12.000', 400),
        a4Subject(5, '15.000', 200),
        a4Subject(6, '13.500', 200),
    ];
    $payload[$tabi] = [a4Subject(1, '13.007', 100)];
    $payload[$njoya] = [a4Subject(1, '11.400', 100)];

    app(ComputePeriodResults::class)->handle($period, $payload);
    app(ComputeRanking::class)->handle($period);

    $a = a4ResultFor($period, $atangana);
    $t = a4ResultFor($period, $tabi);

    expect($a->general_average)->toBe('13.014')
        ->and($t->general_average)->toBe('13.007')
        // Different raw values, one printed number...
        ->and($a->general_average_rounded)->toBe('13.010')
        ->and($t->general_average_rounded)->toBe('13.010')
        // ...and therefore one rank. Ordering reads the ROUNDED value.
        ->and($a->rank_position)->toBe(4)
        ->and($t->rank_position)->toBe(4)
        // Rank 5 is skipped.
        ->and(a4ResultFor($period, $njoya)->rank_position)->toBe(6)
        // 10.4: the student's own Sigma-coef is printed so the basis is visible.
        ->and($a->coefficient_sum)->toBe('18.00')
        ->and($a->weighted_total)->toBe('234.250');
});

// ---------------------------------------------------------------------------
// 10.4 - the cohort rule. "The class" is not the answer.
// ---------------------------------------------------------------------------

it('same_stream ranks students against their own subject basket only', function () {
    actingAs(a4ExamsOfficer());

    $framework = a4Framework(['rank_cohort_rule' => 'same_stream']);
    $period = a4Period($framework);
    $levelId = a4ClassLevel($framework);
    $groupId = a4ClassGroup($framework, $levelId);

    $sciences = a4Stream($framework, ['MATH', 'PHY', 'SVT']);
    $arts = a4Stream($framework, ['LIT', 'HIST', 'PHIL']);

    $scienceTop = a4Enrollment($framework, $levelId, $groupId, $sciences);
    $scienceLow = a4Enrollment($framework, $levelId, $groupId, $sciences);
    $artsOnly = a4Enrollment($framework, $levelId, $groupId, $arts);

    app(ComputePeriodResults::class)->handle($period, [
        $scienceTop => [a4Subject(1, '15.000', 400)],
        $scienceLow => [a4Subject(1, '11.000', 400)],
        // Higher than either science student, and irrelevant to them: a
        // different basket is a different Sigma-coef.
        $artsOnly => [a4Subject(1, '18.000', 200)],
    ]);

    app(ComputeRanking::class)->handle($period);

    expect(a4ResultFor($period, $scienceTop)->rank_position)->toBe(1)
        ->and(a4ResultFor($period, $scienceTop)->rank_denominator)->toBe(2)
        ->and(a4ResultFor($period, $scienceLow)->rank_position)->toBe(2)
        // The arts student is FIRST of one, not first of three.
        ->and(a4ResultFor($period, $artsOnly)->rank_position)->toBe(1)
        ->and(a4ResultFor($period, $artsOnly)->rank_denominator)->toBe(1);
});

it('same_stream keys on the basket CONTENT, so two streams with one basket are one cohort', function () {
    actingAs(a4ExamsOfficer());

    $framework = a4Framework(['rank_cohort_rule' => 'same_stream']);
    $period = a4Period($framework);
    $levelId = a4ClassLevel($framework);
    $groupId = a4ClassGroup($framework, $levelId);

    // Same subjects, entered in a different order by a different administrator.
    $streamA = a4Stream($framework, ['MATH', 'PHY', 'SVT']);
    $streamB = a4Stream($framework, ['SVT', 'MATH', 'PHY']);

    $one = a4Enrollment($framework, $levelId, $groupId, $streamA);
    $two = a4Enrollment($framework, $levelId, $groupId, $streamB);

    app(ComputePeriodResults::class)->handle($period, [
        $one => [a4Subject(1, '15.000', 400)],
        $two => [a4Subject(1, '11.000', 400)],
    ]);

    app(ComputeRanking::class)->handle($period);

    expect(a4ResultFor($period, $one)->cohort_key)
        ->toBe(a4ResultFor($period, $two)->cohort_key)
        ->and(a4ResultFor($period, $one)->rank_denominator)->toBe(2)
        ->and(a4ResultFor($period, $two)->rank_position)->toBe(2);
});

it('the `all` rule ranks every student in the scope regardless of basket', function () {
    actingAs(a4ExamsOfficer());

    $framework = a4Framework(['rank_cohort_rule' => 'all']);
    $period = a4Period($framework);
    $levelId = a4ClassLevel($framework);
    $groupId = a4ClassGroup($framework, $levelId);

    $sciences = a4Stream($framework, ['MATH', 'PHY']);
    $arts = a4Stream($framework, ['LIT', 'HIST']);

    $science = a4Enrollment($framework, $levelId, $groupId, $sciences);
    $art = a4Enrollment($framework, $levelId, $groupId, $arts);

    app(ComputePeriodResults::class)->handle($period, [
        $science => [a4Subject(1, '15.000', 400)],
        $art => [a4Subject(1, '18.000', 200)],
    ]);

    app(ComputeRanking::class)->handle($period);

    expect(a4ResultFor($period, $art)->rank_position)->toBe(1)
        ->and(a4ResultFor($period, $science)->rank_position)->toBe(2)
        ->and(a4ResultFor($period, $science)->rank_denominator)->toBe(2);
});

it('rank_scope = class_level ranks across the class groups of one level', function () {
    actingAs(a4ExamsOfficer());

    $framework = a4Framework(['rank_scope' => 'class_level', 'rank_cohort_rule' => 'all']);
    $period = a4Period($framework);
    $levelId = a4ClassLevel($framework);
    $groupA = a4ClassGroup($framework, $levelId);
    $groupB = a4ClassGroup($framework, $levelId);

    $inA = a4Enrollment($framework, $levelId, $groupA);
    $inB = a4Enrollment($framework, $levelId, $groupB);

    app(ComputePeriodResults::class)->handle($period, [
        $inA => [a4Subject(1, '12.000', 100)],
        $inB => [a4Subject(1, '17.000', 100)],
    ]);

    app(ComputeRanking::class)->handle($period);

    // Two different class groups, one cohort of 2 - which is exactly what
    // rank_scope exists to express.
    expect(a4ResultFor($period, $inB)->rank_position)->toBe(1)
        ->and(a4ResultFor($period, $inA)->rank_position)->toBe(2)
        ->and(a4ResultFor($period, $inA)->rank_denominator)->toBe(2);
});

// ---------------------------------------------------------------------------
// 12.6 - the mid-period transfer.
// ---------------------------------------------------------------------------

it('ranks a mid-period transfer in the class group they FINISHED the period in', function () {
    actingAs(a4ExamsOfficer());

    $framework = a4Framework(['rank_cohort_rule' => 'all']);
    $period = a4Period($framework, '2026-12-15');
    $levelId = a4ClassLevel($framework);
    $oldGroup = a4ClassGroup($framework, $levelId);
    $newGroup = a4ClassGroup($framework, $levelId);

    $transferred = a4Enrollment($framework, $levelId, $oldGroup);

    // The transfer of 07-students 5: close the old segment the day BEFORE, open
    // the new one ON the effective date. No second Enrollment, so every mark in
    // the period still counts (12.6 rule 2).
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
    $alreadyInNew = a4Enrollment($framework, $levelId, $newGroup);

    app(ComputePeriodResults::class)->handle($period, [
        $transferred => [a4Subject(1, '16.000', 100)],
        $stayedInOld => [a4Subject(1, '19.000', 100)],
        $alreadyInNew => [a4Subject(1, '10.000', 100)],
    ]);

    app(ComputeRanking::class)->handle($period);

    $result = a4ResultFor($period, $transferred);

    expect($result->class_group_id)->toBe($newGroup)
        // First of the NEW class, not second of the old one. Ranked once, and
        // absent from the old group's denominator (rule 5).
        ->and($result->rank_position)->toBe(1)
        ->and($result->rank_denominator)->toBe(2)
        ->and(a4ResultFor($period, $stayedInOld)->rank_denominator)->toBe(1)
        ->and(a4ResultFor($period, $alreadyInNew)->rank_position)->toBe(2);
});

// ---------------------------------------------------------------------------
// Framework-level and authorisation guards.
// ---------------------------------------------------------------------------

it('leaves no rank at all when the framework does not use rank', function () {
    actingAs(a4ExamsOfficer());

    // Family F, 8.4 / T19: rank, average and mention are absent from the card.
    $framework = a4Framework(['uses_rank' => false]);
    $period = a4Period($framework);
    $levelId = a4ClassLevel($framework);
    $groupId = a4ClassGroup($framework, $levelId);
    $enrollmentId = a4Enrollment($framework, $levelId, $groupId);

    app(ComputePeriodResults::class)->handle($period, [
        $enrollmentId => [a4Subject(1, '15.000', 400)],
    ]);

    expect(app(ComputeRanking::class)->handle($period))->toBe(0)
        ->and(a4ResultFor($period, $enrollmentId)->rank_position)->toBeNull()
        ->and(a4ResultFor($period, $enrollmentId)->is_ranked)->toBeFalse();
});

it('denies a teacher, who may enter marks but may not compile results', function () {
    (new \Database\Seeders\RolePermissionSeeder)->run();
    $teacher = User::factory()->create(['name' => 'Subject Teacher']);
    $teacher->assignRole(Role::Teacher->value);
    actingAs($teacher->fresh() ?? $teacher);

    $framework = a4Framework();
    $period = a4Period($framework);

    expect(fn () => app(ComputeRanking::class)->handle($period))
        ->toThrow(AuthorizationException::class);
});

it('is idempotent: recomputing a period does not duplicate results', function () {
    actingAs(a4ExamsOfficer());

    $framework = a4Framework();
    $period = a4Period($framework);
    $levelId = a4ClassLevel($framework);
    $groupId = a4ClassGroup($framework, $levelId);
    $enrollmentId = a4Enrollment($framework, $levelId, $groupId);

    $payload = [$enrollmentId => [a4Subject(1, '15.000', 400)]];

    app(ComputePeriodResults::class)->handle($period, $payload);
    app(ComputeRanking::class)->handle($period);
    app(ComputePeriodResults::class)->handle($period, $payload);
    app(ComputeRanking::class)->handle($period);

    expect(PeriodResult::query()->where('assessment_period_id', $period)->count())->toBe(1)
        ->and(a4ResultFor($period, $enrollmentId)->rank_position)->toBe(1);
});
