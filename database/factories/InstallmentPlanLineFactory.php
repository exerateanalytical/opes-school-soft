<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Fees\Models\InstallmentPlan;
use App\Modules\Fees\Models\InstallmentPlanLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A single 100% tranche by default, so one factory line already satisfies
 * the Σ percentage_bp = 1 000 000 sum constraint (04-fees §2.6).
 *
 * @extends Factory<InstallmentPlanLine>
 */
class InstallmentPlanLineFactory extends Factory
{
    /** @var class-string<InstallmentPlanLine> */
    protected $model = InstallmentPlanLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'installment_plan_id' => InstallmentPlan::factory(),
            'sequence_no' => 1,
            'label' => '1st instalment',
            'label_fr' => '1ere tranche',
            'percentage_bp' => 1_000_000,
            'fixed_amount' => null,
            'due_date' => '2026-09-15',
            'due_offset_days' => null,
        ];
    }
}
