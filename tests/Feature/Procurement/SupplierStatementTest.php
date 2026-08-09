<?php

declare(strict_types=1);

use App\Modules\Procurement\Actions\SupplierStatement;
use App\Modules\Procurement\Domain\ProcurementPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/SupplierPaymentTestHelpers.php';

uses(RefreshDatabase::class);

// ── The statement matches the auxiliary ledger exactly ──────────────────

it('builds the statement from the auxiliary ledger: opening, invoice credit, payment debit, running balance, closing', function () {
    $fixture = f4PayBaseline('on_payment');
    $supplier = f4PaySupplier([
        'is_withholding_exempt' => true,
        'withholding_exemption_ref' => 'EXO-F4-02',
    ]);

    $invoice = f4PayPostedInvoice($supplier, [f4PayServiceLine($fixture['tax_code'])]);

    ['payment' => $draft] = f4PayRecordDraft($supplier, [
        ['supplier_invoice_id' => (int) $invoice->id, 'amount' => 431_000],
    ]);
    f4PayApproveAndPay($draft);

    f4PayUser(ProcurementPermission::VIEW);
    $statement = app(SupplierStatement::class)->handle((int) $supplier->id, '2031-03-01', '2031-03-31');

    expect($statement['opening_balance'])->toBe(0)
        ->and($statement['closing_balance'])->toBe(1_000_000)
        ->and(count($statement['movements']))->toBe(2);

    [$invoiceMove, $paymentMove] = $statement['movements'];

    expect($invoiceMove->credit)->toBe(1_431_000)
        ->and($invoiceMove->balance)->toBe(1_431_000)
        ->and($paymentMove->debit)->toBe(431_000)
        ->and($paymentMove->balance)->toBe(1_000_000);

    // A range starting after the invoice carries it in the OPENING balance.
    $later = app(SupplierStatement::class)->handle((int) $supplier->id, '2031-03-16', '2031-03-31');
    expect($later['opening_balance'])->toBe(1_431_000)
        ->and($later['closing_balance'])->toBe(1_000_000);
});

// ── §4.9 auxiliary/collective reconciliation ────────────────────────────

it('reconciles Σ per-supplier balances to the 401+481+4817+4818 account balance, withholding recognition included', function () {
    // on_invoice: the recognition entry (Dr 401 / Cr 447) must stay inside
    // the auxiliary or the reconciliation would break - that is the point.
    $fixture = f4PayBaseline('on_invoice');

    $supplierA = f4PaySupplier();
    $supplierB = f4PaySupplier([
        'is_withholding_exempt' => true,
        'withholding_exemption_ref' => 'EXO-F4-03',
    ]);

    $invoiceA = f4PayPostedInvoice($supplierA, [f4PayServiceLine($fixture['tax_code'])]);
    f4PayPostedInvoice($supplierB, [f4PayServiceLine($fixture['tax_code'], ['unit_price_ht' => 800_000])]);

    // Partially settle A.
    ['payment' => $draft] = f4PayRecordDraft($supplierA, [
        ['supplier_invoice_id' => (int) $invoiceA->id, 'amount' => 365_000],
    ]);
    f4PayApproveAndPay($draft);

    f4PayUser(ProcurementPermission::VIEW);
    $reconciliation = app(SupplierStatement::class)->reconciliation('2031-03-31');

    expect($reconciliation['balanced'])->toBeTrue()
        ->and($reconciliation['per_supplier_total'])->toBe($reconciliation['account_total']);

    // And the per-supplier figures are the auxiliary truth:
    // A: 1 431 000 − 66 000 recognised withholding − 365 000 paid.
    expect($reconciliation['by_supplier'][(int) $supplierA->id])->toBe(1_431_000 - 66_000 - 365_000);

    // Nothing on the collective accounts escaped a partner (L8).
    $orphans = DB::table('journal_entry_lines')
        ->where('account_id', f4PayAccountId('401'))
        ->whereNull('partner_id')
        ->count();
    expect($orphans)->toBe(0);
});
