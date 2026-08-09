<?php

declare(strict_types=1);

use App\Modules\Fees\Actions\IssueInvoice;
use App\Modules\Fees\Actions\PrintReceipt;
use App\Modules\Fees\Actions\VoidPayment;
use App\Modules\Fees\Domain\PaymentVoidReason;
use App\Modules\Identity\Domain\Role;
use App\Modules\Students\Models\Student;
use App\Support\Fiscal\FiscalIdentityGate;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../Accounting/AccountingTestHelpers.php';
require_once __DIR__.'/P13MoneyHelpers.php';

uses(RefreshDatabase::class);

/**
 * docs/specs/10-documents.md §10.1 (phase-12-13 D3) - the Fee Receipt: the
 * A5/POS80 templates, the "receipt pattern" (Payment IS the snapshot, no
 * fresh series number), the void overlay, and the fiscal-identity gate that
 * refuses to print a money document at all without a NIU.
 */
beforeEach(function (): void {
    p13moneyDocumentProfile();
    p13moneyConfirmedFiscalIdentity();
});

it('refuses to render any money document while the fiscal identity is incomplete', function (): void {
    $cashier = p13moneyUserAs(Role::Bursar, Role::Accountant);
    $cal = ledgerCalendar('2031-03-15');
    p13moneySaveCashPaymentRule($cashier);

    // Wipe the row the gate checks - an incomplete identity, not a missing one.
    p13moneyConfirmedFiscalIdentity(['niu' => null]);

    $payment = p13moneyRecordCash(Student::factory()->create()->id, null, $cal, $cashier, 50_000);

    expect(fn () => app(PrintReceipt::class)->handle($payment->id))
        ->toThrow(DomainException::class, 'fiscal identity is incomplete');
});

it('confirms the exact missing-field list the gate reports', function (): void {
    p13moneyConfirmedFiscalIdentity(['niu' => null, 'tax_regime' => null]);

    expect(FiscalIdentityGate::missingFields())->toBe(['niu', 'tax_regime']);

    p13moneyConfirmedFiscalIdentity();
    expect(FiscalIdentityGate::missingFields())->toBe([]);
});

it('prints the original A5 receipt with the allocated receipt_no, lines and amount in words', function (): void {
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

    $rendered = app(PrintReceipt::class)->handle($payment->id);

    expect($rendered->serial)->toBeNull(); // receipt pattern: no platform series number
    expect($rendered->html)->toContain($payment->receipt_no);
    expect($rendered->html)->toContain($issued->invoice_no ?? '');
    expect($rendered->html)->toContain('One hundred twenty thousand'); // ucfirst() in the blade
    expect($rendered->html)->not->toContain('DUPLICATA');
    expect($rendered->html)->not->toContain('This receipt has been voided');
});

it('prints the 80mm POS variant against its own template row', function (): void {
    $cashier = p13moneyUserAs(Role::Bursar, Role::Accountant);
    $cal = ledgerCalendar('2031-03-15');
    p13moneySaveCashPaymentRule($cashier);

    $payment = p13moneyRecordCash(Student::factory()->create()->id, null, $cal, $cashier, 25_000);

    $rendered = app(PrintReceipt::class)->handle($payment->id, PrintReceipt::VARIANT_POS);

    expect($rendered->html)->toContain($payment->receipt_no);

    $templateCode = \Illuminate\Support\Facades\DB::table('issued_documents as id')
        ->join('document_templates as dt', 'dt.id', '=', 'id.document_template_id')
        ->where('id.subject_type', 'Payment')
        ->where('id.subject_id', $payment->id)
        ->value('dt.code');
    expect($templateCode)->toBe('FEE-RECEIPT-POS');
});

it('reprinting the same payment is a DUPLICATA, never a fresh original', function (): void {
    $cashier = p13moneyUserAs(Role::Bursar, Role::Accountant);
    $cal = ledgerCalendar('2031-03-15');
    p13moneySaveCashPaymentRule($cashier);

    $payment = p13moneyRecordCash(Student::factory()->create()->id, null, $cal, $cashier, 30_000);

    $first = app(PrintReceipt::class)->handle($payment->id);
    expect($first->isDuplicate)->toBeFalse();

    $second = app(PrintReceipt::class)->handle($payment->id);
    expect($second->isDuplicate)->toBeTrue();
    expect($second->html)->toContain('DUPLICATA');
    expect($second->issuedDocumentId)->toBe($first->issuedDocumentId);
});

it('applies the VOID overlay and refuses a first-ever print of an already-voided payment', function (): void {
    $cashier = p13moneyUserAs(Role::Bursar, Role::Accountant);
    $cal = ledgerCalendar('2031-03-15');
    p13moneySaveCashPaymentRule($cashier);

    $printedThenVoided = p13moneyRecordCash(Student::factory()->create()->id, null, $cal, $cashier, 40_000);
    app(PrintReceipt::class)->handle($printedThenVoided->id);

    // 04-fees §11.5: the cashier who recorded a payment cannot void it - a
    // second office does, here a fresh Accountant (the one role holding
    // both FeeVoid and DocumentsRevoke, since voiding automatically
    // revokes the receipt's IssuedDocument row - 10-documents §10.1).
    $voider = p13moneyUserAs(Role::Accountant);

    app(VoidPayment::class)->handle(
        $printedThenVoided->id,
        PaymentVoidReason::CashierError,
        'Wrong amount keyed in at the till.',
        $voider->toAuditActor(),
    );

    $reprint = app(PrintReceipt::class)->handle($printedThenVoided->id);
    expect($reprint->html)->toContain('This receipt has been voided');

    // A SECOND payment, voided before it was ever printed once: no original
    // is issued for money that no longer stands (04-fees §11.5).
    $secondCashier = p13moneyUserAs(Role::Bursar);
    $neverPrinted = p13moneyRecordCash(Student::factory()->create()->id, null, $cal, $secondCashier, 15_000);

    $secondVoider = p13moneyUserAs(Role::Accountant);
    app(VoidPayment::class)->handle(
        $neverPrinted->id,
        PaymentVoidReason::KeyingError,
        'Duplicate entry, caught before printing.',
        $secondVoider->toAuditActor(),
    );

    expect(fn () => app(PrintReceipt::class)->handle($neverPrinted->id))
        ->toThrow(DomainException::class, 'voided');
});

it('refuses a non-existent payment id', function (): void {
    p13moneyUserAs(Role::Bursar, Role::Accountant);

    expect(fn () => app(PrintReceipt::class)->handle(999_999))
        ->toThrow(DomainException::class, 'does not exist');
});
