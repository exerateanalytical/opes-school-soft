<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Fees\Domain\AdjustmentApplicationMethod;
use App\Modules\Fees\Domain\FeeAdjustmentReasonType;
use App\Modules\Fees\Domain\FeeAdjustmentStatus;
use App\Modules\Fees\Models\FeeAdjustment;
use App\Modules\Fees\Models\Invoice;
use App\Modules\Fees\Models\InvoiceLine;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PENDING by default - approval (and the posting) is ApproveFeeAdjustment's
 * job, with segregation of duties (docs/specs/04-fees.md §8).
 *
 * @extends Factory<FeeAdjustment>
 */
class FeeAdjustmentFactory extends Factory
{
    /** @var class-string<FeeAdjustment> */
    protected $model = FeeAdjustment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference_no' => 'ADJ/2026/'.Str::upper(Str::random(6)),
            'invoice_line_id' => InvoiceLine::factory(),
            'enrollment_id' => fn (array $attributes): int => $this->invoiceFor($attributes)->enrollment_id,
            'student_id' => fn (array $attributes): int => $this->invoiceFor($attributes)->student_id,
            'academic_year_id' => fn (array $attributes): int => $this->invoiceFor($attributes)->academic_year_id,
            'fiscal_year_id' => fn (array $attributes): int => $this->invoiceFor($attributes)->fiscal_year_id,
            'amount' => 50_000,
            'reason_type' => FeeAdjustmentReasonType::Hardship,
            'reason_note' => 'Hardship reduction granted by the bursar.',
            'adjustment_account_id' => fn (): int => $this->contraRevenueAccountId(),
            'application_method' => AdjustmentApplicationMethod::EarliestFirst,
            'effective_date' => '2026-11-01',
            'status' => FeeAdjustmentStatus::Pending,
            'granted_by' => User::factory(),
        ];
    }

    private function invoiceFor(array $attributes): Invoice
    {
        /** @var InvoiceLine $line */
        $line = InvoiceLine::query()->findOrFail($attributes['invoice_line_id']);

        /** @var Invoice $invoice */
        $invoice = Invoice::query()->findOrFail($line->invoice_id);

        return $invoice;
    }

    private function contraRevenueAccountId(): int
    {
        // 4198 RRR et autres avoirs à accorder - the verified contra-revenue
        // account (docs/specs/04-fees.md §8.1).
        $id = DB::table('chart_of_accounts')->where('code', '4198')->value('id');

        if ($id === null) {
            throw new \RuntimeException('Account 4198 is not seeded; run the chart-of-accounts migrations first.');
        }

        return (int) $id;
    }
}
