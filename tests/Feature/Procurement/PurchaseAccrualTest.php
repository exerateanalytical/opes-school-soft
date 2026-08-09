<?php

declare(strict_types=1);

use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Procurement\Actions\RunYearEndPurchaseAccrual;
use App\Modules\Procurement\Models\GoodsReceipt;
use App\Modules\Procurement\Models\GoodsReceiptLine;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderLine;
use App\Modules\Procurement\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/SupplierPaymentTestHelpers.php';

uses(RefreshDatabase::class);

if (! function_exists('f4PayAccrualCalendar')) {
    /**
     * Extend the baseline calendar with an OPEN December 2031 period and
     * an open January 2032 period in a fresh FY 2032 (with its academic
     * year), so the closing entry and its first-day reversal both land.
     *
     * @param  array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}  $calendar
     */
    function f4PayAccrualCalendar(array $calendar): void
    {
        AccountingPeriod::factory()->create([
            'fiscal_year_id' => $calendar['fiscal_year_id'],
            'period_month' => '2031-12-01',
            'starts_on' => '2031-12-01',
            'ends_on' => '2031-12-31',
            'status' => 'open',
        ]);

        $nextYear = FiscalYear::factory()->create([
            'code' => strtoupper(Str::random(8)),
            'starts_on' => '2032-01-01',
            'ends_on' => '2032-12-31',
            'status' => 'open',
        ]);

        AccountingPeriod::factory()->create([
            'fiscal_year_id' => $nextYear->getKey(),
            'period_month' => '2032-01-01',
            'starts_on' => '2032-01-01',
            'ends_on' => '2032-01-31',
            'status' => 'open',
        ]);

        DB::table('academic_years')->insert([
            'code' => 'AY-2032-'.Str::random(8),
            'name' => 'Academic Year 2032',
            'starts_on' => '2032-01-01',
            'ends_on' => '2032-12-31',
            'is_current' => false,
            'status' => 'planned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('f4PayReceiptAgainstPo')) {
    /**
     * A sent PO with one service line and a confirmed receipt for it.
     *
     * @param  array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}  $calendar
     * @return array{po_line: PurchaseOrderLine, gr_line: GoodsReceiptLine}
     */
    function f4PayReceiptAgainstPo(
        Supplier $supplier,
        array $calendar,
        int $unitPrice = 250_000,
        string $qtyOrdered = '4.000',
        string $qtyAccepted = '4.000',
    ): array {
        $creator = \App\Modules\Identity\Models\User::factory()->create();

        $po = PurchaseOrder::query()->create([
            'po_no' => 'BC/2031/'.fake()->unique()->numberBetween(100000, 999999),
            'supplier_id' => $supplier->getKey(),
            'order_date' => '2031-11-10',
            'status' => 'sent',
            'created_by' => $creator->getKey(),
            'payable_account_id' => f4PayAccountId('401'),
            'academic_year_id' => $calendar['academic_year_id'],
            'fiscal_year_id' => $calendar['fiscal_year_id'],
        ]);

        $qtyMillis = \App\Modules\Procurement\Domain\LineAmount::toMillis($qtyOrdered);
        $amountHt = \App\Modules\Procurement\Domain\LineAmount::compute($qtyOrdered, $unitPrice);

        $poLine = PurchaseOrderLine::query()->create([
            'purchase_order_id' => $po->getKey(),
            'line_no' => 1,
            'description' => 'Maintenance service',
            'quantity' => $qtyOrdered,
            'unit_price_ht' => $unitPrice,
            'amount_ht' => $amountHt,
            'tax_amount' => 0,
            'amount_ttc' => $amountHt,
            'expense_account_id' => f4PayExpenseAccountId(),
            'qty_received' => $qtyAccepted,
            'qty_invoiced' => '0.000',
        ]);

        $gr = GoodsReceipt::query()->create([
            'receipt_no' => 'BR/2031/'.fake()->unique()->numberBetween(100000, 999999),
            'purchase_order_id' => $po->getKey(),
            'supplier_id' => $supplier->getKey(),
            'received_on' => '2031-12-10',
            'received_by' => $creator->getKey(),
            'status' => 'confirmed',
            'academic_year_id' => $calendar['academic_year_id'],
            'fiscal_year_id' => $calendar['fiscal_year_id'],
        ]);

        $grLine = GoodsReceiptLine::query()->create([
            'goods_receipt_id' => $gr->getKey(),
            'line_no' => 1,
            'purchase_order_line_id' => $poLine->getKey(),
            'description' => 'Maintenance service',
            'qty_ordered' => $qtyOrdered,
            'qty_received' => $qtyAccepted,
            'qty_accepted' => $qtyAccepted,
            'qty_rejected' => '0.000',
        ]);

        return ['po_line' => $poLine, 'gr_line' => $grLine];
    }
}

// ── §3.3: Dr 60x / Cr 4818 at closing, reversed on day one ──────────────

it('accrues receipt-not-invoiced lines to 4818 at PO price on the closing date and reverses on the first day of the next period', function () {
    $fixture = f4PayBaseline('on_payment');
    f4PayAccrualCalendar($fixture['calendar']);

    $supplier = f4PaySupplier();
    ['gr_line' => $grLine, 'po_line' => $poLine] = f4PayReceiptAgainstPo($supplier, $fixture['calendar']);

    $runner = f4PayUser(\App\Modules\Identity\Domain\Permission::LedgerPost->value);
    $result = app(RunYearEndPurchaseAccrual::class)->handle($fixture['calendar']['fiscal_year_id'], f4PayActor($runner));

    expect(count($result['accruals']))->toBe(1);

    $accrual = $result['accruals'][0];

    // Valued at PO price: 4.000 × 250 000.
    expect($accrual->amount_ht)->toBe(1_000_000)
        ->and($accrual->goods_receipt_line_id)->toBe((int) $grLine->getKey());

    $accrualAccountId = f4PayAccountId('4818');

    // Accrual entry: Dr expense / Cr 4818 (partner), dated 2031-12-31.
    $entry = f4PayRow(DB::table('journal_entries')->where('id', $accrual->journal_entry_id)->first());
    expect((string) $entry->date)->toBe('2031-12-31');

    $accrualLines = DB::table('journal_entry_lines')->where('journal_entry_id', $accrual->journal_entry_id)->get();
    expect((int) $accrualLines->where('account_id', (int) $poLine->expense_account_id)->sum('debit'))->toBe(1_000_000)
        ->and((int) $accrualLines->where('account_id', $accrualAccountId)->sum('credit'))->toBe(1_000_000);

    $fnpLine = f4PayRow($accrualLines->firstWhere('account_id', $accrualAccountId));
    expect((string) $fnpLine->partner_type)->toBe('supplier')
        ->and((int) $fnpLine->partner_id)->toBe((int) $supplier->getKey());

    // Reversal: Dr 4818 / Cr expense, dated 2032-01-01 - the FIRST day.
    $reversal = f4PayRow(DB::table('journal_entries')->where('id', $accrual->reversal_journal_entry_id)->first());
    expect((string) $reversal->date)->toBe('2032-01-01');

    $reversalLines = DB::table('journal_entry_lines')->where('journal_entry_id', $accrual->reversal_journal_entry_id)->get();
    expect((int) $reversalLines->where('account_id', $accrualAccountId)->sum('debit'))->toBe(1_000_000)
        ->and((int) $reversalLines->where('account_id', (int) $poLine->expense_account_id)->sum('credit'))->toBe(1_000_000);

    // 4818 nets to zero across the two entries - the accrual never becomes
    // a phantom payable.
    $net = DB::table('journal_entry_lines')
        ->where('account_id', $accrualAccountId)
        ->selectRaw('CAST(SUM(credit) - SUM(debit) AS SIGNED) as net')
        ->value('net');
    expect((int) $net)->toBe(0);
});

it('is idempotent at the database and excludes receipts already invoiced at the closing date', function () {
    $fixture = f4PayBaseline('on_payment');
    f4PayAccrualCalendar($fixture['calendar']);

    $supplier = f4PaySupplier([
        'is_withholding_exempt' => true,
        'withholding_exemption_ref' => 'EXO-F4-04',
    ]);

    // Line A: accepted, never invoiced → accrues.
    f4PayReceiptAgainstPo($supplier, $fixture['calendar']);

    // Line B: accepted AND fully invoiced before closing → nothing to accrue.
    ['gr_line' => $invoicedGrLine, 'po_line' => $invoicedPoLine] = f4PayReceiptAgainstPo($supplier, $fixture['calendar'], 100_000, '2.000', '2.000');
    $zeroTax = f4PayInputTaxCode(['rate_bp' => 0]);
    f4PayPostedInvoice($supplier, [[
        'description' => 'Invoiced service',
        'quantity' => '2',
        'unit_price_ht' => 100_000,
        'tax_code_id' => (int) $zeroTax->id,
        'expense_account_id' => (int) $invoicedPoLine->expense_account_id,
        'purchase_order_line_id' => (int) $invoicedPoLine->getKey(),
        'goods_receipt_line_id' => (int) $invoicedGrLine->getKey(),
    ]], ['invoice_date' => '2031-12-15', 'purchase_order_id' => (int) $invoicedPoLine->purchase_order_id]);

    $runner = f4PayUser(\App\Modules\Identity\Domain\Permission::LedgerPost->value);
    $first = app(RunYearEndPurchaseAccrual::class)->handle($fixture['calendar']['fiscal_year_id'], f4PayActor($runner));

    expect(count($first['accruals']))->toBe(1);

    // Re-run: the UNIQUE(fiscal_year_id, goods_receipt_line_id) input set
    // is already consumed - nothing accrues twice.
    $second = app(RunYearEndPurchaseAccrual::class)->handle($fixture['calendar']['fiscal_year_id'], f4PayActor($runner));

    expect($second['accruals'])->toBe([])
        ->and(DB::table('purchase_accruals')->count())->toBe(1);
});
