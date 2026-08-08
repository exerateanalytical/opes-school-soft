<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Academics\Models\AssessmentPeriod;
use App\Modules\Assessment\Models\PeriodResult;
use App\Modules\Students\Models\Enrollment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The default state is an ASSESSED, not-yet-ranked result: ranking is a
 * separate pass (ComputeRanking), so a factory that pre-filled a rank would
 * make every ranking test assert its own fixture back.
 *
 * @extends Factory<PeriodResult>
 */
class PeriodResultFactory extends Factory
{
    /** @var class-string<PeriodResult> */
    protected $model = PeriodResult::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'assessment_period_id' => fn (): int => (int) AssessmentPeriod::factory()->create()->getKey(),
            'enrollment_id' => fn (): int => (int) Enrollment::factory()->create()->getKey(),
            'class_group_id' => fn (): int => $this->classGroupId(),
            'framework_id' => null,
            'grade_band_id' => null,
            'cohort_key' => PeriodResult::COHORT_KEY_ALL,
            'rank_scope' => 'class_group',
            'coefficient_sum' => '18.00',
            'weighted_total' => '234.250',
            'general_average' => '13.014',
            'general_average_rounded' => '13.010',
            'is_pass' => true,
            'gpa' => null,
            'subjects_counted' => 6,
            'subject_scores' => null,
            'rank_position' => null,
            'rank_denominator' => null,
            'is_ranked' => false,
            'nc_reason' => null,
            'computed_at' => now(),
        ];
    }

    /**
     * A raw average and the value it rounds to. Passing the raw figure alone
     * would let a test assert a tie that the schema had quietly resolved;
     * 10.1's rule is that the ROUNDED value is the one rank reads, so the
     * factory makes both explicit.
     */
    public function average(string $raw, string $rounded): self
    {
        return $this->state(fn (): array => [
            'general_average' => $raw,
            'general_average_rounded' => $rounded,
        ]);
    }

    /**
     * 10.2 / C3: Sigma-coef = 0. Every dependent column goes NULL with it -
     * the CHECK constraints on the table reject any other combination, so this
     * state is the only representable shape of "not assessed".
     */
    public function unassessed(): self
    {
        return $this->state(fn (): array => [
            'coefficient_sum' => '0.00',
            'weighted_total' => null,
            'general_average' => null,
            'general_average_rounded' => null,
            'is_pass' => null,
            'gpa' => null,
            'grade_band_id' => null,
            'subjects_counted' => 0,
            'is_ranked' => false,
            'rank_position' => null,
            'rank_denominator' => null,
            'nc_reason' => PeriodResult::NC_NULL_AVERAGE,
        ]);
    }

    /**
     * 10.5: NC for a reason OTHER than a null average - a November arrival who
     * did not sit Sequence 1 still HAS marks and an average; what they do not
     * have is a comparable one.
     */
    public function nonClasse(string $reason = PeriodResult::NC_NOT_YET_ASSESSABLE): self
    {
        return $this->state(fn (): array => [
            'is_ranked' => false,
            'rank_position' => null,
            'rank_denominator' => null,
            'nc_reason' => $reason,
        ]);
    }

    public function inCohort(string $cohortKey): self
    {
        return $this->state(fn (): array => ['cohort_key' => $cohortKey]);
    }

    /**
     * Prerequisite reference rows go in through the query builder rather than
     * the Academics factories: those belong to another workstream, and this
     * factory must not depend on their code to stay green.
     */
    public static function referenceClassGroupId(): int
    {
        return (new self)->classGroupId();
    }

    private function classGroupId(): int
    {
        $existing = DB::table('class_groups')->value('id');

        if (is_numeric($existing)) {
            return (int) $existing;
        }

        // Reused rather than blindly inserted: school_sections carries
        // UNIQUE(education_level, track, sub_system) and sub_system is a closed
        // set, so a second insert cannot be randomised past the constraint.
        $sectionId = DB::table('school_sections')->value('id');

        $sectionId = is_numeric($sectionId) ? (int) $sectionId : DB::table('school_sections')->insertGetId([
            'education_level' => 'secondary_1',
            'track' => 'general',
            'sub_system' => 'anglophone',
            'name' => 'Section '.Str::upper(Str::random(6)),
            'name_fr' => 'Section '.Str::upper(Str::random(6)),
            'matricule_format' => 'OS-{YY}-{NNNN}',
            'display_order' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $levelId = DB::table('class_levels')->insertGetId([
            'school_section_id' => $sectionId,
            'code' => 'F'.Str::upper(Str::random(4)),
            'name' => 'Form 1',
            'name_fr' => 'Sixieme',
            'order_index' => 1,
            'is_exam_class' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $yearId = DB::table('academic_years')->value('id');

        if (! is_numeric($yearId)) {
            $yearId = DB::table('academic_years')->insertGetId([
                'code' => '2026-2027-'.Str::lower(Str::random(6)),
                'name' => 'Academic Year 2026/2027',
                'starts_on' => '2026-09-01',
                'ends_on' => '2027-07-31',
                'is_current' => false,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return DB::table('class_groups')->insertGetId([
            'class_level_id' => $levelId,
            'academic_year_id' => (int) $yearId,
            'name' => 'Group '.Str::upper(Str::random(8)),
            'capacity' => 60,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
