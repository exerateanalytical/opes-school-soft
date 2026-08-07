<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Academics\Models\SchoolSection;
use App\Modules\Academics\Models\Stream;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Stream>
 */
final class StreamFactory extends Factory
{
    /** @var class-string<Stream> */
    protected $model = Stream::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = strtoupper(fake()->unique()->bothify('S##'));

        return [
            'school_section_id' => SchoolSection::factory(),
            'code' => $code,
            'name' => 'Stream '.$code,
            'name_fr' => 'Serie '.$code,
            'subject_basket' => ['MATH', 'ENG', 'FRE', 'BIO'],
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }
}
