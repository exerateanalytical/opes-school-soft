<?php

declare(strict_types=1);

use App\Modules\Procurement\Actions\AgedPayables;
use App\Modules\Procurement\Domain\ProcurementPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/SupplierPaymentTestHelpers.php';

uses(RefreshDatabase::class);

if (! function_exists('f4PayAgedViewer')) {
    /** Sign in a report viewer. */
    function f4PayAgedViewer(): void
    {
        f4PayUser(ProcurementPermission::VIEW);
    }
}

if (! function_exists('f4PayExemptSupplier')) {
    /** A withholding-exempt supplier so aging numbers carry no 447 noise. */
    function f4PayExemptSupplier(): \App\Modules\Procurement\Models\Supplier
    {
        return f4PaySupplier([
            'is_withholding_exempt' => true,
            'withholding_exemption_ref' => 'EXO-F4-01',
        ]);
    }
}

// ── The axis is due_date, never invoice date ────────────────────────────

it('ages unlettered payables on the DUE DATE axis, printing the axis and as_of with the data', function () {
    $fixture = f4PayBaseline('on_payment');
    $supplier = f4PayExemptSupplier();

    // Same invoice date, spread due dates - the §4.9 point.
    f4PayPostedInvoice($supplier, [f4PayServiceLine($fixture['tax_code'])], ['due_date' => '2031-04-14']);
    f4PayPostedInvoice($supplier, [f4PayServiceLine($fixture['tax_code'])], ['due_date' => '2031-01-31']);

    f4PayAgedViewer();
    $report = app(AgedPayables::class)->handle('2031-03-15');

    expect($report['axis'])->toBe('due_date')
        ->and($report['as_of'])->toBe('2031-03-15');

    $row = f4PayRow(collect($report['rows'])->firstWhere('supplier_id', (int) $supplier->id));

    // Due 2031-04-14: not yet due → current. Due 2031-01-31: 43 days → 31-60.
    expect($row->current)->toBe(1_431_000)
        ->and($row->days_31_60)->toBe(1_431_000)
        ->and($row->days_1_30)->toBe(0)
        ->and($row->days_61_90)->toBe(0)
        ->and($row->days_90_plus)->toBe(0)
        ->and($row->total)->toBe(2_862_000);
});

// ── Ledger-sourced: lettered items vanish, partials net signed ──────────

it('drops a fully settled (lettered) invoice and nets a partial payment against the position - the source is the unlettered ledger, not the invoice table', function () {
    $fixture = f4PayBaseline('on_payment');
    $supplierPaid = f4PayExemptSupplier();
    $supplierPartial = f4PayExemptSupplier();

    // Fully paid and lettered: contributes NOTHING.
    $paidInvoice = f4PayPostedInvoice($supplierPaid, [f4PayServiceLine($fixture['tax_code'])], ['due_date' => '2031-03-30']);
    ['payment' => $draft] = f4PayRecordDraft($supplierPaid, [
        ['supplier_invoice_id' => (int) $paidInvoice->id, 'amount' => 1_431_000],
    ]);
    f4PayApproveAndPay($draft);

    // Partially paid: invoice credit ages on its due date, the unlettered
    // payment debit nets SIGNED in its own bucket.
    $partialInvoice = f4PayPostedInvoice($supplierPartial, [f4PayServiceLine($fixture['tax_code'])], ['due_date' => '2031-04-14']);
    ['payment' => $partialDraft] = f4PayRecordDraft($supplierPartial, [
        ['supplier_invoice_id' => (int) $partialInvoice->id, 'amount' => 431_000],
    ]);
    f4PayApproveAndPay($partialDraft);

    f4PayAgedViewer();
    $report = app(AgedPayables::class)->handle('2031-03-25');

    $paidRow = collect($report['rows'])->firstWhere('supplier_id', (int) $supplierPaid->id);
    expect($paidRow)->toBeNull();

    $partialRow = f4PayRow(collect($report['rows'])->firstWhere('supplier_id', (int) $supplierPartial->id));
    expect($partialRow->current)->toBe(1_431_000)      // invoice credit, due 2031-04-14
        ->and($partialRow->days_1_30)->toBe(-431_000)  // payment debit, aged on entry date 2031-03-20
        ->and($partialRow->total)->toBe(1_000_000);

    // The report agrees with the unlettered ledger to the franc.
    $ledgerNet = DB::table('journal_entry_lines as l')
        ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
        ->where('l.account_id', f4PayAccountId('401'))
        ->where('l.partner_type', 'supplier')
        ->where('l.partner_id', $supplierPartial->id)
        ->whereNull('l.lettering_id')
        ->whereDate('e.date', '<=', '2031-03-25')
        ->selectRaw('CAST(SUM(l.credit) - SUM(l.debit) AS SIGNED) as net')
        ->value('net');

    expect($partialRow->total)->toBe((int) $ledgerNet);
});
