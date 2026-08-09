<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Academics\Domain\CalendarDayType;
use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Academics\Models\SchoolCalendarDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchoolCalendarDay>
 */
final class SchoolCalendarDayFactory extends Factory
{
    /** @var class-string<SchoolCalendarDay> */
    protected $model = SchoolCalendarDay::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academic_year_id' => AcademicYear::factory(),
            // Distinct dates: the (year, date, section) triple is UNIQUE.
            'date' => fake()->unique()->dateTimeBetween('2026-09-01', '2027-06-30')->format('Y-m-d'),
            'day_type' => CalendarDayType::Teaching,
            'school_section_id' => SchoolCalendarDay::SECTION_ALL,
            'label' => null,
            'label_fr' => null,
        ];
    }

    public function holiday(string $label = 'Public Holiday'): self
    {
        return $this->state([
            'day_type' => CalendarDayType::PublicHoliday,
            'label' => $label,
        ]);
    }
}
