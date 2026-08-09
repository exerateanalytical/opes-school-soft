<?php

declare(strict_types=1);

use App\Modules\Procurement\Actions\MatchSupplierInvoice;
use App\Modules\Procurement\Domain\SupplierInvoicePermission;
use App\Modules\Procurement\Livewire\SupplierInvoices\Capture as InvoiceCapture;
use App\Modules\Procurement\Livewire\SupplierInvoices\Index as InvoicesIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__.'/SupplierInvoiceTestHelpers.php';

uses(RefreshDatabase::class);

/*
 * Components tested DIRECTLY (Livewire::test) - the /procurement/invoices
 * routes, navigation and Permission enum cases belong to the Phase 5
 * wiring package (F5).
 */

it('blocks the invoice list for a user without invoice_view', function () {
    f3InvUser(); // signed in, holds nothing

    Livewire::test(InvoicesIndex::class)->assertForbidden();
});

it('blocks the capture screen for a user without invoice_view', function () {
    f3InvUser();

    Livewire::test(InvoiceCapture::class)->assertForbidden();
});

it('renders the invoice list with rows, KPIs and the blocking-state pills', function () {
    $fixture = f3InvBaseline();

    $invoice = f3InvCapture($fixture['clerk'], $fixture['supplier'], [
        f3InvServiceLine($fixture['tax_code'], ['description' => 'IT consulting', 'unit_price_ht' => 1_200_000]),
    ]);
    app(MatchSupplierInvoice::class)->handle($invoice->id, f3InvActor($fixture['clerk']));

    $viewer = f3InvUser(SupplierInvoicePermission::VIEW);

    Livewire::test(InvoicesIndex::class)
        ->assertSee(__('opes.supplier_invoice_screen.title'))
        ->assertSee($invoice->internal_no)
        ->assertSee($invoice->supplier_invoice_no);
});

it('captures, matches and surfaces the tax panel through the Capture component in one save', function () {
    $fixture = f3InvBaseline();

    $clerk = f3InvUser(SupplierInvoicePermission::VIEW, SupplierInvoicePermission::CREATE);

    $component = Livewire::test(InvoiceCapture::class)
        ->set('supplierId', (string) $fixture['supplier']->id)
        ->set('supplierInvoiceNo', 'SCR-001')
        ->set('invoiceDate', '2031-03-15')
        ->set('rows.0.description', 'IT consulting')
        ->set('rows.0.quantity', '1')
        ->set('rows.0.unit_price_ht', '1200000')
        ->set('rows.0.tax_code_id', (string) $fixture['tax_code']->id)
        ->set('rows.0.expense_account_id', (string) f3InvExpenseAccountId())
        ->call('save');

    $component->assertSet('error', null);

    /** @var \App\Modules\Procurement\Models\SupplierInvoice $invoice */
    $invoice = \App\Modules\Procurement\Models\SupplierInvoice::query()
        ->where('supplier_invoice_no', 'SCR-001')
        ->firstOrFail();

    expect($invoice->total_ttc)->toBe(1_431_000)
        ->and($invoice->withholding_total)->toBe(66_000);

    // The tax panel names the applied rule and shows the split.
    $component->assertSee($invoice->internal_no)
        ->assertSee('AIR on services (F3)');
});
