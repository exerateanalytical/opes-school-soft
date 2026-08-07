<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Academics\Domain\AssessmentPeriodStatus;
use App\Modules\Academics\Domain\AssessmentPeriodType;
use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Academics\Models\AssessmentPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Defaults to a year-root period spanning its academic year. Term children
 * are normally created through DefineTermStructure, which owns the
 * contiguity validation; use ->term() only for low-level persistence tests.
 *
 * @extends Factory<AssessmentPeriod>
 */
class AssessmentPeriodFactory extends Factory
{
    /** @var class-string<AssessmentPeriod> */
    protected $model = AssessmentPeriod::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academic_year_id' => AcademicYear::factory(),
            'framework_id' => null,
            'parent_id' => null,
            'type' => AssessmentPeriodType::Year,
            'code' => 'YEAR',
            'name' => 'Academic Year',
            'name_fr' => 'Année scolaire',
            'order_index' => 0,
            // Mirrors the AcademicYearFactory span; overridden by callers that
            // attach to a specific year.
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-08-31',
            'weight' => '1.0000',
            'counts_toward_parent' => true,
            'marks_entry_opens_at' => null,
            'marks_entry_closes_at' => null,
            'is_reporting_period' => false,
            'status' => AssessmentPeriodStatus::Planned,
        ];
    }

    public function forYear(AcademicYear $year): static
    {
        return $this->state(fn (array $attributes): array => [
            'academic_year_id' => $year->id,
            'starts_on' => $year->starts_on->toDateString(),
            'ends_on' => $year->ends_on->toDateString(),
        ]);
    }

    public function term(int $index, string $startsOn, string $endsOn): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => AssessmentPeriodType::Term,
            'code' => 'T'.$index,
            'name' => 'Term '.$index,
            'name_fr' => 'Trimestre '.$index,
            'order_index' => $index,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'is_reporting_period' => true,
        ]);
    }
}
