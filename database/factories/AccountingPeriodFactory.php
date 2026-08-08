<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Accounting\Domain\AccountingPeriodStatus;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<AccountingPeriod>
 */
class AccountingPeriodFactory extends Factory
{
    /** @var class-string<AccountingPeriod> */
    protected $model = AccountingPeriod::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $month = Carbon::createFromDate(fake()->numberBetween(2000, 2400), fake()->numberBetween(1, 12), 1);

        return [
            'fiscal_year_id' => FiscalYear::factory(),
            'period_month' => $month->toDateString(),
            'starts_on' => $month->copy()->startOfMonth()->toDateString(),
            'ends_on' => $month->copy()->endOfMonth()->toDateString(),
            'status' => AccountingPeriodStatus::Open,
            'is_quarter_end' => in_array((int) $month->format('n'), [3, 6, 9, 12], true),
            'forced_closure_due_on' => null,
        ];
    }

    public function softLocked(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => AccountingPeriodStatus::SoftLocked]);
    }

    public function hardLocked(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => AccountingPeriodStatus::HardLocked]);
    }
}
