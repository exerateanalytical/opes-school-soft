<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Assessment\Models\Exam;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A scheduled sitting, docs/specs/01-assessment.md 16.1.
 *
 * Prerequisite rows are inserted with column-filtered DB::table() writes
 * rather than their owning factories, exactly as SubjectAllocationFactory
 * explains: those factories belong to other workstreams building concurrently,
 * and an added nullable column somewhere else must not turn this factory red.
 *
 * `exam_type_id` is a bare integer. The `exam_types` table (16.1) is not part
 * of this workstream's ownership and the column deliberately carries no
 * foreign key - see the migration header.
 *
 * @extends Factory<Exam>
 */
final class ExamFactory extends Factory
{
    /** @var class-string<Exam> */
    protected $model = Exam::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'exam_type_id' => 1,
            'assessment_period_id' => fn (): int => self::leafPeriodId(),
            'subject_allocation_id' => SubjectAllocationFactory::new(),
            'class_group_id' => ClassGroupFactory::new(),
            'scheduled_on' => '2027-01-20',
            'starts_at' => '08:00:00',
            'duration_minutes' => 120,
            'room_id' => null,
            'mark_scheme_id' => null,
            'max_score' => '20.000',
            'status' => Exam::STATUS_SCHEDULED,
            'created_by' => UserFactory::new(),
            'version' => 1,
        ];
    }

    /**
     * @param  numeric-string|int  $durationMinutes
     */
    public function at(string $date, string $startsAt, int|string $durationMinutes = 120): self
    {
        return $this->state(fn (): array => [
            'scheduled_on' => $date,
            'starts_at' => mb_strlen($startsAt) === 5 ? $startsAt.':00' : $startsAt,
            'duration_minutes' => (int) $durationMinutes,
        ]);
    }

    /**
     * A LEAF period (a sequence), because 6.1 invariant 3 forbids a mark - and
     * therefore an exam - under a term or a year. It is inserted flat rather
     * than through AssessmentPeriodFactory so this factory carries no
     * dependency on the Academics enums another workstream may still be
     * editing.
     */
    public static function leafPeriodId(): int
    {
        $yearId = self::academicYearId();

        return self::insertFiltered('assessment_periods', [
            'academic_year_id' => $yearId,
            'framework_id' => null,
            'parent_id' => null,
            'type' => 'sequence',
            'code' => 'SEQ'.Str::upper(Str::random(6)),
            'name' => 'Sequence',
            'name_fr' => 'Sequence',
            'order_index' => 1,
            'starts_on' => '2027-01-05',
            'ends_on' => '2027-02-06',
            'weight' => '1.0000',
            'counts_toward_parent' => 1,
            'is_reporting_period' => 0,
            'status' => 'open',
        ]);
    }

    public static function academicYearId(): int
    {
        $existing = DB::table('academic_years')->value('id');

        if (is_numeric($existing)) {
            return (int) $existing;
        }

        return self::insertFiltered('academic_years', [
            'code' => 'AY'.Str::upper(Str::random(6)),
            'name' => 'Academic Year 2026/2027',
            'name_fr' => 'Annee scolaire 2026/2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-08-31',
            'is_current' => 0,
            'status' => 'planned',
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
