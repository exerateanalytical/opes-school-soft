<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Fees\Models\Payment;
use App\Modules\Fees\Models\Receipt;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Receipt>
 */
class ReceiptFactory extends Factory
{
    /** @var class-string<Receipt> */
    protected $model = Receipt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'receipt_no' => sprintf('RCPT/2031/%06d', fake()->unique()->numberBetween(1, 999_999)),
            'copy_no' => 1,
            'reissue_reason' => null,
            'is_voided' => false,
            'issued_by' => User::factory(),
            'issued_at' => now(),
        ];
    }
}
