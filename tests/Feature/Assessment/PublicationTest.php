<?php

declare(strict_types=1);

use App\Modules\Assessment\Actions\ConfigureReportCard;
use App\Modules\Assessment\Actions\PublishPeriod;
use App\Modules\Assessment\Models\AssessmentComponent;
use App\Modules\Assessment\Models\AssessmentFramework;
use App\Modules\Assessment\Models\GradeBand;
use App\Modules\Assessment\Models\PeriodPublication;
use App\Modules\Assessment\Models\ReportCardSnapshot;
use App\Modules\Identity\Domain\Permission as PermissionEnum;
use App\Modules\Identity\Models\User;
use App\Support\Audit\Actor;
use Database\Factories\ReportCardConfigFactory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

/**
 * RefreshDatabase is deliberately NOT used here, and its absence is the design
 * of this file rather than an oversight.
 *
 * RefreshDatabase wraps every test in one open transaction. T17 needs a SECOND
 * MySQL SESSION to contend for a row lock with the first, and a second session
 * cannot see - let alone block on - rows another connection has not committed.
 * The identical reasoning, and the identical remedy, are already written down
 * in tests/Unit/Support/SequenceTest.php for the sequence allocator.
 *
 * So this file commits for real and truncates between tests. tests/Pest.php
 * already binds Tests\TestCase to the whole Feature suite, so nothing is
 * declared here beyond the omission of RefreshDatabase.
 */

