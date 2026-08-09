<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\SavePostingRule;
use App\Modules\Accounting\Domain\AccountSource;
use App\Modules\Accounting\Domain\LineSign;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Fees\Actions\RecordPayment;
use App\Modules\Fees\Actions\ReissueReceipt;
use App\Modules\Fees\Actions\VoidPayment;
use App\Modules\Fees\Domain\FeeBearer;
use App\Modules\Fees\Domain\PaymentMethod;
use App\Modules\Fees\Domain\PaymentVoidReason;
use App\Modules\Fees\Models\Payment;
use App\Modules\Fees\Models\Receipt;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Students\Models\Student;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/../Accounting/AccountingTestHelpers.php';

uses(RefreshDatabase::class);

if (! function_exists('paymentUserAs')) {
    /** A logged-in user holding the union of the given roles' permissions. */
    function paymentUserAs(Role ...$roles): User
    {
        (new Database\Seeders\RolePermissionSeeder())->run();
        $user = User::factory()->create();

        foreach ($roles as $role) {
            $user->assignRole($role->value);
        }

        $user = $user->fresh() ?? $user;
        actingAs($user);

        return $user;
    }
}

if (! function_exists('feesSaveMomoPaymentRule')) {
    /** The §15.6 MoMo rule: Dr 552 net, Dr 6317 commission, Cr 4111 gross with student partner. */
    function feesSaveMomoPaymentRule(User $accountant): void
    {
        /** @var Journal $journal */
        $journal = Journal::query()->where('code', 'MM')->firstOrFail();

        app(SavePostingRule::class)->handle([
            'code' => 'f3_momo_fee_payment',
            'event' => PostingEvent::FeePaymentReceived->value,
            'journal_id' => $journal->id,
            'label_expression' => 'Encaissement MoMo réf. {payment.reference}',
            'condition_expression' => null,
            'priority' => 100,
            'is_active' => true,
            'effective_from' => '2030-01-01',
            'effective_to' => null,
        ], [
            [
                'sequence' => 1,
                'account_source' => AccountSource::Literal,
                'account_code' => '552',
                'sign' => LineSign::Debit,
                'amount_expression' => 'payment.amount - payment.commission',
                'label_expression' => 'Encaissement MoMo réf. {payment.reference}',
            ],
            [
                'sequence' => 2,
                'account_source' => AccountSource::Literal,
                'account_code' => '6317',
                'sign' => LineSign::Debit,
                'amount_expression' => 'payment.commission',
                'label_expression' => 'Commission opérateur MoMo',
            ],
            [
                'sequence' => 3,
                'account_source' => AccountSource::Literal,
                'account_code' => '4111',
                'sign' => LineSign::Credit,
                'amount_expression' => 'payment.amount',
                'is_balancing' => true,
                'partner_source' => 'payment.partner',
                'label_expression' => '{payment.partner_label} — {payment.reference}',
            ],
        ], $accountant->toAuditActor());
    }
}

if (! function_exists('feesRecordCash')) {
    /**
     * @param  array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}  $cal
     */
    function feesRecordCash(Student $student, array $cal, User $cashier): Payment
    {
        return app(RecordPayment::class)->handle(
            studentId: $student->id,
            academicYearId: $cal['academic_year_id'],
            fiscalYearId: $cal['fiscal_year_id'],
            method: PaymentMethod::MobileMoney,
            amount: Money::of(120_000),
            payerName: 'Doe, Jane (mother)',
            valueDate: '2031-03-15',
            actor: $cashier->toAuditActor(),
            feeAmount: Money::of(1_200),
            feeBearer: FeeBearer::School,
            reference: 'MM-77001',
        );
    }
}

it('issues the original receipt (copy 1) with the payment, carrying the allocated receipt_no', function (): void {
    $cashier = paymentUserAs(Role::Bursar, Role::Accountant);
    feesSaveMomoPaymentRule($cashier);
    $cal = ledgerCalendar('2031-03-15');

    /** @var Student $student */
    $student = Student::factory()->create();
    $payment = feesRecordCash($student, $cal, $cashier);

    $receipts = $payment->receipts()->get();

    expect($receipts)->toHaveCount(1)
        ->and($receipts[0]?->copy_no)->toBe(1)
        ->and($receipts[0]?->receipt_no)->toBe($payment->receipt_no)
        ->and($receipts[0]?->is_voided)->toBeFalse()
        ->and($receipts[0]?->reissue_reason)->toBeNull();
});

