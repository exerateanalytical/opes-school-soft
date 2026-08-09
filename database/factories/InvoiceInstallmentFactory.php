<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Fees\Models\Invoice;
use App\Modules\Fees\Models\InvoiceInstallment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * docs/specs/04-fees.md §3.3 - aging rides on THESE due dates.
 *
 * @extends Factory<InvoiceInstallment>
 */
class InvoiceInstallmentFactory extends Factory
{
    /** @var class-string<InvoiceInstallment> */
    protected $model = InvoiceInstallment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'sequence_no' => 1,
            'label' => '1st instalment',
            'label_fr' => '1ère tranche',
            'amount' => 100_000,
            'due_date' => '2026-10-08',
            'is_cancelled' => false,
        ];
    }
}