if (! function_exists('assessmentTruncateAll')) {
    /**
     * TRUNCATE, not DELETE: `report_card_snapshots` carries a BEFORE DELETE
     * trigger that refuses row deletion (15.2 step 4 - snapshots are retained,
     * never deleted). TRUNCATE does not fire triggers, which is exactly the
     * distinction between "the product cannot delete an issued card" and "a
     * test fixture is being reset".
     */
    function assessmentTruncateAll(): void
    {
        // Every other Assessment test file uses RefreshDatabase, which holds an
        // open transaction for the duration of a test. When this file runs
        // beside them in one process, a stray transaction can still be open on
        // this connection - and TRUNCATE is DDL, so it would COMMIT that
        // transaction's rows instead of discarding them, leaving exactly the
        // half-cleaned database that produced duplicate-key failures here.
        // Roll back to a clean session first; on a clean session this is a
        // no-op.
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        if (! Schema::hasTable('report_card_snapshots')) {
            Artisan::call('migrate', ['--force' => true]);
        }

        /** @var list<string> $tables */
        $tables = array_map(
            static fn (array $row): string => (string) array_values($row)[0],
            array_map(
                static fn (object $row): array => (array) $row,
                DB::select('SHOW TABLES'),
            ),
        );

        DB::statement('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($tables as $table) {
            if ($table === 'migrations') {
                continue;
            }

            DB::table($table)->truncate();
        }

        DB::statement('SET FOREIGN_KEY_CHECKS = 1');

        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

if (! function_exists('assessmentPublisher')) {
    /**
     * A publisher holds `reports.publish`; it also needs `marks.validate`
     * because publication drives ComputePeriodResults and ComputeRanking, which
     * are the module's single implementations of stages 5 and 6 and carry their
     * own gate. That coupling is noted rather than worked around: the
     * alternative is a second aggregation path, which T23 forbids.
     */
    function assessmentPublisher(): User
    {
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionEnum::cases() as $case) {
            Permission::findOrCreate($case->value, 'web');
        }

        $user = User::factory()->create();

        foreach ([
            PermissionEnum::ReportsPublish,
            PermissionEnum::AssessmentConfigure,
            PermissionEnum::MarksValidate,
        ] as $permission) {
            $user->givePermissionTo($permission->value);
        }

        return $user->fresh() ?? $user;
    }
}

if (! function_exists('assessmentFixture')) {
    /**
     * One framework, one leaf period, N class groups, M students each, S
     * subjects, one component per subject, and a marked-and-validated grid.
     *
     * The single component is not a convenience: 5.4's `component_weights`
     * table is not yet on disk (it belongs to the marks-entry slice of this
     * phase), and RenderReportCard only derives a weight where exactly one
     * component is declared - because 100 is then the only value satisfying
     * "Sigma weight = exactly 100", not a guess.
     *
     * @param  array{groups?: int, students?: int, subjects?: int, nursery?: bool, requires_hod_validation?: bool, requires_conseil?: bool, score?: string}  $options
     * @return array{
     *     framework_id: int, period_id: int, config_id: int, config_version_id: int,
     *     class_group_ids: list<int>, allocation_ids: list<int>, component_id: int,
     *     enrollments: array<int, list<int>>, academic_year_id: int, class_level_id: int
     * }
     */
    function assessmentFixture(array $options = []): array
    {
        $groups = $options['groups'] ?? 1;
        $students = $options['students'] ?? 3;
        $subjectCount = $options['subjects'] ?? 2;
        $nursery = $options['nursery'] ?? false;

        $factory = AssessmentFramework::factory();

        if ($nursery) {
            $factory = $factory->nursery();
        }

        /** @var AssessmentFramework $framework */
        $framework = $factory->create([
            'requires_hod_validation' => $options['requires_hod_validation'] ?? false,
            'requires_conseil' => $options['requires_conseil'] ?? false,
        ]);
        $frameworkId = (int) $framework->getKey();
        $yearId = $framework->academic_year_id;

        // 3.3's worked example ladder, Family A internal /20. Seeded here and
        // nowhere else: 00-core 16 seeds nothing into the product.
        foreach ([
            ['0.000', '5.000', 'Très Faible', '0.00', false],
            ['5.000', '10.000', 'Faible', '1.00', false],
            ['10.000', '12.000', 'Passable', '2.00', true],
            ['12.000', '14.000', 'Assez Bien', '3.00', true],
            ['14.000', '16.000', 'Bien', '4.00', true],
            ['16.000', '20.000', 'Très Bien', '5.00', true],
        ] as $index => [$min, $max, $label, $point, $isPass]) {
            GradeBand::query()->create([
                'framework_id' => $frameworkId,
                'purpose' => GradeBand::PURPOSE_INTERNAL,
                'scale_basis' => GradeBand::BASIS_OUT_OF_MAX,
                'class_level_id' => null,
                'min_score' => $min,
                'max_score' => $max,
                'label' => $label,
                'label_fr' => $label,
                'mention' => $label,
                'grade_point' => $point,
                'is_pass' => $isPass,
                'order_index' => $index,
            ]);
        }

        /** @var AssessmentComponent $component */
        $component = AssessmentComponent::factory()->create([
            'framework_id' => $frameworkId,
            'code' => 'CA',
            'max_score' => '20.000',
        ]);
        $componentId = (int) $component->getKey();

        // SubjectAllocationFactory owns the "reuse or create a class level" rule
        // for this phase; calling it keeps one level shared by the allocations,
        // the class groups and the enrollments, which is what 12.6 rule 1's
        // segment lookup needs in order to find anybody at all.
        $classLevelId = Database\Factories\SubjectAllocationFactory::classLevelId();

        // A LEAF period: no children, so 10.5's min_periods_assessed rule does
        // not apply and the fixture is not silently NC.
        $periodId = (int) DB::table('assessment_periods')->insertGetId([
            'academic_year_id' => $yearId,
            'framework_id' => $frameworkId,
            'parent_id' => null,
            'type' => 'sequence',
            'code' => 'S1',
            'name' => 'Sequence 1',
            'name_fr' => 'Séquence 1',
            'order_index' => 1,
            'starts_on' => '2026-09-05',
            'ends_on' => '2026-11-15',
            'weight' => '1.0000',
            'counts_toward_parent' => true,
            'is_reporting_period' => true,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $allocationIds = [];

        for ($s = 0; $s < $subjectCount; $s++) {
            $subjectId = (int) DB::table('subjects')->insertGetId([
                'code' => 'SUB'.strtoupper(Str::random(6)),
                'name' => 'Subject '.($s + 1),
                'name_fr' => 'Matière '.($s + 1),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $allocationIds[] = (int) DB::table('subject_allocations')->insertGetId([
                'academic_year_id' => $yearId,
                'class_level_id' => $classLevelId,
                'stream_id' => 0,
                'subject_id' => $subjectId,
                'coefficient' => $s === 0 ? '4.00' : '3.00',
                'required_components' => json_encode([$componentId], JSON_THROW_ON_ERROR),
                'is_optional' => false,
                'counts_toward_average' => true,
                'is_active' => true,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $classGroupIds = [];
        $enrollments = [];
        $scores = ['16.000', '13.000', '11.000', '18.000', '9.000', '14.000'];
        $n = 0;

        for ($g = 0; $g < $groups; $g++) {
            $classGroupId = (int) DB::table('class_groups')->insertGetId([
                'class_level_id' => $classLevelId,
                'academic_year_id' => $yearId,
                'name' => 'Form 1 '.chr(65 + $g),
                'capacity' => 60,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $classGroupIds[] = $classGroupId;
            $enrollments[$classGroupId] = [];

            for ($i = 0; $i < $students; $i++) {
                $studentId = (int) DB::table('students')->insertGetId([
                    'matricule' => 'TMP/'.strtoupper(Str::random(10)),
                    'matricule_is_official' => false,
                    'admission_no' => 'ADM/'.strtoupper(Str::random(10)),
                    'first_name' => 'Student',
                    'last_name' => 'G'.$g.'N'.$i,
                    'date_of_birth' => '2012-04-11',
                    'gender' => 'female',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $enrollmentId = (int) DB::table('enrollments')->insertGetId([
                    'student_id' => $studentId,
                    'academic_year_id' => $yearId,
                    'class_level_id' => $classLevelId,
                    'school_section_id' => $framework->school_section_id,
                    'status' => 'active',
                    'is_repeat' => false,
                    'enrollment_type' => 'new',
                    'enrolled_on' => '2026-09-05',
                    'boarding_status' => 'day',
                    'financial_clearance' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('enrollment_segments')->insert([
                    'enrollment_id' => $enrollmentId,
                    'class_group_id' => $classGroupId,
                    'starts_on' => '2026-09-05',
                    'ends_on' => null,
                    'reason' => 'initial',
                    'capacity_override' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($allocationIds as $allocationId) {
                    DB::table('marks')->insert([
                        'enrollment_id' => $enrollmentId,
                        'subject_allocation_id' => $allocationId,
                        'assessment_period_id' => $periodId,
                        'component_id' => $componentId,
                        'score' => $options['score'] ?? $scores[$n % count($scores)],
                        'state' => 'scored',
                        'workflow_state' => 'validated',
                        'attempt_no' => 1,
                        'version' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $n++;
                }

                $enrollments[$classGroupId][] = $enrollmentId;
            }
        }

        $configured = app(ConfigureReportCard::class)->handle(
            $frameworkId,
            'BULLETIN',
            $nursery ? ReportCardConfigFactory::nurseryPayload() : ReportCardConfigFactory::bulletinPayload(),
        );

        return [
            'framework_id' => $frameworkId,
            'period_id' => $periodId,
            'config_id' => (int) $configured['config']->getKey(),
            'config_version_id' => $configured['version_id'],
            'class_group_ids' => $classGroupIds,
            'allocation_ids' => $allocationIds,
            'component_id' => $componentId,
            'enrollments' => $enrollments,
            'academic_year_id' => $yearId,
            'class_level_id' => $classLevelId,
        ];
    }
}

beforeEach(function (): void {
    assessmentTruncateAll();
    actingAs(assessmentPublisher());
});

afterEach(function (): void {
    DB::purge('assessment_second');
});

it('publishes a class group and writes one snapshot per student in one batch', function () {
    $fx = assessmentFixture(['groups' => 1, 'students' => 3]);

    $result = app(PublishPeriod::class)->handle(
        $fx['period_id'],
        $fx['class_group_ids'],
        $fx['config_id'],
    );

    expect($result['published'])->toBe(1);
    expect($result['blocked'])->toBe(0);
    expect($result['results'][0]['outcome'])->toBe(PublishPeriod::OUTCOME_PUBLISHED);
    expect($result['results'][0]['snapshots'])->toBe(3);

    expect(ReportCardSnapshot::query()->count())->toBe(3);
    expect(ReportCardSnapshot::query()->distinct()->count('snapshot_batch_id'))->toBe(1);

    /** @var PeriodPublication $publication */
    $publication = PeriodPublication::query()->firstOrFail();
    expect($publication->status)->toBe(PeriodPublication::STATUS_PUBLISHED);
    expect($publication->report_card_config_version_id)->toBe($fx['config_version_id']);

    // 13.1: the pinned version is now referenced by an issued document and is
    // frozen, so a later edit of the configurator cannot rewrite this card.
    expect(DB::table('report_card_config_versions')->where('id', $fx['config_version_id'])->value('frozen_at'))
        ->not->toBeNull();
});

it('prints the totals row and its stated derivation (13.6)', function () {
    $fx = assessmentFixture(['groups' => 1, 'students' => 1, 'subjects' => 2, 'score' => '13.000']);

    app(PublishPeriod::class)->handle($fx['period_id'], $fx['class_group_ids'], $fx['config_id']);

    /** @var ReportCardSnapshot $snapshot */
    $snapshot = ReportCardSnapshot::query()->firstOrFail();
    $totals = $snapshot->payload['totals'];

    // Coefficients 4 and 3, both subjects at 13.00.
    expect($totals['sum_coefficient'])->toBe('7.00');
    expect($totals['sum_score_times_coef'])->toBe('91.00');
    expect($totals['derivation'])->toBe('Moyenne = 91,00 / 7,00 = 13,00');
});

it('T14: one blocked class group does not block the others, and results are reported per class group', function () {
    // Four class groups. One of them has a pending mark under a framework whose
    // policy is block_publication, so it - and ONLY it - must fail its gates.
    $fx = assessmentFixture(['groups' => 4, 'students' => 2]);

    DB::table('assessment_frameworks')
        ->where('id', $fx['framework_id'])
        ->update(['missing_component_policy' => 'block_publication']);

    $blockedGroup = $fx['class_group_ids'][2];
    $victim = $fx['enrollments'][$blockedGroup][0];

    DB::table('marks')
        ->where('enrollment_id', $victim)
        ->where('subject_allocation_id', $fx['allocation_ids'][0])
        ->update(['state' => 'pending', 'score' => null]);

    $result = app(PublishPeriod::class)->handle(
        $fx['period_id'],
        $fx['class_group_ids'],
        $fx['config_id'],
    );

    expect($result['published'])->toBe(3);
    expect($result['blocked'])->toBe(1);

    $byGroup = [];

    foreach ($result['results'] as $row) {
        $byGroup[$row['class_group_id']] = $row;
    }

    expect($byGroup[$blockedGroup]['outcome'])->toBe(PublishPeriod::OUTCOME_BLOCKED);
    expect($byGroup[$blockedGroup]['failures'])->not->toBeEmpty();
    expect($byGroup[$blockedGroup]['snapshots'])->toBe(0);

    foreach ($fx['class_group_ids'] as $classGroupId) {
        if ($classGroupId === $blockedGroup) {
            continue;
        }

        expect($byGroup[$classGroupId]['outcome'])->toBe(PublishPeriod::OUTCOME_PUBLISHED);
        expect($byGroup[$classGroupId]['snapshots'])->toBe(2);
    }

    // The three healthy class groups are published and their cards exist. This
    // is the whole of C8: in v1 all four would be waiting on one teacher.
    expect(ReportCardSnapshot::query()->count())->toBe(6);
    expect(PeriodPublication::query()->where('status', PeriodPublication::STATUS_PUBLISHED)->count())->toBe(3);

    // The blocked group keeps a machine-readable record of WHY, all failures
    // together rather than one at a time (13.2).
    $blocking = PeriodPublication::query()
        ->where('class_group_id', $blockedGroup)
        ->value('blocking_report');

    expect($blocking)->not->toBeNull();
});

it('reports every failing gate together rather than one at a time', function () {
    $fx = assessmentFixture(['groups' => 1, 'students' => 2, 'requires_hod_validation' => true, 'requires_conseil' => true]);

    DB::table('marks')->update(['workflow_state' => 'draft']);

    $result = app(PublishPeriod::class)->handle($fx['period_id'], $fx['class_group_ids'], $fx['config_id']);

    expect($result['results'][0]['outcome'])->toBe(PublishPeriod::OUTCOME_BLOCKED);

    $failures = implode(' | ', $result['results'][0]['failures']);

    expect($failures)->toContain('not yet validated');
    expect($failures)->toContain('conseil de classe');
    expect(ReportCardSnapshot::query()->count())->toBe(0);
});

it('T17: two concurrent publishes leave exactly one snapshot batch', function () {
    // Genuine concurrency, two real MySQL sessions.
    //
    // Session A opens a transaction and takes the publication row lock through
    // PublishPeriod's OWN `lockPublication()` - the same SELECT ... FOR UPDATE
    // the Action issues as its first statement, so A is in precisely the state
    // a mid-flight publisher is in, with real uncommitted state on a real
    // connection.
    //
    // Session B then runs the COMPLETE PublishPeriod::handle(). With a one
    // second innodb_lock_wait_timeout it must BLOCK and fail rather than read
    // the row A is holding: a publisher that did not take the lock would sail
    // past and write a second batch. When A commits and B runs again, B
    // publishes; A's own retry then finds the work done and writes nothing.
    //
    // The assertion at the end is T17 verbatim: one batch id, one snapshot per
    // student, no duplicates.
    $fx = assessmentFixture(['groups' => 1, 'students' => 3]);
    $classGroupId = $fx['class_group_ids'][0];

    config([
        'database.connections.assessment_second' => config('database.connections.'.config('database.default')),
    ]);

    $default = (string) config('database.default');

    DB::connection('assessment_second')->statement('SET SESSION innodb_lock_wait_timeout = 1');

    DB::beginTransaction();

    try {
        app(PublishPeriod::class)->lockPublication($fx['period_id'], $classGroupId, Actor::system());

        DB::setDefaultConnection('assessment_second');

        $contended = app(PublishPeriod::class)->handle($fx['period_id'], [$classGroupId], $fx['config_id']);

        DB::setDefaultConnection($default);

        expect($contended['results'][0]['outcome'])->toBe(PublishPeriod::OUTCOME_FAILED);
        expect($contended['results'][0]['failures'][0])->toContain('Lock wait timeout');
    } finally {
        DB::setDefaultConnection($default);
        DB::commit();
    }

    // A has released the row. B publishes for real, on its own connection.
    DB::setDefaultConnection('assessment_second');

    try {
        $winner = app(PublishPeriod::class)->handle($fx['period_id'], [$classGroupId], $fx['config_id']);
    } finally {
        DB::setDefaultConnection($default);
    }

    expect($winner['results'][0]['outcome'])->toBe(PublishPeriod::OUTCOME_PUBLISHED);

    // A retries, on the original connection, unaware B finished.
    $loser = app(PublishPeriod::class)->handle($fx['period_id'], [$classGroupId], $fx['config_id']);

    expect($loser['results'][0]['outcome'])->toBe(PublishPeriod::OUTCOME_ALREADY_PUBLISHED);
    expect($loser['results'][0]['snapshots'])->toBe(0);

    expect(ReportCardSnapshot::query()->count())->toBe(3);
    expect(ReportCardSnapshot::query()->distinct()->count('snapshot_batch_id'))->toBe(1);
    expect(ReportCardSnapshot::query()->distinct()->count('enrollment_id'))->toBe(3);
});

it('un-publishes with a reason and retains every snapshot', function () {
    $fx = assessmentFixture(['groups' => 1, 'students' => 2]);

    app(PublishPeriod::class)->handle($fx['period_id'], $fx['class_group_ids'], $fx['config_id']);

    expect(ReportCardSnapshot::query()->count())->toBe(2);

    app(PublishPeriod::class)->unpublish(
        $fx['period_id'],
        $fx['class_group_ids'][0],
        'Coefficients for Physique were configured wrongly for the whole level.',
    );

    /** @var PeriodPublication $publication */
    $publication = PeriodPublication::query()->firstOrFail();

    expect($publication->status)->toBe(PeriodPublication::STATUS_UNPUBLISHED);
    expect($publication->isIssuable())->toBeFalse();
    expect($publication->unpublish_reason)->toContain('Physique');

    // 13.2: "Snapshots are retained, never deleted; the card is simply no
    // longer issuable."
    expect(ReportCardSnapshot::query()->count())->toBe(2);
});

it('refuses to un-publish without a reason', function () {
    $fx = assessmentFixture(['groups' => 1, 'students' => 1]);

    app(PublishPeriod::class)->handle($fx['period_id'], $fx['class_group_ids'], $fx['config_id']);

    app(PublishPeriod::class)->unpublish($fx['period_id'], $fx['class_group_ids'][0], '   ');
})->throws(Illuminate\Validation\ValidationException::class);
