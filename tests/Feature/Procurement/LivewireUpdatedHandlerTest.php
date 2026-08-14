<?php

declare(strict_types=1);

use App\Modules\Procurement\Domain\ProcurementPermission;
use App\Modules\Procurement\Domain\SupplierInvoicePermission;
use App\Modules\Procurement\Livewire\GoodsReceipts\Index as GoodsReceiptsIndex;
use App\Modules\Procurement\Livewire\PurchaseOrders\Index as PurchaseOrdersIndex;
use App\Modules\Procurement\Livewire\SupplierInvoices\Index as SupplierInvoicesIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/ProcurementTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * The three crashes in the 2026-08-13 bugs audit share ONE cause:
 * DB::table() returns a Query builder, which has no whereKey(); Laravel's
 * dynamic where{Column} magic rewrites it to `where 'key' = ?`. These tests
 * exercise the presentation layer the Action-layer suites never touch, which
 * is the band every one of the seven crashes lives in - `startAmend` had
 * literally no test anywhere in tests/ before this file.
 *
 * Fixtures come from ProcurementTestHelpers rather than raw inserts: these
 * tables carry NOT NULL foreign keys to chart_of_accounts, academic_years,
 * fiscal_years and users, so a hand-rolled insert would fail on the schema
 * long before it reached the bug.
 */
it('opens the amend form on a purchase order instead of 500-ing', function (): void {
    $user = f2ProcUser(
        ProcurementPermission::VIEW,
        ProcurementPermission::ORDER_APPROVE,
        ProcurementPermission::ORDER_MANAGE,
        ProcurementPermission::SUPPLIER_MANAGE,
    );
    $calendar = f2ProcCalendar();
    $supplier = f2ProcSupplier([], $user);
    $po = f2ProcPurchaseOrder($user, $supplier, $calendar);

    Livewire::test(PurchaseOrdersIndex::class)
        ->call('startAmend', (int) $po->id)
        ->assertHasNoErrors()
        ->assertSet('amendingId', (int) $po->id);
});

it('fills the supplier when a purchase order is picked on a goods receipt', function (): void {
    $user = f2ProcUser(
        ProcurementPermission::VIEW,
        ProcurementPermission::ORDER_MANAGE,
        ProcurementPermission::SUPPLIER_MANAGE,
    );
    $calendar = f2ProcCalendar();
    $supplier = f2ProcSupplier([], $user);
    $po = f2ProcPurchaseOrder($user, $supplier, $calendar);

    Livewire::test(GoodsReceiptsIndex::class)
        ->set('formPurchaseOrderId', (int) $po->id)
        ->assertSet('formSupplierId', (int) $supplier->id);
});

it('fills the supplier when an invoice is picked for a credit note', function (): void {
    $user = f2ProcUser(
        ProcurementPermission::VIEW,
        ProcurementPermission::SUPPLIER_MANAGE,
        SupplierInvoicePermission::VIEW,
        SupplierInvoicePermission::CREATE,
    );
    $calendar = f2ProcCalendar();
    $supplier = f2ProcSupplier([], $user);

    $invoiceId = (int) DB::table('supplier_invoices')->insertGetId([
        'internal_no' => 'FF/2026/000001',
        'supplier_invoice_no' => 'INV-1',
        'supplier_id' => $supplier->id,
        'invoice_date' => '2031-03-01',
        'received_date' => '2031-03-02',
        'value_date' => '2031-03-01',
        'due_date' => '2031-04-01',
        'payable_account_id' => f2ProcPayableAccountId(),
        'status' => 'posted',
        'created_by' => $user->id,
        'academic_year_id' => $calendar['academic_year_id'],
        'fiscal_year_id' => $calendar['fiscal_year_id'],
        'accounting_period_id' => $calendar['accounting_period_id'],
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(SupplierInvoicesIndex::class)
        ->set('creditNoteInvoiceId', $invoiceId)
        ->assertSet('creditNoteSupplierId', (int) $supplier->id);
});
