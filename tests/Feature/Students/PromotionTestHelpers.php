<?php

declare(strict_types=1);

// Shared fixture builders for the Phase 8 F4 promotion suite. Helper names
// carry the phase8F4 prefix and are function_exists-guarded: Pest loads every
// file in the suite into one process, and a collision with another
// workstream's helpers would be a build break.

use App\Modules\Academics\Domain\AssessmentPeriodType;
use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Academics\Models\AssessmentPeriod;
use App\Modules\Academics\Models\ClassGroup;
use App\Modules\Academics\Models\ClassLevel;
use App\Modules\Academics\Models\SchoolSection;
use App\Modules\Assessment\Models\PeriodResult;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Students\Models\Enrollment;
use App\Modules\Students\Models\EnrollmentSegment;
use App\Modules\Students\Models\PromotionCriteriaSet;
use App\Modules\Students\Models\PromotionCriterion;

if (! function_exists('phase8F4UserAs')) {
    function phase8F4UserAs(Role $role): User
    {
        (new \Database\Seeders\RolePermissionSeeder)->run();
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user->fresh() ?? $user;
    }
}

if (! function_exists('phase8F4Fixture')) {
    /**
     * The promotion stage: a current year (Sept 2026 – June 2027), its target
     * successor, one section with two ordered levels (level2 is the exam
     * class), class groups in both years, and two leaf sequence periods the
     * annual-average service composes (no framework => MINESEC
     * mean_of_leaf_periods default).
     *
     * @return array<string, mixed>
     */
    function phase8F4Fixture(): array
    {
        $year = AcademicYear::factory()->current()->create([
            'code' => '2026-2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-06-30',
        ]);

        $targetYear = AcademicYear::factory()->create([
            'code' => '2027-2028',
            'starts_on' => '2027-09-01',
            'ends_on' => '2028-06-30',
        ]);

        $section = SchoolSection::factory()->create();

        $level1 = ClassLevel::factory()->create([
            'school_section_id' => $section->getKey(),
            'order_index' => 1,
            'is_exam_class' => false,
        ]);

        $level2 = ClassLevel::factory()->create([
            'school_section_id' => $section->getKey(),
            'order_index' => 2,
            'is_exam_class' => true,
        ]);

        $group = ClassGroup::factory()->create([
            'class_level_id' => $level1->getKey(),
            'academic_year_id' => $year->getKey(),
            'stream_id' => null,
            'capacity' => 60,
        ]);

        $examGroup = ClassGroup::factory()->create([
            'class_level_id' => $level2->getKey(),
            'academic_year_id' => $year->getKey(),
            'stream_id' => null,
            'capacity' => 60,
        ]);

        $targetGroup = ClassGroup::factory()->create([
            'class_level_id' => $level2->getKey(),
            'academic_year_id' => $targetYear->getKey(),
            'stream_id' => null,
            'capacity' => 60,
        ]);

        $s1 = AssessmentPeriod::factory()->create([
            'academic_year_id' => $year->getKey(),
            'type' => AssessmentPeriodType::Sequence,
            'code' => 'S1',
            'name' => 'Sequence 1',
            'name_fr' => 'Séquence 1',
            'order_index' => 1,
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-12-15',
        ]);

        $s2 = AssessmentPeriod::factory()->create([
            'academic_year_id' => $year->getKey(),
            'type' => AssessmentPeriodType::Sequence,
            'code' => 'S2',
            'name' => 'Sequence 2',
            'name_fr' => 'Séquence 2',
            'order_index' => 2,
            'starts_on' => '2027-01-05',
            'ends_on' => '2027-03-30',
        ]);

        return [
            'year' => $year,
            'target_year' => $targetYear,
            'section' => $section,
            'level1' => $level1,
            'level2' => $level2,
            'group' => $group,
            'exam_group' => $examGroup,
            'target_group' => $targetGroup,
            's1' => $s1,
            's2' => $s2,
        ];
    }
}

if (! function_exists('phase8F4Student')) {
    /**
     * One enrolled student with an open initial segment in the given group
     * and, unless $averages is null, a PeriodResult per leaf sequence.
     *
     * @param  array<string, mixed>  $fixture
     * @param  list<string>|null  $averages  rounded general averages for S1/S2; null = never assessed
     */
    function phase8F4Student(array $fixture, ?array $averages = ['12.000', '13.000'], ?ClassGroup $group = null): Enrollment
    {
        $group ??= $fixture['group'];
        $level = (int) $group->class_level_id;

        $enrollment = Enrollment::factory()->create([
            'academic_year_id' => $fixture['year']->getKey(),
            'class_level_id' => $level,
            'school_section_id' => $fixture['section']->getKey(),
            'stream_id' => null,
            'enrolled_on' => '2026-09-01',
        ]);

        EnrollmentSegment::factory()->create([
            'enrollment_id' => $enrollment->getKey(),
            'class_group_id' => $group->getKey(),
            'starts_on' => '2026-09-01',
            'ends_on' => null,
        ]);

        if ($averages !== null) {
            foreach ([$fixture['s1'], $fixture['s2']] as $index => $period) {
                $average = $averages[$index] ?? null;

                if ($average === null) {
                    continue;
                }

                PeriodResult::factory()->create([
                    'assessment_period_id' => $period->getKey(),
                    'enrollment_id' => $enrollment->getKey(),
                    'class_group_id' => $group->getKey(),
                    'general_average' => $average,
                    'general_average_rounded' => $average,
                ]);
            }
        }

        return $enrollment;
    }
}

if (! function_exists('phase8F4CriterionResult')) {
    /**
     * The stored explanation row for one criterion type — §10.5's
     * criteria_results, fetched typed so assertions stay honest.
     *
     * @return array<string, mixed>
     */
    function phase8F4CriterionResult(\App\Modules\Students\Models\PromotionDecision $decision, string $type): array
    {
        $results = $decision->criteria_results['criteria'] ?? [];

        foreach (is_array($results) ? $results : [] as $row) {
            if (is_array($row) && ($row['type'] ?? null) === $type) {
                return $row;
            }
        }

        throw new RuntimeException("No {$type} criterion result on decision {$decision->getKey()}.");
    }
}

if (! function_exists('phase8F4CriteriaSet')) {
    /**
     * A one-criterion rulebook: blocking annual_average >= 10, the MINESEC
     * default judgement.
     *
     * @param  array<string, mixed>  $fixture
     * @param  list<array<string, mixed>>|null  $criteria  raw PromotionCriterion attribute rows
     */
    function phase8F4CriteriaSet(array $fixture, ?array $criteria = null): PromotionCriteriaSet
    {
        $set = PromotionCriteriaSet::factory()->create([
            'academic_year_id' => $fixture['year']->getKey(),
            'school_section_id' => $fixture['section']->getKey(),
            'class_level_id' => null,
        ]);

        $criteria ??= [[
            'type' => 'annual_average',
            'comparator' => 'gte',
            'threshold' => '10.000',
            'is_blocking' => true,
        ]];

        foreach ($criteria as $sequence => $attributes) {
            PromotionCriterion::factory()->create([
                'criteria_set_id' => $set->getKey(),
                'sequence' => $sequence,
                ...$attributes,
            ]);
        }

        return $set;
    }
}
