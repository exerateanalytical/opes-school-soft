<?php

declare(strict_types=1);

use App\Modules\Procurement\Actions\ReleaseRetention;
use App\Modules\Procurement\Actions\SupplierStatement;
use App\Modules\Procurement\Actions\VoidSupplierPayment;
use App\Modules\Procurement\Domain\ProcurementPermission;
use App\Modules\Procurement\Domain\SupplierPaymentPermission;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderLine;
use App\Modules\Procurement\Models\Supplier;
use App\Modules\Procurement\Models\SupplierInvoice;
use App\Modules\Procurement\Models\SupplierRetention;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/SupplierPaymentTestHelpers.php';

uses(RefreshDatabase::class);

if (! function_exists('f4PayRetentionInvoice')) {
    /**
     * A posted 1 000 000 works invoice (zero-rate TVA, exempt supplier)
     * under a PO carrying a 5% retenue de garantie.
     *
     * @param  array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}  $calendar
     * @return array{invoice: SupplierInvoice, supplier: Supplier}
     */
    function f4PayRetentionInvoice(array $calendar): array
    {
        $supplier = f4PaySupplier([
            'is_withholding_exempt' => true,
            'withholding_exemption_ref' => 'EXO-F4-RG',
        ]);

        $creator = \App\Modules\Identity\Models\User::factory()->create();
        $expenseAccountId = f4PayExpenseAccountId();

        $po = PurchaseOrder::query()->create([
            'po_no' => 'BC/2031/'.fake()->unique()->numberBetween(100000, 999999),
            'supplier_id' => $supplier->getKey(),
            'order_date' => '2031-02-01',
            'status' => 'sent',
            'retention_rate_bp' => 500,
            'retention_release_due_on' => '2031-09-30',
            'created_by' => $creator->getKey(),
            'payable_account_id' => f4PayAccountId('401'),
            'academic_year_id' => $calendar['academic_year_id'],
            'fiscal_year_id' => $calendar['fiscal_year_id'],
        ]);

        $poLine = PurchaseOrderLine::query()->create([
            'purchase_order_id' => $po->getKey(),
            'line_no' => 1,
            'description' => 'Roof repair works',
            'quantity' => '1.000',
            'unit_price_ht' => 1_000_000,
            'amount_ht' => 1_000_000,
            'tax_amount' => 0,
            'amount_ttc' => 1_000_000,
            'expense_account_id' => $expenseAccountId,
            'qty_received' => '1.000',
            'qty_invoiced' => '0.000',
        ]);

        $zeroTax = f4PayInputTaxCode(['rate_bp' => 0]);

        $invoice = f4PayPostedInvoice($supplier, [[
            'description' => 'Roof repair works',
            'quantity' => '1',
            'unit_price_ht' => 1_000_000,
            'tax_code_id' => (int) $zeroTax->id,
            'expense_account_id' => $expenseAccountId,
            'purchase_order_line_id' => (int) $poLine->getKey(),
        ]], ['purchase_order_id' => (int) $po->getKey()]);

        return ['invoice' => $invoice, 'supplier' => $supplier];
    }
}

// ── §3.3: the retained portion goes to 4817, never expense ──────────────

it('withholds the retenue de garantie to 4817 at first settlement and letters the payable to zero', function () {
    $fixture = f4PayBaseline('on_payment');
    ['invoice' => $invoice, 'supplier' => $supplier] = f4PayRetentionInvoice($fixture['calendar']);

    expect($invoice->retention_amount)->toBe(50_000);

    // Outstanding excludes the retention: 950 000, recomputed under lock.
    ['payment' => $draft] = f4PayRecordDraft($supplier, [
        ['supplier_invoice_id' => (int) $invoice->id, 'amount' => 950_000],
    ]);
    ['payment' => $paid] = f4PayApproveAndPay($draft);

    // Settlement entry: Dr 401 950 000 / Cr 5x 950 000.
    $settlement = DB::table('journal_entry_lines')->where('journal_entry_id', $paid->journal_entry_id)->get();
    expect((int) $settlement->where('account_id', f4PayAccountId('401'))->sum('debit'))->toBe(950_000)
        ->and((int) $settlement->where('account_id', f4PayAccountId('52'))->sum('credit'))->toBe(950_000);

    // Retention reclass: Dr 401 / Cr 4817, both carrying the supplier.
    /** @var SupplierRetention $retention */
    $retention = SupplierRetention::query()->where('supplier_invoice_id', $invoice->id)->firstOrFail();
    expect($retention->amount)->toBe(50_000)
        ->and($retention->status->value)->toBe('withheld')
        ->and($retention->release_due_on)->toBe('2031-09-30');

    $reclass = DB::table('journal_entry_lines')->where('journal_entry_id', $retention->withheld_journal_entry_id)->get();
    expect((int) $reclass->where('account_id', f4PayAccountId('401'))->sum('debit'))->toBe(50_000)
        ->and((int) $reclass->where('account_id', f4PayAccountId('4817'))->sum('credit'))->toBe(50_000);

    $retentionLeg = f4PayRow($reclass->firstWhere('account_id', f4PayAccountId('4817')));
    expect((string) $retentionLeg->partner_type)->toBe('supplier')
        ->and((int) $retentionLeg->partner_id)->toBe((int) $supplier->getKey());

    // Retention never touches expense: the class-6 movement is exactly the
    // invoice's HT, nothing more.
    $expenseNet = DB::table('journal_entry_lines')
        ->where('account_id', f4PayExpenseAccountId())
        ->selectRaw('CAST(SUM(debit) - SUM(credit) AS SIGNED) as net')
        ->value('net');
    expect((int) $expenseNet)->toBe(1_000_000);

    // The invoice is PAID (401 nets to zero and is lettered Full).
    expect($invoice->refresh()->status->value)->toBe('paid');

    $allocation = f4PayRow(DB::table('supplier_payment_allocations')->where('supplier_payment_id', $paid->id)->first());
    expect($allocation->letter_code)->not->toBeNull();

    // Auxiliary reconciliation holds WITH the 4817 balance (§4.9).
    f4PayUser(ProcurementPermission::VIEW);
    $reconciliation = app(SupplierStatement::class)->reconciliation('2031-03-31');
    expect($reconciliation['balanced'])->toBeTrue()
        ->and($reconciliation['by_supplier'][(int) $supplier->getKey()])->toBe(50_000);
});

