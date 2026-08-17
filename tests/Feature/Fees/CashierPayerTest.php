<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\SavePostingRule;
use App\Modules\Accounting\Domain\AccountSource;
use App\Modules\Accounting\Domain\LineSign;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Fees\Livewire\Cashier;
use App\Modules\Fees\Models\Payment;
use App\Modules\Identity\Domain\Role;
use App\Modules\Students\Models\Student;
use App\Support\Clock\BusinessDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/../Accounting/AccountingTestHelpers.php';
require_once __DIR__.'/../Reporting/P13MoneyHelpers.php';

uses(RefreshDatabase::class);

/*
 * 04-fees §11 - the cashier form must be able to say WHO paid.
 *
 * The screen used to hardcode the payer to the student's own name, so a
 * guardian settling their child's fees produced a receipt naming the child.
 * These tests pin the corrected behaviour: the payer name, phone and notes
 * the cashier typed are the ones stored on the payment row the receipt is
 * printed from.
 *
 * Mobile money deliberately, not cash: §17.2 blocks cash collection until a
 * cash-desk session is open, which is a different rule under test elsewhere.
 */

/**
 * A calendar covering TODAY - collect() stamps BusinessDate::today() as the
 * value date, so the fiscal year and the posting rule must both cover it.
 *
 * @return array{academic_year_id: int, fiscal_year_id: int}
 */
function cashierPayerCalendar(): array
{
    $calendar = ledgerCalendar(BusinessDate::today());

    DB::table('academic_years')->where('id', $calendar['academic_year_id'])->update(['is_current' => true]);

    return $calendar;
}

/** Dr 552 (mobile money float) / Cr 4111, effective long before today. */
function cashierPayerMomoRule(App\Modules\Identity\Models\User $accountant): void
{
    app(SavePostingRule::class)->handle([
        'code' => 'cashier_payer_momo',
        'event' => PostingEvent::FeePaymentReceived->value,
        'journal_id' => Journal::factory()->create()->id,
        'label_expression' => 'Encaissement MoMo réf. {payment.reference}',
        'condition_expression' => null,
        'priority' => 100,
        'is_active' => true,
        'effective_from' => Carbon::parse(BusinessDate::today())->subYears(5)->toDateString(),
        'effective_to' => null,
    ], [
        [
            'sequence' => 1,
            'account_source' => AccountSource::Literal,
            'account_code' => '552',
            'sign' => LineSign::Debit,
            'amount_expression' => 'payment.amount',
            'label_expression' => 'Encaissement réf. {payment.reference}',
        ],
        [
            'sequence' => 2,
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

it('records the guardian who paid, their phone and the note, not the student', function (): void {
    $cashier = p13moneyUserAs(Role::Bursar, Role::Accountant);
    cashierPayerCalendar();
    cashierPayerMomoRule($cashier);

    /** @var Student $student */
    $student = Student::factory()->create(['first_name' => 'Ayuk', 'last_name' => 'Tabi']);

    Livewire::actingAs($cashier)
        ->test(Cashier::class)
        ->call('selectStudent', $student->id)
        // The default is the student - the walk-up case…
        ->assertSet('payerName', 'Ayuk Tabi')
        // …and it is overwritable, which is the whole point.
        ->set('method', 'mobile_money')
        ->set('amount', '50000')
        ->set('reference', 'MP250816.1200.A12345')
        ->set('payerName', 'Mrs Bih Tabi (mother)')
        ->set('payerPhone', '677000111')
        ->set('notes', 'Paid at the MTN kiosk, receipt handed to the elder brother.')
        ->call('collect')
        ->assertHasNoErrors()
        ->assertSet('errorMessage', '');

    /** @var Payment $payment */
    $payment = Payment::query()->where('student_id', $student->id)->firstOrFail();

    expect($payment->payer_name)->toBe('Mrs Bih Tabi (mother)')
        ->and($payment->payer_phone)->toBe('677000111')
        ->and($payment->notes)->toBe('Paid at the MTN kiosk, receipt handed to the elder brother.')
        ->and($payment->amount)->toBe(50_000);
});

it('refuses a payment with no payer named', function (): void {
    $cashier = p13moneyUserAs(Role::Bursar, Role::Accountant);
    cashierPayerCalendar();
    cashierPayerMomoRule($cashier);

    /** @var Student $student */
    $student = Student::factory()->create();

    Livewire::actingAs($cashier)
        ->test(Cashier::class)
        ->call('selectStudent', $student->id)
        ->set('method', 'mobile_money')
        ->set('amount', '50000')
        ->set('reference', 'MP250816.1200.B99999')
        ->set('payerName', '')
        ->call('collect')
        ->assertHasErrors(['payerName']);

    expect(Payment::query()->count())->toBe(0);
});

it('stores the commission the cashier typed and who bore it', function (): void {
    $cashier = p13moneyUserAs(Role::Bursar, Role::Accountant);
    cashierPayerCalendar();
    cashierPayerMomoRule($cashier);

    /** @var Student $student */
    $student = Student::factory()->create();

    Livewire::actingAs($cashier)
        ->test(Cashier::class)
        ->call('selectStudent', $student->id)
        ->set('method', 'mobile_money')
        ->set('amount', '50000')
        ->set('reference', 'MP250816.1200.C77777')
        ->set('feeAmount', '250')
        ->set('feeBearer', 'payer')
        ->call('collect')
        ->assertHasNoErrors();

    /** @var Payment $payment */
    $payment = Payment::query()->where('student_id', $student->id)->firstOrFail();

    expect($payment->fee_amount)->toBe(250)
        ->and($payment->fee_bearer->value)->toBe('payer');
});
