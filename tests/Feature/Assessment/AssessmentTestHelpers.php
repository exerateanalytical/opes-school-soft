<?php

declare(strict_types=1);

use App\Modules\Assessment\Actions\ConfigureReportCard;
use App\Modules\Assessment\Models\AssessmentComponent;
use App\Modules\Assessment\Models\AssessmentFramework;
use App\Modules\Assessment\Models\GradeBand;
use App\Modules\Identity\Domain\Permission as PermissionEnum;
use App\Modules\Identity\Models\User;
use Database\Factories\ReportCardConfigFactory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Shared because a published report card cannot be faked: ReportCardSnapshot
 * has no factory ON PURPOSE, so every test that needs an issued bulletin has
 * to run a real publication. Extracted from PublicationTest, unchanged.
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

        // Tables the MIGRATIONS THEMSELVES seed (the OHADA chart, the seeded
        // journals, the four analytic axes) must survive this reset:
        // RefreshDatabase runs migrate:fresh ONCE per process, so a truncate
        // here would leave every later suite in the run with an empty chart -
        // "Account 706 is not seeded" across the whole Fees suite was exactly
        // this. Publication never writes these tables, so skipping them keeps
        // the reset honest.
        $seededByMigrations = ['migrations', 'chart_of_accounts', 'journals', 'analytic_axes'];

        foreach ($tables as $table) {
            if (in_array($table, $seededByMigrations, true)) {
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