it('releases the retention on acceptance - Dr 4817 / Cr 401 - and the reopened balance is paid like any payable', function () {
    $fixture = f4PayBaseline('on_payment');
    ['invoice' => $invoice, 'supplier' => $supplier] = f4PayRetentionInvoice($fixture['calendar']);

    ['payment' => $draft] = f4PayRecordDraft($supplier, [
        ['supplier_invoice_id' => (int) $invoice->id, 'amount' => 950_000],
    ]);
    f4PayApproveAndPay($draft);

    $releaser = f4PayUser(
        SupplierPaymentPermission::APPROVE,
        \App\Modules\Identity\Domain\Permission::LedgerPost->value,
    );
    $retention = app(ReleaseRetention::class)->handle((int) $invoice->id, f4PayActor($releaser), '2031-03-25');

    expect($retention->status->value)->toBe('released');

    $release = DB::table('journal_entry_lines')->where('journal_entry_id', $retention->release_journal_entry_id)->get();
    expect((int) $release->where('account_id', f4PayAccountId('4817'))->sum('debit'))->toBe(50_000)
        ->and((int) $release->where('account_id', f4PayAccountId('401'))->sum('credit'))->toBe(50_000);

    // 4817 nets to zero; the invoice re-opens for the released 50 000.
    $net4817 = DB::table('journal_entry_lines')
        ->where('account_id', f4PayAccountId('4817'))
        ->selectRaw('CAST(SUM(credit) - SUM(debit) AS SIGNED) as net')
        ->value('net');
    expect((int) $net4817)->toBe(0)
        ->and($invoice->refresh()->status->value)->toBe('partially_paid');

    // A second release is refused - the chain moved on.
    expect(fn () => app(ReleaseRetention::class)->handle((int) $invoice->id, f4PayActor($releaser), '2031-03-26'))
        ->toThrow(DomainException::class, 'released');

    // The released amount settles as an ordinary payment.
    ['payment' => $finalDraft] = f4PayRecordDraft($supplier, [
        ['supplier_invoice_id' => (int) $invoice->id, 'amount' => 50_000],
    ]);
    f4PayApproveAndPay($finalDraft);

    expect($invoice->refresh()->status->value)->toBe('paid');
});

it('refuses voiding the withholding payment once the retention has been released - the chain cannot be unwound', function () {
    $fixture = f4PayBaseline('on_payment');
    ['invoice' => $invoice, 'supplier' => $supplier] = f4PayRetentionInvoice($fixture['calendar']);

    ['payment' => $draft] = f4PayRecordDraft($supplier, [
        ['supplier_invoice_id' => (int) $invoice->id, 'amount' => 950_000],
    ]);
    ['payment' => $paid] = f4PayApproveAndPay($draft);

    $releaser = f4PayUser(
        SupplierPaymentPermission::APPROVE,
        \App\Modules\Identity\Domain\Permission::LedgerPost->value,
    );
    app(ReleaseRetention::class)->handle((int) $invoice->id, f4PayActor($releaser), '2031-03-25');

    $voider = f4PayUser(
        SupplierPaymentPermission::VOID,
        \App\Modules\Identity\Domain\Permission::LedgerPost->value,
    );

    expect(fn () => app(VoidSupplierPayment::class)->handle((int) $paid->id, 'attempting to unwind a released chain', f4PayActor($voider)))
        ->toThrow(DomainException::class, 'RELEASED');
});
