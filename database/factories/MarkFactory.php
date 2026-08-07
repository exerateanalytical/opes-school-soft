<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Assessment\Domain\MarkState;
use App\Modules\Assessment\Domain\WorkflowState;
use App\Modules\Assessment\Models\Mark;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Prerequisite rows (framework, component, period, allocation, enrollment,
 * class group) are inserted with schema-filtered DB::table() writes rather
 * than through their owning factories: those belong to workstreams being
 * written concurrently and this factory must not depend on their code to stay
 * green. The same precedent SubjectAllocationFactory set.
 *
 * `scenario()` builds one complete, consistent marks-entry situation and hands
 * back its ids, which is what every test in tests/Feature/Assessment needs.
 *
 * @extends Factory<Mark>
 */
final class MarkFactory extends Factory
{
    /** @var class-string<Mark> */
    protected $model = Mark::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'state' => MarkState::Pending,
            'workflow_state' => WorkflowState::Draft,
            'score' => null,
            'attempt_no' => 1,
            'version' => 1,
        ];
    }

    public function scored(string $score): self
    {
        return $this->state(fn (): array => [
            'state' => MarkState::Scored,
            'score' => $score,
        ]);
    }

    public function submitted(): self
    {
        return $this->state(fn (): array => ['workflow_state' => WorkflowState::Submitted]);
    }

    /**
     * A complete marks-entry scenario: one framework, one component, one leaf
     * period with an open entry window, one allocation, one class group and
     * `$students` enrollments each holding a materialised pending Mark.
     *
     * @return array{framework: int, component: int, period: int, allocation: int, class_group: int, year: int, level: int, section: int, enrollments: list<int>, marks: list<int>}
     */
    public static function scenario(
        int $students = 3,
        // No window by default: 7.6's window is a real constraint and the
        // tests that exercise it set it explicitly, rather than every other
        // test silently depending on the wall clock.
        ?string $opensAt = null,
        ?string $closesAt = null,
        string $maxScore = '20.000',
        ?string $allocationOverride = null,
    ): array {
        $section = self::sectionId();
        $year = self::yearId();
        $level = self::levelId($section);

        $framework = self::insertFiltered('assessment_frameworks', [
            'school_section_id' => $section,
            'academic_year_id' => $year,
            'code' => 'FW'.Str::upper(Str::random(6)),
            'name' => 'MINESEC Secondary',
            'name_fr' => 'MINESEC Secondaire',
            'family' => 'A',
            'assessment_mode' => 'numeric',
            'max_score' => $maxScore,
            'pass_score' => '10.000',
            'score_precision' => 2,
            'is_default' => false,
            'is_active' => true,
        ]);

        $component = self::insertFiltered('assessment_components', [
            'framework_id' => $framework,
            'code' => 'CA'.Str::upper(Str::random(4)),
            'name' => 'Continuous assessment',
            'name_fr' => 'Controle continu',
            'max_score' => $maxScore,
            'order_index' => 1,
            'is_active' => true,
        ]);

        $period = self::insertFiltered('assessment_periods', [
            'academic_year_id' => $year,
            'framework_id' => $framework,
            'parent_id' => null,
            'type' => 'sequence',
            'code' => 'S'.Str::upper(Str::random(6)),
            'name' => 'Sequence 1',
            'name_fr' => 'Sequence 1',
            'order_index' => 1,
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-11-30',
            'weight' => '1.0000',
            'counts_toward_parent' => true,
            'marks_entry_opens_at' => $opensAt,
            'marks_entry_closes_at' => $closesAt,
            'is_reporting_period' => false,
            'status' => 'open',
        ]);

        $subject = self::insertFiltered('subjects', [
            'code' => 'SUB'.Str::upper(Str::random(5)),
            'name' => 'Mathematics',
            'name_fr' => 'Mathematiques',
            'is_active' => true,
        ]);

        $allocation = self::insertFiltered('subject_allocations', [
            'academic_year_id' => $year,
            'class_level_id' => $level,
            'stream_id' => 0,
            'subject_id' => $subject,
            'coefficient' => '4.00',
            'required_components' => json_encode([$component]),
            'max_score_override' => $allocationOverride,
            'is_optional' => false,
            'counts_toward_average' => true,
            'is_active' => true,
            'version' => 1,
        ]);

        $classGroup = self::insertFiltered('class_groups', [
            'class_level_id' => $level,
            'academic_year_id' => $year,
            'name' => 'Form 1 '.Str::upper(Str::random(3)),
            'capacity' => 80,
            'status' => 'active',
        ]);

        $enrollments = [];
        $marks = [];

        for ($i = 0; $i < $students; $i++) {
            $enrollment = self::enrollmentId($year, $level, $section);
            $enrollments[] = $enrollment;

            self::insertFiltered('enrollment_segments', [
                'enrollment_id' => $enrollment,
                'class_group_id' => $classGroup,
                'starts_on' => '2026-09-05',
                'ends_on' => null,
                'reason' => 'initial',
            ]);

            // 6.2: materialisation, so "no row" is unreachable.
            $marks[] = (int) DB::table('marks')->insertGetId([
                'enrollment_id' => $enrollment,
                'subject_allocation_id' => $allocation,
                'assessment_period_id' => $period,
                'component_id' => $component,
                'score' => null,
                'state' => MarkState::Pending->value,
                'workflow_state' => WorkflowState::Draft->value,
                'attempt_no' => 1,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [
            'framework' => $framework,
            'component' => $component,
            'period' => $period,
            'allocation' => $allocation,
            'class_group' => $classGroup,
            'year' => $year,
            'level' => $level,
            'section' => $section,
            'enrollments' => $enrollments,
            'marks' => $marks,
        ];
    }

    public static function sectionId(): int
    {
        $existing = DB::table('school_sections')->value('id');

        if (is_numeric($existing)) {
            return (int) $existing;
        }

        return self::insertFiltered('school_sections', [
            'education_level' => 'secondary_1',
            'track' => 'general',
            'sub_system' => 'anglophone',
            'name' => 'Anglophone General Secondary',
            'name_fr' => 'Secondaire general anglophone',
            'matricule_format' => 'OS-{YY}-{NNNN}',
            'display_order' => 1,
            'order_index' => 1,
            'is_active' => true,
        ]);
    }

    public static function yearId(): int
    {
        $existing = DB::table('academic_years')->value('id');

        if (is_numeric($existing)) {
            return (int) $existing;
        }

        return self::insertFiltered('academic_years', [
            'code' => '2026-2027',
            'name' => 'Academic Year 2026/2027',
            'name_fr' => 'Annee scolaire 2026/2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-08-31',
            'is_current' => false,
            'status' => 'active',
        ]);
    }

    public static function levelId(int $sectionId): int
    {
        $existing = DB::table('class_levels')->value('id');

        if (is_numeric($existing)) {
            return (int) $existing;
        }

        return self::insertFiltered('class_levels', [
            'school_section_id' => $sectionId,
            'code' => 'F'.Str::upper(Str::random(5)),
            'name' => 'Form 1',
            'name_fr' => 'Sixieme',
            'order_index' => 1,
            'is_exam_class' => false,
            'is_active' => true,
        ]);
    }

    public static function enrollmentId(int $yearId, int $levelId, int $sectionId): int
    {
        $suffix = Str::upper(Str::random(8));

        $student = self::insertFiltered('students', [
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
        ]);

        return self::insertFiltered('enrollments', [
            'student_id' => $student,
            'academic_year_id' => $yearId,
            'class_level_id' => $levelId,
            'school_section_id' => $sectionId,
            'status' => 'active',
            'is_repeat' => false,
            'enrollment_type' => 'new',
            'enrolled_on' => '2026-09-05',
            'boarding_status' => 'day',
            'financial_clearance' => false,
        ]);
    }

    /**
     * Column-filtered against the live schema, so a column added by a
     * concurrent workstream never breaks these inserts.
     *
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
