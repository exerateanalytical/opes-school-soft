<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Assessment\Domain\FrameworkFamily;
use App\Modules\Assessment\Domain\MissingComponentPolicy;
use App\Modules\Assessment\Models\AssessmentFramework;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prerequisite academic_years / school_sections rows are inserted with raw,
 * schema-filtered DB writes rather than the owning modules' factories - the
 * same discipline SubjectAllocationFactory uses, and for the same reason: this
 * factory must not depend on code being written concurrently by other authors.
 *
 * The default is a Family A framework: MINESEC Francophone secondary, /20,
 * pass at 10. Family B is deliberately NOT the default - its scale is 00-core
 * blocking gate 5, and nothing about Family B may be seeded.
 *
 * @extends Factory<AssessmentFramework>
 */
final class AssessmentFrameworkFactory extends Factory
{
    protected $model = AssessmentFramework::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_section_id' => fn (): int => self::schoolSectionId(),
            'academic_year_id' => fn (): int => SubjectAllocationFactory::academicYearId(),
            'code' => strtoupper(uniqid('FW')),
            'name' => 'MINESEC Francophone Secondary',
            'name_fr' => 'Secondaire francophone MINESEC',
            'family' => FrameworkFamily::A,
            'assessment_mode' => AssessmentFramework::MODE_NUMERIC,
            'max_score' => '20.000',
            'pass_score' => '10.000',
            'score_precision' => 2,
            'uses_coefficients' => true,
            'uses_rank' => true,
            'rank_scope' => 'class_group',
            'rank_cohort_rule' => 'same_stream',
            'annual_composition' => 'mean_of_leaf_periods',
            'requires_conseil' => false,
            'requires_hod_validation' => true,
            // Mandatory for the MINESEC families (01-assessment 14).
            'requires_per_lesson_attendance' => true,
            'missing_component_policy' => MissingComponentPolicy::Redistribute,
            'min_periods_assessed' => 1,
            'gpa_scale_id' => null,
            'is_default' => false,
            'is_active' => true,
        ];
    }

    /** Family F: maternelle, competency only - no marks, no coefficients, no rank. */
    public function nursery(): self
    {
        return $this->state(fn (): array => [
            'family' => FrameworkFamily::F,
            'assessment_mode' => AssessmentFramework::MODE_COMPETENCY,
            'uses_coefficients' => false,
            'uses_rank' => false,
            'requires_per_lesson_attendance' => false,
            'name' => 'MINEDUB Nursery',
            'name_fr' => 'Maternelle MINEDUB',
        ]);
    }

    /**
     * Reuse an existing section: UNIQUE(education_level, track, sub_system)
     * forbids blind re-insertion and sub_system is a closed set, so it cannot
     * be randomised past the constraint.
     */
    public static function schoolSectionId(): int
    {
        $existing = DB::table('school_sections')->value('id');

        if (is_numeric($existing)) {
            return (int) $existing;
        }

        $columns = array_flip(Schema::getColumnListing('school_sections'));

        $row = array_intersect_key([
            'education_level' => 'secondary_1',
            'track' => 'general',
            'sub_system' => 'francophone',
            'name' => 'Secondary General',
            'name_fr' => 'Secondaire général',
            'matricule_format' => 'OPES-{Y}-{N}',
            'display_order' => 1,
            'order_index' => 1,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $columns);

        return (int) DB::table('school_sections')->insertGetId($row);
    }
}
