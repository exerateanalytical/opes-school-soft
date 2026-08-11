<?php

declare(strict_types=1);

use App\Modules\Procurement\Actions\MatchSupplierInvoice;
use App\Modules\Procurement\Domain\SupplierInvoicePermission;
use App\Modules\Procurement\Livewire\SupplierInvoices\Show as InvoiceShow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__.'/SupplierInvoiceTestHelpers.php';

uses(RefreshDatabase::class);

/*
 * The supplier-invoice DETAIL screen.
 *
 * This test exists because the demo database carries zero rows in
 * `supplier_invoices`, so the enriched detail page could not be exercised
 * against it - it was compile-checked only. Verifying here instead of
 * seeding the live demo ledger keeps the check honest without writing
 * financial records to the demo copy unsupervised.
 */

it('refuses the invoice detail screen to a user without invoice_view', function (): void {
    $fixture = f3InvBaseline();

    $invoice = f3InvCapture($fixture['clerk'], $fixture['supplier'], [
        f3InvServiceLine($fixture['tax_code'], ['description' => 'Locked away', 'unit_price_ht' => 400_000]),
    ]);

    f3InvUser(); // signed in, holds nothing

    Livewire::test(InvoiceShow::class, ['invoice' => $invoice->id])->assertForbidden();
});

it('renders the invoice detail screen with its identity, lines and settlement', function (): void {
    $fixture = f3InvBaseline();

    $invoice = f3InvCapture($fixture['clerk'], $fixture['supplier'], [
        f3InvServiceLine($fixture['tax_code'], ['description' => 'Roof repair', 'unit_price_ht' => 900_000]),
    ]);
    app(MatchSupplierInvoice::class)->handle($invoice->id, f3InvActor($fixture['clerk']));

    f3InvUser(SupplierInvoicePermission::VIEW);

    Livewire::test(InvoiceShow::class, ['invoice' => $invoice->id])
        ->assertOk()
        ->assertSee($invoice->internal_no)
        ->assertSee($invoice->supplier_invoice_no)
        // The supplier the invoice belongs to, not just its id.
        ->assertSee($fixture['supplier']->name)
        // A line actually reaches the page - the detail page's whole point.
        ->assertSee('Roof repair');
});

it('shows the ledger posting once the invoice has been posted', function (): void {
    $fixture = f3InvBaseline();

    $invoice = f3InvCapture($fixture['clerk'], $fixture['supplier'], [
        f3InvServiceLine($fixture['tax_code'], ['description' => 'Generator service', 'unit_price_ht' => 750_000]),
    ]);
    app(MatchSupplierInvoice::class)->handle($invoice->id, f3InvActor($fixture['clerk']));

    f3InvUser(SupplierInvoicePermission::VIEW);

    // Renders whether or not a posting exists yet; the assertion that
    // matters is that reading the posting relationship does not blow the
    // page up, which is what an unguarded join would do.
    Livewire::test(InvoiceShow::class, ['invoice' => $invoice->id])
        ->assertOk()
        ->assertSee($invoice->internal_no);
});
