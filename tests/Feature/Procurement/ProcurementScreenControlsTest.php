<?php

declare(strict_types=1);

use App\Modules\Procurement\Domain\SupplierInvoicePermission;
use App\Modules\Procurement\Domain\SupplierPaymentPermission;
use App\Modules\Procurement\Livewire\Payments\Index as PaymentsIndex;
use App\Modules\Procurement\Livewire\SupplierInvoices\Index as SupplierInvoicesIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__.'/ProcurementTestHelpers.php';

uses(RefreshDatabase::class);

/*
 * Controls that rendered but did nothing. x-list-screen always draws a
 * Reset beside the filter bar and calls `resetFilters`; the payments
 * worklist never defined it, so the button raised a method-missing error.
 */

it('resets the payment worklist filters from the list-screen Reset control', function (): void {
    f2ProcUser(SupplierPaymentPermission::RECORD);

    Livewire::test(PaymentsIndex::class)
        ->set('search', 'BON/2026')
        ->set('status', 'draft')
        ->set('page', 3)
        ->call('resetFilters')
        ->assertHasNoErrors()
        ->assertSet('search', '')
        ->assertSet('status', '')
        ->assertSet('page', 1);
});

it('offers every payment status in the worklist filter', function (): void {
    f2ProcUser(SupplierPaymentPermission::RECORD);

    Livewire::test(PaymentsIndex::class)
        ->assertViewHas('statusOptions', ['draft', 'approved', 'paid', 'voided']);
});

/*
 * The invoice filter listed eight statuses by hand out of the enum's ten,
 * so an invoice sitting in `pending_match` or `disputed` could not be
 * filtered for at all.
 */
it('offers every supplier-invoice status in the filter, including pending_match and disputed', function (): void {
    f2ProcUser(SupplierInvoicePermission::VIEW);

    $component = Livewire::test(SupplierInvoicesIndex::class);

    $options = $component->viewData('statusOptions');

    expect($options)->toContain('pending_match')
        ->and($options)->toContain('disputed')
        ->and($options)->toHaveCount(10);
});
