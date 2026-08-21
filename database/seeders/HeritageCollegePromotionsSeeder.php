<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Models\User;
use App\Modules\Students\Actions\EnrollStudent;
use App\Modules\Students\Actions\EvaluatePromotionRun;
use App\Modules\Students\Actions\OverridePromotionDecision;
use App\Modules\Students\Domain\EnrollmentType;
use App\Modules\Students\Domain\PromotionOutcome;
use App\Modules\Students\Models\PromotionDecision;
use App\Modules\Students\Models\PromotionRun;
use App\Support\Audit\Actor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Builds previous-year (2025-2026) academic history for a subset of Heritage
 * College's current (2026-2027) students, then runs the REAL promotion
 * engine (EvaluatePromotionRun / OverridePromotionDecision) against that
 * history so promotion_decisions has realistic status variety to show.
 *
 * Idempotent: every insertion point checks for an existing row first, so
 * re-running converges rather than duplicating.
 */
final class HeritageCollegePromotionsSeeder extends Seeder
{
    private int $currentYearId;

    private int $previousYearId;

    private int $sectionId;

    public function run(): void
    {
        $admin = User::query()->where('email', 'demo.admin@opeschool.test')->firstOrFail();
        Auth::login($admin);
        $actor = $admin->toAuditActor();

        $this->currentYearId = (int) DB::table('academic_years')->where('code', '2026-2027')->value('id');
        $this->previousYearId = (int) DB::table('academic_years')->where('code', '2025-2026')->value('id');

        $levels = DB::table('class_levels')->orderBy('order_index')->get(['id', 'name', 'order_index', 'is_exam_class', 'school_section_id']);
        $this->sectionId = (int) $levels->first()->school_section_id;

        $levelById = $levels->keyBy('id');
        $levelBelow = []; // current_level_id => previous_level_id
        $orderToId = $levels->pluck('id', 'order_index');

        foreach ($levels as $level) {
            if ($level->order_index > 1) {
                $levelBelow[(int) $level->id] = (int) $orderToId[$level->order_index - 1];
            }
        }

        $staffIds = DB::table('staff_members')->pluck('id')->all();
        if ($staffIds === []) {
            $staffIds = [null];
        }

        // ---- 1. Previous-year class groups (one "A" stream per level that
        // has a level below it) ------------------------------------------------
        $prevGroupIdByLevel = $this->createPreviousYearClassGroups($levelById, $staffIds);

        // ---- 2. Pick a subset of current students and enrol them into last
        // year's class group one level below their current one ---------------
        $current = DB::table('enrollments as e')
            ->join('students as s', 's.id', '=', 'e.student_id')
            ->where('e.academic_year_id', $this->currentYearId)
            ->whereIn('e.status', ['active', 'pending', 'suspended', 'completed'])
            ->orderBy('e.id')
            ->select('e.id as enrollment_id', 'e.student_id', 'e.class_level_id', 'e.enrolled_on')
            ->get();

        // Deterministic pseudo-randomness so re-runs are stable.
        $rand = static function (int $seed, int $mod): int {
            return $seed === 0 ? 0 : (($seed * 2654435761) % 1000003) % $mod;
        };

        $historyEnrollmentIds = []; // student_id => previous-year enrollment id
        $skippedForm1 = 0;
        $created = 0;

        foreach ($current as $index => $row) {
            $currentLevelId = (int) $row->class_level_id;

            if (! isset($levelBelow[$currentLevelId])) {
                // Form 1 (or the section's entry level): no level below it,
                // so these are treated as fresh admissions - no prior history.
                $skippedForm1++;

                continue;
            }

            // A meaningful subset, not all 933: ~60% of eligible students.
            if ($rand((int) $row->student_id, 100) >= 60) {
                continue;
            }

            $prevLevelId = $levelBelow[$currentLevelId];
            $groupId = $prevGroupIdByLevel[$prevLevelId] ?? null;

            if ($groupId === null) {
                continue;
            }

            $existingPrevEnrollmentId = DB::table('enrollments')
                ->where('student_id', $row->student_id)
                ->where('academic_year_id', $this->previousYearId)
                ->value('id');

            if (is_numeric($existingPrevEnrollmentId)) {
                $historyEnrollmentIds[(int) $row->student_id] = (int) $existingPrevEnrollmentId;

                continue;
            }

            try {
                $enrollment = app(EnrollStudent::class)->handle(
                    studentId: (int) $row->student_id,
                    academicYearId: $this->previousYearId,
                    classGroupId: $groupId,
                    enrolledOn: '2025-09-08',
                    enrollmentType: EnrollmentType::New,
                    isRepeat: false,
                    capacityOverride: true,
                );
            } catch (\Throwable $e) {
                // Skip students the real Action refuses (e.g. capacity edge
                // cases) rather than fake around the workflow's own guard.
                continue;
            }

            $historyEnrollmentIds[(int) $row->student_id] = (int) $enrollment->id;
            $created++;
        }

        $this->command?->info("Previous-year (2025-2026) enrollments: {$created} created, ".count($historyEnrollmentIds).' total with history, '.$skippedForm1.' current Form-1 students skipped (new admissions).');

        // ---- 3. A minimal, ADVISORY (non-blocking) criteria set per section,
        // scoped to the previous academic year - lets EvaluatePromotionRun run
        // for real without depending on exam-result seeding this session does
        // not own (attendance/exams is a separate concurrent agent). ----------
        $criteriaSetId = $this->createCriteriaSet();

        // ---- 4. Evaluate a REAL PromotionRun per previous-year class group
        // that has a roster, through the real Action, then diversify outcomes
        // via OverridePromotionDecision (also the real Action). ---------------
        $totalDecisions = 0;
        $runsEvaluated = 0;
        $outcomeCounts = [];

        foreach ($prevGroupIdByLevel as $prevLevelId => $groupId) {
            $rosterCount = DB::table('enrollments as e')
                ->join('enrollment_segments as seg', 'seg.enrollment_id', '=', 'e.id')
                ->where('e.academic_year_id', $this->previousYearId)
                ->where('seg.class_group_id', $groupId)
                ->whereIn('e.status', ['pending', 'active', 'suspended'])
                ->count();

            if ($rosterCount === 0) {
                continue;
            }

            try {
                $run = app(EvaluatePromotionRun::class)->handle(
                    classGroupId: $groupId,
                    criteriaSetId: $criteriaSetId,
                    targetAcademicYearId: $this->currentYearId,
                    onIndeterminate: PromotionRun::ON_INDETERMINATE_MANUAL_REVIEW,
                    actor: $actor,
                );
            } catch (\Throwable $e) {
                $this->command?->warn("EvaluatePromotionRun failed for class group {$groupId}: ".$e->getMessage());

                continue;
            }

            $runsEvaluated++;

            $decisions = PromotionDecision::query()
                ->where('promotion_run_id', $run->id)
                ->orderBy('id')
                ->get();

            $this->diversifyDecisions($run, $decisions, $actor);
        }

        $final = PromotionDecision::query()->get();
        $totalDecisions = $final->count();

        foreach ($final as $decision) {
            $key = $decision->outcome?->value ?? 'null';
            $outcomeCounts[$key] = ($outcomeCounts[$key] ?? 0) + 1;
        }

        $this->command?->info("Promotion runs evaluated: {$runsEvaluated}. promotion_decisions: {$totalDecisions}.");
        $this->command?->info('Outcome breakdown: '.json_encode($outcomeCounts));
    }

