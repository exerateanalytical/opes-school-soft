<?php

declare(strict_types=1);

use App\Modules\Fees\Actions\IssueInvoice;
use App\Modules\Fees\Actions\PrintReceipt;
use App\Modules\Identity\Domain\Role;
use App\Modules\Reporting\Models\IssuedDocument;
use App\Modules\Students\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Accounting/AccountingTestHelpers.php';
require_once __DIR__.'/P13MoneyHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    p13moneyDocumentProfile();
    p13moneyConfirmedFiscalIdentity();
});

it('reprints the name recorded at issue, even after the student is later renamed', function (): void {
    $cashier = p13moneyUserAs(Role::Bursar, Role::Accountant);
    $cal = ledgerCalendar('2031-03-15');
    p13moneySaveCashPaymentRule($cashier);

    $studentId = Student::factory()->create(['first_name' => 'Carine', 'last_name' => 'Ndongo'])->id;
    $payment = p13moneyRecordCash($studentId, null, $cal, $cashier, 25_000);

    $original = app(PrintReceipt::class)->handle($payment->id);
    expect($original->html)->toContain('Carine Ndongo');

    // A legitimate later correction - e.g. a misspelled name fixed at the
    // front desk - must not break the ability to reprint what was already
    // issued.
    DB::table('students')->where('id', $studentId)->update(['first_name' => 'Karine']);

    $reprint = app(PrintReceipt::class)->handle($payment->id);
    expect($reprint->html)->toContain('Carine Ndongo');
    expect($reprint->html)->not->toContain('Karine Ndongo');
    expect($reprint->isDuplicate)->toBeTrue();
});

it('reprints the invoice line frozen at issue, even after the payment is later reallocated', function (): void {
    $cashier = p13moneyUserAs(Role::Bursar, Role::Accountant);
    $cal = ledgerCalendar('2031-03-15');
    p13moneySaveInvoiceIssuedRule($cashier);
    p13moneySaveCashPaymentRule($cashier);

    $fixture = p13moneyInvoiceFixture($cal);
    $invoice = p13moneyDraftInvoice($fixture, $cashier);
    [$issued] = app(IssueInvoice::class)->handle([$invoice->id], $cashier->toAuditActor());

    $payment = p13moneyRecordCash(
        $fixture['enrollment']->student_id,
        $fixture['enrollment']->id,
        $cal,
        $cashier,
        120_000,
    );

    $original = app(PrintReceipt::class)->handle($payment->id);
    expect($original->html)->toContain($issued->invoice_no ?? '');

    // A legitimate later correction - the payment's allocation is reversed
    // (e.g. moved to a different invoice line) - must not break the ability
    // to reprint what was already issued: the receipt shows the lines that
    // were true AT ISSUE, not whatever payment_allocations says right now.
    DB::table('payment_allocations')->where('payment_id', $payment->id)->update([
        'reversed_at' => now(),
        'reversed_by' => $cashier->id,
        'reversal_reason' => 'test reallocation',
    ]);

    $reprint = app(PrintReceipt::class)->handle($payment->id);
    expect($reprint->html)->toContain($issued->invoice_no ?? '');
    expect($reprint->isDuplicate)->toBeTrue();
});

it('refuses to let payload_snapshot be mutated after issue, the same as content_hash', function (): void {
    $cashier = p13moneyUserAs(Role::Bursar, Role::Accountant);
    $cal = ledgerCalendar('2031-03-15');
    p13moneySaveCashPaymentRule($cashier);

    $payment = p13moneyRecordCash(Student::factory()->create()->id, null, $cal, $cashier, 25_000);
    app(PrintReceipt::class)->handle($payment->id);

    $issued = IssuedDocument::query()
        ->where('subject_type', 'Payment')
        ->where('subject_id', $payment->id)
        ->firstOrFail();

    expect($issued->payload_snapshot)->not->toBeNull();

    // The whole fix depends on this column being append-only, exactly like
    // content_hash - if a future edit ever adds it to booted()'s $mutable
    // list (or something bypasses save() to write it directly), the frozen
    // payload stops being trustworthy and this test is what catches it.
    expect(fn () => $issued->update(['payload_snapshot' => ['tampered' => true]]))
        ->toThrow(RuntimeException::class, 'append-only');
});
