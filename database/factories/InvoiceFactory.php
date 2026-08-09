<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Fees\Domain\InvoiceStatus;
use App\Modules\Fees\Domain\InvoiceType;
use App\Modules\Fees\Models\Invoice;
use App\Modules\Students\Models\Enrollment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A DRAFT invoice by default - `invoice_no` is NULL until IssueInvoice
 * allocates one from the sequence (docs/specs/04-fees.md §4.1 step 6); a
 * factory must never fabricate document numbers.
 *
 * Fee-structure/plan references stay NULL here: those tables belong to the
 * fee-configuration workstream and a factory row must not depend on them.
 *
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /** @var class-string<Invoice> */
    protected $model = Invoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_no' => null,
            'enrollment_id' => Enrollment::factory(),
            'student_id' => function (array $attributes): int {
                /** @var Enrollment $enrollment */
                $enrollment = Enrollment::query()->findOrFail($attributes['enrollment_id']);

                return $enrollment->student_id;
            },
            'academic_year_id' => function (array $attributes): int {
                /** @var Enrollment $enrollment */
                $enrollment = Enrollment::query()->findOrFail($attributes['enrollment_id']);

                return $enrollment->academic_year_id;
            },
            'fiscal_year_id' => FiscalYearFactory::new()->open(),
            'fee_structure_id' => null,
            'term_id' => null,
            'installment_plan_id' => null,
            'type' => InvoiceType::Standard,
            'issue_date' => '2026-09-08',
            'due_date' => '2026-10-08',
            'currency' => 'XAF',
            'status' => InvoiceStatus::Draft,
            'is_migration' => false,
            'version' => 0,
        ];
    }

    public function issued(string $invoiceNo): static
    {
        return $this->state(fn (): array => [
            'invoice_no' => $invoiceNo,
            'status' => InvoiceStatus::Issued,
        ]);
    }
}
