<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Academics\Domain\EducationLevel;
use App\Modules\Academics\Domain\SubSystem;
use App\Modules\Academics\Domain\Track;
use App\Modules\Academics\Models\SchoolSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchoolSection>
 */
final class SchoolSectionFactory extends Factory
{
    /** @var class-string<SchoolSection> */
    protected $model = SchoolSection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // The (level, track, sub_system) triple is UNIQUE, so the factory
        // cycles through distinct triples rather than faking random ones and
        // colliding after a handful of creates.
        $triples = [];
        foreach (EducationLevel::cases() as $level) {
            foreach (Track::cases() as $track) {
                foreach (SubSystem::cases() as $subSystem) {
                    $triples[] = [$level, $track, $subSystem];
                }
            }
        }

        $sequence = fake()->unique()->numberBetween(0, count($triples) - 1);
        [$level, $track, $subSystem] = $triples[$sequence];

        return [
            'education_level' => $level,
            'track' => $track,
            'sub_system' => $subSystem,
            'name' => ucfirst($level->value).' '.ucfirst($track->value).' ('.ucfirst($subSystem->value).')',
            'name_fr' => 'Section '.$level->value.' '.$track->value.' '.$subSystem->value,
            'matricule_format' => 'OPS-{YY}-{SEQ:5}',
            'display_order' => 0,
            'is_active' => true,
        ];
    }
}