it('reissues as the next copy number with a mandatory reason - the original row is untouched', function (): void {
    $cashier = paymentUserAs(Role::Bursar, Role::Accountant);
    feesSaveMomoPaymentRule($cashier);
    $cal = ledgerCalendar('2031-03-15');

    /** @var Student $student */
    $student = Student::factory()->create();
    $payment = feesRecordCash($student, $cal, $cashier);

    $duplicate = app(ReissueReceipt::class)->handle(
        $payment->id,
        'Parent lost the original paper receipt.',
        $cashier->toAuditActor(),
    );

    expect($duplicate->copy_no)->toBe(2)
        ->and($duplicate->receipt_no)->toBe($payment->receipt_no)
        ->and($duplicate->reissue_reason)->toBe('Parent lost the original paper receipt.')
        ->and($payment->receipts()->count())->toBe(2);

    /** @var Receipt $original */
    $original = $payment->receipts()->where('copy_no', 1)->firstOrFail();
    expect($original->reissue_reason)->toBeNull();

    $third = app(ReissueReceipt::class)->handle(
        $payment->id,
        'Second household copy requested by the father.',
        $cashier->toAuditActor(),
    );
    expect($third->copy_no)->toBe(3);
});

it('refuses a reissue without a reason', function (): void {
    $cashier = paymentUserAs(Role::Bursar, Role::Accountant);
    feesSaveMomoPaymentRule($cashier);
    $cal = ledgerCalendar('2031-03-15');

    /** @var Student $student */
    $student = Student::factory()->create();
    $payment = feesRecordCash($student, $cal, $cashier);

    app(ReissueReceipt::class)->handle($payment->id, '   ', $cashier->toAuditActor());
})->throws(ValidationException::class);

it('voiding the payment flags every issued copy and blocks any further reissue (§11.5 step 7)', function (): void {
    $cashier = paymentUserAs(Role::Bursar, Role::Accountant);
    feesSaveMomoPaymentRule($cashier);
    $cal = ledgerCalendar('2031-03-15');

    /** @var Student $student */
    $student = Student::factory()->create();
    $payment = feesRecordCash($student, $cal, $cashier);

    app(ReissueReceipt::class)->handle(
        $payment->id,
        'Parent lost the original paper receipt.',
        $cashier->toAuditActor(),
    );

    $supervisor = paymentUserAs(Role::Accountant);
    app(VoidPayment::class)->handle(
        $payment->id,
        PaymentVoidReason::WrongStudent,
        'Receipt issued to the wrong student account.',
        $supervisor->toAuditActor(),
    );

    // Every printed copy is flagged - discoverable and unprintable.
    expect($payment->receipts()->where('is_voided', false)->count())->toBe(0)
        ->and($payment->receipts()->count())->toBe(2);

    app(ReissueReceipt::class)->handle(
        $payment->id,
        'Trying to reprint a voided receipt.',
        $supervisor->toAuditActor(),
    );
})->throws(DomainException::class, 'cannot be reissued');

it('a receipt issuance row is append-only and a voided receipt cannot be un-voided', function (): void {
    $cashier = paymentUserAs(Role::Bursar, Role::Accountant);
    feesSaveMomoPaymentRule($cashier);
    $cal = ledgerCalendar('2031-03-15');

    /** @var Student $student */
    $student = Student::factory()->create();
    $payment = feesRecordCash($student, $cal, $cashier);

    /** @var Receipt $receipt */
    $receipt = $payment->receipts()->firstOrFail();

    expect(fn () => $receipt->forceFill(['receipt_no' => 'RCPT/2031/999999'])->save())
        ->toThrow(RuntimeException::class, 'append-only')
        ->and(fn () => $receipt->delete())
        ->toThrow(RuntimeException::class, 'cannot be deleted');

    $receipt->refresh();
    $receipt->is_voided = true;
    $receipt->save();

    expect(fn () => $receipt->forceFill(['is_voided' => false])->save())
        ->toThrow(RuntimeException::class, 'cannot be un-voided');
});
