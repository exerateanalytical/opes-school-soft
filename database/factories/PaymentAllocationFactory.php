<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Fees\Models\Payment;
use App\Modules\Fees\Models\PaymentAllocation;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A live (unreversed) allocation. Invoice ids are left null because the
 * invoice tables belong to a parallel Phase 6 work package; tests that
 * need line-targeted allocations set them explicitly.
 *
 * @extends Factory<PaymentAllocation>
 */
class PaymentAllocationFactory extends Factory
{
    /** @var class-string<PaymentAllocation> */
    protected $model = PaymentAllocation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'invoice_id' => null,
            'invoice_line_id' => null,
            'amount' => fake()->numberBetween(1, 40) * 5_000,
            'allocated_at' => now(),
            'allocated_by' => User::factory(),
            'reversed_at' => null,
            'reversed_by' => null,
            'reversal_reason' => null,
        ];
    }
}