    /**
     * @param  Collection<int, object>  $levelById
     * @param  list<int|null>  $staffIds
     * @return array<int, int> class_level_id => class_group_id
     */
    private function createPreviousYearClassGroups($levelById, array $staffIds): array
    {
        $out = [];
        $cursor = 0;

        foreach ($levelById as $levelId => $level) {
            $groupName = $level->name.' A (2025-2026)';

            $id = DB::table('class_groups')
                ->where('academic_year_id', $this->previousYearId)
                ->where('class_level_id', $levelId)
                ->where('name', $groupName)
                ->value('id');

            if (! is_numeric($id)) {
                $teacherId = $staffIds[$cursor % count($staffIds)] ?? null;
                $cursor++;

                $id = DB::table('class_groups')->insertGetId([
                    'class_level_id' => $levelId,
                    'stream_id' => null,
                    'academic_year_id' => $this->previousYearId,
                    'name' => $groupName,
                    'class_teacher_staff_id' => $teacherId,
                    'room_id' => null,
                    'capacity' => 300,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $out[(int) $levelId] = (int) $id;
        }

        return $out;
    }

    private function createCriteriaSet(): int
    {
        $existing = DB::table('promotion_criteria_sets')
            ->where('academic_year_id', $this->previousYearId)
            ->where('school_section_id', $this->sectionId)
            ->where('name', 'Standard Promotion Criteria 2025-2026')
            ->value('id');

        if (is_numeric($existing)) {
            return (int) $existing;
        }

        $setId = (int) DB::table('promotion_criteria_sets')->insertGetId([
            'academic_year_id' => $this->previousYearId,
            'school_section_id' => $this->sectionId,
            'class_level_id' => null,
            'name' => 'Standard Promotion Criteria 2025-2026',
            'is_active' => true,
            'version' => 1,
            'created_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Non-blocking (advisory) criteria: real rules, but since this seeder
        // does not own exam/attendance data (a concurrent agent does), they
        // do not gate the outcome - they still run through the real
        // comparator/verdict machinery in EvaluatePromotionRun.
        DB::table('promotion_criteria')->insert([
            [
                'criteria_set_id' => $setId,
                'type' => 'annual_average',
                'comparator' => 'gte',
                'threshold' => '10.000',
                'subject_id' => null,
                'weight' => '1.00',
                'is_blocking' => false,
                'sequence' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'criteria_set_id' => $setId,
                'type' => 'attendance_rate',
                'comparator' => 'gte',
                'threshold' => '75.000',
                'subject_id' => null,
                'weight' => '1.00',
                'is_blocking' => false,
                'sequence' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        return $setId;
    }

    /**
     * @param  Collection<int, PromotionDecision>  $decisions
     */
    private function diversifyDecisions(PromotionRun $run, $decisions, Actor $actor): void
    {
        $i = 0;

        foreach ($decisions as $decision) {
            $i++;
            $bucket = $i % 20; // deterministic spread, ~5% buckets

            if ($decision->outcome === PromotionOutcome::Graduate) {
                // Graduated students are left as computed - exam-class exit.
                continue;
            }

            if ($bucket === 0) {
                // Repeating (~5%)
                app(OverridePromotionDecision::class)->handle(
                    promotionRunId: $run->id,
                    enrollmentId: $decision->enrollment_id,
                    outcome: PromotionOutcome::Repeat,
                    reason: "Did not meet the year's minimum requirements; repeating the class.",
                    actor: $actor,
                );
            } elseif ($bucket === 1) {
                // Promoted with conditions (~5%)
                app(OverridePromotionDecision::class)->handle(
                    promotionRunId: $run->id,
                    enrollmentId: $decision->enrollment_id,
                    outcome: PromotionOutcome::ConditionalPromote,
                    reason: 'Promoted on condition of remedial support in core subjects next term.',
                    actor: $actor,
                );
            } elseif ($bucket === 2) {
                // Withdrawn (~5%)
                app(OverridePromotionDecision::class)->handle(
                    promotionRunId: $run->id,
                    enrollmentId: $decision->enrollment_id,
                    outcome: PromotionOutcome::Exclude,
                    reason: 'Family withdrew the student from the school before the new year began.',
                    actor: $actor,
                );
            } elseif ($bucket === 3) {
                // Transferred (~5%) - modelled as Exclude (the schema's
                // closest outcome to "left the school"), reason states why.
                app(OverridePromotionDecision::class)->handle(
                    promotionRunId: $run->id,
                    enrollmentId: $decision->enrollment_id,
                    outcome: PromotionOutcome::Exclude,
                    reason: 'Transferred to another school ahead of the new academic year.',
                    actor: $actor,
                );
            }
            // else: left as the computed Promote (the majority case).
        }
    }
}
