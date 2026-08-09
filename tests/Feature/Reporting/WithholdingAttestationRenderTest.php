<?php

declare(strict_types=1);

use App\Modules\Tax\Actions\PrintWithholdingAttestation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Procurement/SupplierPaymentTestHelpers.php';
require_once __DIR__.'/P13MoneyHelpers.php';

uses(RefreshDatabase::class);

/**
 * docs/specs/03-tax-procurement.md §6.6 / 10-documents.md §15 (WHT-CERT,
 * phase-12-13 D3) - the withholding attestation is issued automatically by
 * PaySupplierPayment (on_payment recognition); this suite only exercises
 * the PRINT of that already-issued attestation, the receipt pattern (its
 * own attestation_no, no fresh series number) and the fiscal-identity gate.
 */
beforeEach(function (): void {
    p13moneyDocumentProfile();
});

it('prints the attestation with its own attestation_no, supplier, base and withheld amounts', function (): void {
    $baseline = f4PayBaseline('on_payment');

    $invoice = f4PayPostedInvoice($baseline['supplier'], [
        f4PayServiceLine($baseline['tax_code']),
    ]);

    ['payment' => $draft] = f4PayRecordDraft($baseline['supplier'], [
        ['supplier_invoice_id' => $invoice->id, 'amount' => (int) $invoice->total_ttc],
    ]);
    f4PayApproveAndPay($draft);

    $attestationId = (int) DB::table('withholding_attestations')
        ->where('supplier_payment_id', $draft->id)
        ->value('id');
    expect($attestationId)->toBeGreaterThan(0);

    $attestationNo = DB::table('withholding_attestations')->where('id', $attestationId)->value('attestation_no');

    $rendered = app(PrintWithholdingAttestation::class)->handle($attestationId);

    expect($rendered->serial)->toBeNull();
    expect($rendered->html)->toContain((string) $attestationNo);
    expect($rendered->html)->toContain($baseline['supplier']->name);
    expect($rendered->html)->toContain('AIR on services (F4)');
});

it('a reprint of the same attestation is a DUPLICATA', function (): void {
    $baseline = f4PayBaseline('on_payment');

    $invoice = f4PayPostedInvoice($baseline['supplier'], [
        f4PayServiceLine($baseline['tax_code']),
    ]);

    ['payment' => $draft] = f4PayRecordDraft($baseline['supplier'], [
        ['supplier_invoice_id' => $invoice->id, 'amount' => (int) $invoice->total_ttc],
    ]);
    f4PayApproveAndPay($draft);

    $attestationId = (int) DB::table('withholding_attestations')
        ->where('supplier_payment_id', $draft->id)
        ->value('id');

    $first = app(PrintWithholdingAttestation::class)->handle($attestationId);
    expect($first->isDuplicate)->toBeFalse();

    $second = app(PrintWithholdingAttestation::class)->handle($attestationId);
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
    f4PayApproveAndPay($draft);

    $attestationId = (int) DB::table('withholding_attestations')
        ->where('supplier_payment_id', $draft->id)
        ->value('id');

    DB::table('fiscal_identities')->where('id', 1)->update(['niu' => null]);

    expect(fn () => app(PrintWithholdingAttestation::class)->handle($attestationId))
        ->toThrow(DomainException::class, 'fiscal identity is incomplete');
});

it('refuses a non-existent attestation id', function (): void {
    f4PayUser(\App\Modules\Identity\Domain\Permission::TaxView->value);

    expect(fn () => app(PrintWithholdingAttestation::class)->handle(999_999))
        ->toThrow(DomainException::class, 'does not exist');
});
