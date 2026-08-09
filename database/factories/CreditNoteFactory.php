<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Fees\Domain\CreditNoteReasonType;
use App\Modules\Fees\Domain\CreditNoteSettlementMode;
use App\Modules\Fees\Domain\CreditNoteStatus;
use App\Modules\Fees\Models\CreditNote;
use App\Modules\Fees\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * DRAFT by default; `credit_note_no` belongs to IssueCreditNote's sequence
 * allocation, never to a factory (docs/specs/04-fees.md §9/§14).
 *
 * @extends Factory<CreditNote>
 */
class CreditNoteFactory extends Factory
{
    /** @var class-string<CreditNote> */
    protected $model = CreditNote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'credit_note_no' => null,
            'invoice_id' => Invoice::factory(),
            'enrollment_id' => function (array $attributes): int {
                /** @var Invoice $invoice */
                $invoice = Invoice::query()->findOrFail($attributes['invoice_id']);

                return $invoice->enrollment_id;
            },
            'student_id' => function (array $attributes): int {
                /** @var Invoice $invoice */
                $invoice = Invoice::query()->findOrFail($attributes['invoice_id']);

                return $invoice->student_id;
            },
            'academic_year_id' => function (array $attributes): int {
                /** @var Invoice $invoice */
                $invoice = Invoice::query()->findOrFail($attributes['invoice_id']);

                return $invoice->academic_year_id;
            },
            'fiscal_year_id' => function (array $attributes): int {
                /** @var Invoice $invoice */
                $invoice = Invoice::query()->findOrFail($attributes['invoice_id']);

                return $invoice->fiscal_year_id;
            },
            'issue_date' => '2026-11-01',
            'reason_type' => CreditNoteReasonType::PriceCorrection,
            'reason_note' => 'Over-invoiced; facture d\'avoir per OHADA.',
            'status' => CreditNoteStatus::Draft,
            'settlement_mode' => CreditNoteSettlementMode::ApplyToAccount,
        ];
    }
}
