<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Academics\Models\SchoolSection;
use App\Modules\Academics\Models\TimetablePeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Each row is a self-consistent 50-minute period; the sequence counter keeps
 * (school_section_id, sequence) unique within one factory run.
 *
 * @extends Factory<TimetablePeriod>
 */
final class TimetablePeriodFactory extends Factory
{
    /** @var class-string<TimetablePeriod> */
    protected $model = TimetablePeriod::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sequence = fake()->unique()->numberBetween(1, 200);

        // Pack periods inside a 07:00-23:00 bell day whatever the sequence.
        $startMinutes = 7 * 60 + (($sequence - 1) % 16) * 60;

        return [
            'school_section_id' => SchoolSection::factory(),
            'name' => 'Period '.$sequence,
            'name_fr' => 'Période '.$sequence,
            'sequence' => $sequence,
            'starts_at' => sprintf('%02d:%02d:00', intdiv($startMinutes, 60), $startMinutes % 60),
            'ends_at' => sprintf('%02d:%02d:00', intdiv($startMinutes + 50, 60), ($startMinutes + 50) % 60),
            'is_break' => false,
            'duration_minutes' => 50,
        ];
    }

    public function break(string $name = 'BREAK'): self
    {
        return $this->state([
            'name' => $name,
            'name_fr' => $name === 'BREAK' ? 'PAUSE' : $name,
            'is_break' => true,
            'duration_minutes' => 20,
        ]);
    }
}
