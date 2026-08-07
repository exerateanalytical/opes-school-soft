<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Academics\Models\Subject;
use App\Modules\Academics\Models\SubjectAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prerequisite academic_years / class_levels rows are inserted with raw
 * DB::table() writes rather than the owning models' factories, so this
 * factory has no dependency on code written concurrently by other modules'
 * authors. The inserts are column-filtered against the live schema, so an
 * added nullable column never breaks them.
 *
 * @extends Factory<SubjectAllocation>
 */
final class SubjectAllocationFactory extends Factory
{
    protected $model = SubjectAllocation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academic_year_id' => fn (): int => self::academicYearId(),
            'class_level_id' => fn (): int => self::classLevelId(),
            'stream_id' => SubjectAllocation::STREAM_NONE,
            'subject_id' => Subject::factory(),
            'coefficient' => '2.00',
            'subject_group_id' => null,
            'required_components' => [],
            'max_score_override' => null,
            'is_optional' => false,
            'counts_toward_average' => true,
            'effective_from_period_id' => null,
            'effective_to_period_id' => null,
            'is_active' => true,
            'version' => 1,
        ];
    }

    public static function academicYearId(): int
    {
        return self::insertFiltered('academic_years', [
            'code' => uniqid('AY'),
            'name' => '2026/2027',
            'name_fr' => '2026/2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-08-31',
            // Not current: a one-current-year generated-column UNIQUE
            // (00-core 10.1 pattern) would reject a second current row.
            'is_current' => 0,
            'status' => 'planned',
        ]);
    }

    public static function classLevelId(): int
    {
        // Reuse an existing section: UNIQUE(education_level, track,
        // sub_system) forbids blind re-insertion, and sub_system is a
        // closed set so it cannot be randomised past the constraint.
        $existing = DB::table('school_sections')->value('id');

        $sectionId = is_numeric($existing) ? (int) $existing : self::insertFiltered('school_sections', [
            'education_level' => 'secondary_1',
            'track' => 'general',
            'sub_system' => 'francophone',
            'name' => 'Secondary General',
            'name_fr' => 'Secondaire général',
            'matricule_format' => 'OPES-{Y}-{N}',
            'display_order' => 1,
            'order_index' => 1,
            'is_active' => 1,
        ]);

        return self::insertFiltered('class_levels', [
            'school_section_id' => $sectionId,
            'code' => strtoupper(uniqid('CL')),
            'name' => 'Form 1',
            'name_fr' => '6e',
            'order_index' => 1,
            'is_exam_class' => 0,
            'is_active' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function insertFiltered(string $table, array $values): int
    {
        $columns = array_flip(Schema::getColumnListing($table));

        $row = array_intersect_key($values + [
            'created_at' => now(),
            'updated_at' => now(),
        ], $columns);

        return (int) DB::table($table)->insertGetId($row);
    }
}
