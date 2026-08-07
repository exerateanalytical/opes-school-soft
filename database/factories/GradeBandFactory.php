<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Assessment\Models\AssessmentFramework;
use App\Modules\Assessment\Models\GradeBand;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GradeBand>
 */
final class GradeBandFactory extends Factory
{
    protected $model = GradeBand::class;

    /**
     * The Family A worked example of 01-assessment 3.3, as the payload
     * ConfigureGradeBands accepts. A complete, valid /20 ladder: starts at 0,
     * contiguous, closed at 20.
     *
     * @return list<array<string, mixed>>
     */
    public static function familyAInternalLadder(): array
    {
        return [
            ['min_score' => '0.000', 'max_score' => '5.000', 'label' => 'Very Weak', 'label_fr' => 'Très Faible', 'grade_point' => '0.00', 'is_pass' => false],
            ['min_score' => '5.000', 'max_score' => '10.000', 'label' => 'Weak', 'label_fr' => 'Faible', 'grade_point' => '1.00', 'is_pass' => false],
            ['min_score' => '10.000', 'max_score' => '12.000', 'label' => 'Fair', 'label_fr' => 'Passable', 'grade_point' => '2.00', 'is_pass' => true],
            ['min_score' => '12.000', 'max_score' => '14.000', 'label' => 'Fairly Good', 'label_fr' => 'Assez Bien', 'grade_point' => '3.00', 'is_pass' => true],
            ['min_score' => '14.000', 'max_score' => '16.000', 'label' => 'Good', 'label_fr' => 'Bien', 'grade_point' => '4.00', 'is_pass' => true],
            ['min_score' => '16.000', 'max_score' => '20.000', 'label' => 'Very Good', 'label_fr' => 'Très Bien', 'grade_point' => '5.00', 'is_pass' => true],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'framework_id' => AssessmentFramework::factory(),
            'purpose' => GradeBand::PURPOSE_INTERNAL,
            'scale_basis' => GradeBand::BASIS_OUT_OF_MAX,
            'class_level_id' => null,
            'min_score' => '10.000',
            'max_score' => '12.000',
            'label' => 'Fair',
            'label_fr' => 'Passable',
            'mention' => 'Passable',
            'grade_point' => '2.00',
            'is_pass' => true,
            'colour' => null,
            'order_index' => 1,
        ];
    }
}
