<?php

declare(strict_types=1);

use App\Modules\Procurement\Actions\PrintPaymentVoucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Procurement/SupplierPaymentTestHelpers.php';
require_once __DIR__.'/P13MoneyHelpers.php';

uses(RefreshDatabase::class);

/**
 * phase-12-13 D3 / docs/specs/03-tax-procurement.md §9 - the Payment
 * Voucher for an already-recorded SupplierPayment: the receipt pattern
 * (payment_no is Procurement's own number), gross/withholding/net figures,
 * allocations and the fiscal-identity gate.
 */
beforeEach(function (): void {
    p13moneyDocumentProfile();
});

it('prints the voucher with payment_no, allocations and net amount paid', function (): void {
    $baseline = f4PayBaseline('on_payment');

    $invoice = f4PayPostedInvoice($baseline['supplier'], [
        f4PayServiceLine($baseline['tax_code']),
    ]);

    ['payment' => $draft] = f4PayRecordDraft($baseline['supplier'], [
        ['supplier_invoice_id' => $invoice->id, 'amount' => (int) $invoice->total_ttc],
    ]);
    ['payment' => $paid] = f4PayApproveAndPay($draft);

    $rendered = app(PrintPaymentVoucher::class)->handle($paid->id);

    expect($rendered->serial)->toBeNull();
    expect($rendered->html)->toContain($paid->payment_no);
    expect($rendered->html)->toContain($baseline['supplier']->name);
    expect($rendered->html)->toContain($invoice->internal_no);
});

it('a reprint of the same voucher is a DUPLICATA', function (): void {
    $baseline = f4PayBaseline('on_payment');

    $invoice = f4PayPostedInvoice($baseline['supplier'], [
        f4PayServiceLine($baseline['tax_code']),
    ]);

    ['payment' => $draft] = f4PayRecordDraft($baseline['supplier'], [
        ['supplier_invoice_id' => $invoice->id, 'amount' => (int) $invoice->total_ttc],
    ]);
    ['payment' => $paid] = f4PayApproveAndPay($draft);

    $first = app(PrintPaymentVoucher::class)->handle($paid->id);
    expect($first->isDuplicate)->toBeFalse();

    $second = app(PrintPaymentVoucher::class)->handle($paid->id);
    expect($second->isDuplicate)->toBeTrue();
    expect($second->html)->toContain('DUPLICATA');
});

it('refuses to print while the fiscal identity is incomplete', function (): void {
    $baseline = f4PayBaseline('on_payment');

    $invoice = f4PayPostedInvoice($baseline['supplier'], [
        f4PayServiceLine($baseline['tax_code']),
    ]);

    ['payment' => $draft] = f4PayRecordDraft($baseline['supplier'], [
        ['supplier_invoice_id' => $invoice->id, 'amount' => (int) $invoice->total_ttc],
    ]);
    ['payment' => $paid] = f4PayApproveAndPay($draft);

    DB::table('fiscal_identities')->where('id', 1)->update(['legal_name' => null]);

    expect(fn () => app(PrintPaymentVoucher::class)->handle($paid->id))
        ->toThrow(DomainException::class, 'fiscal identity is incomplete');
});

it('refuses a non-existent supplier payment id', function (): void {
    f4PayUser(\App\Modules\Identity\Domain\Permission::ProcurementPaymentRecord->value);

    expect(fn () => app(PrintPaymentVoucher::class)->handle(999_999))
        ->toThrow(DomainException::class, 'does not exist');
});
