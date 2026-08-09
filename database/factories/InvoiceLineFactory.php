<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Fees\Models\Invoice;
use App\Modules\Fees\Models\InvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * Snapshot-first, like the table (docs/specs/04-fees.md §3.2): the line is
 * self-sufficient without any fee_items row, exactly as history must be.
 *
 * @extends Factory<InvoiceLine>
 */
class InvoiceLineFactory extends Factory
{
    /** @var class-string<InvoiceLine> */
    protected $model = InvoiceLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = fake()->numberBetween(10, 400) * 1000;

        return [
            'invoice_id' => Invoice::factory(),
            'line_no' => 1,
            'fee_item_id' => null,
            'description' => 'Tuition Fee',
            'description_fr' => 'Frais de scolarité',
            'fee_category_code' => 'TUITION',
            'collection_basis' => InvoiceLine::BASIS_OWN_REVENUE,
            'third_party_fund_id' => null,
            'revenue_account_id' => fn (): int => $this->revenueAccountId(),
            'recognition_method' => 'on_issue',
            'tax_code_id' => null,
            'quantity' => 1,
            'unit_amount' => $amount,
            'amount' => $amount,
            'tax_amount' => 0,
            'service_period_start' => null,
            'service_period_end' => null,
        ];
    }

    private function revenueAccountId(): int
    {
        // 706 Services vendus is seeded postable by the chart-of-accounts
        // migration; read via the query builder (00-core §6.2 rule 2).
        $id = DB::table('chart_of_accounts')->where('code', '706')->value('id');

        if ($id === null) {
            throw new \RuntimeException('Account 706 is not seeded; run the chart-of-accounts migrations first.');
        }

        return (int) $id;
    }
}
