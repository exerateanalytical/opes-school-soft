<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Fees\Domain\ClearingState;
use App\Modules\Fees\Domain\FeeBearer;
use App\Modules\Fees\Domain\PaymentMethod;
use App\Modules\Fees\Models\Payment;
use App\Modules\Identity\Models\User;
use App\Modules\Students\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A factory payment is a CASH payment fully unallocated (the C10 baseline:
 * money against the student account, no invoice yet). Real receipt numbers
 * come from RecordPayment's SequenceAllocator path; the factory fakes a
 * unique one because a factory row never went through the Action.
 *
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /** @var class-string<Payment> */
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = fake()->numberBetween(1, 70) * 5_000;

        return [
            'receipt_no' => sprintf('RCPT/2031/%06d', fake()->unique()->numberBetween(1, 999_999)),
            'student_id' => Student::factory(),
            'enrollment_id' => null,
            'academic_year_id' => AcademicYear::factory(),
            'fiscal_year_id' => FiscalYear::factory(),
            'payment_method' => PaymentMethod::Cash,
            'amount' => $amount,
            'fee_amount' => 0,
            'fee_bearer' => FeeBearer::None,
            'reference' => null,
            'payer_name' => fake()->name(),
            'payer_phone' => null,
            'value_date' => '2031-03-15',
            'posting_date' => '2031-03-15',
            'clearing_state' => ClearingState::Cleared,
            'unallocated_amount' => $amount,
            'is_migration' => false,
            'journal_entry_id' => null,
            'received_by' => User::factory(),
        ];
    }

    public function mobileMoney(int $amount, int $fee): static
    {
        return $this->state(fn (array $attributes): array => [
            'payment_method' => PaymentMethod::MobileMoney,
            'amount' => $amount,
            'unallocated_amount' => $amount,
            'fee_amount' => $fee,
            'fee_bearer' => FeeBearer::School,
            'reference' => 'MM-'.fake()->unique()->numerify('#####'),
        ]);
    }
}
