<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Accounting\Domain\LetteringStatus;
use App\Modules\Accounting\Domain\PartnerType;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\Lettering;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lettering>
 */
class LetteringFactory extends Factory
{
    /** @var class-string<Lettering> */
    protected $model = Lettering::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => ChartOfAccount::factory(),
            'partner_type' => PartnerType::Student,
            'partner_id' => fake()->numberBetween(1, 999999),
            'code' => strtoupper(fake()->unique()->lexify('??')),
            'status' => LetteringStatus::Partial,
            'total_debit' => 0,
            'total_credit' => 0,
            'lettered_by' => User::factory(),
            'lettered_at' => now(),
            'unlettered_by' => null,
            'unlettered_at' => null,
            'unletter_reason' => null,
            'is_auto' => false,
        ];
    }

    public function full(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => LetteringStatus::Full]);
    }
}
