<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Students\Domain\PromotionRunStatus;
use App\Modules\Students\Models\PromotionRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PromotionRun>
 */
class PromotionRunFactory extends Factory
{
    /** @var class-string<PromotionRun> */
    protected $model = PromotionRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academic_year_id' => AcademicYearFactory::new(),
            'class_group_id' => ClassGroupFactory::new(),
            'target_academic_year_id' => AcademicYearFactory::new(),
            'criteria_set_id' => PromotionCriteriaSetFactory::new(),
            'status' => PromotionRunStatus::Evaluating,
            'inputs_hash' => null,
            'on_indeterminate' => PromotionRun::ON_INDETERMINATE_BLOCK,
            'idempotency_key' => 'run-'.Str::lower(Str::random(24)),
        ];
    }
}
