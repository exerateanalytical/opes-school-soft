<?php

declare(strict_types=1);

use App\Modules\Procurement\Actions\SubmitRequisition;
use App\Modules\Procurement\Domain\ProcurementPermission;
use App\Modules\Procurement\Domain\RequisitionStatus;
use App\Modules\Procurement\Livewire\GoodsReceipts\Index as GoodsReceiptsIndex;
use App\Modules\Procurement\Livewire\PurchaseOrders\Edit as PurchaseOrderEdit;
use App\Modules\Procurement\Livewire\PurchaseOrders\Index as PurchaseOrdersIndex;
use App\Modules\Procurement\Livewire\Requisitions\Index as RequisitionsIndex;
use App\Modules\Procurement\Livewire\Suppliers\Index as SuppliersIndex;
use App\Modules\Procurement\Livewire\Suppliers\Show as SuppliersShow;
use App\Modules\Procurement\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__.'/ProcurementTestHelpers.php';

uses(RefreshDatabase::class);

/*
 * Components are tested DIRECTLY (Livewire::test) - the /procurement route
 * block, navigation entry and Permission enum cases belong to the Phase 5
 * wiring package (F5), which lands after this one.
 */

// ── Authorization boundary: every screen refuses without procurement.view ─

it('blocks each screen for a user without the view permission', function (string $component) {
    f2ProcUser(); // signed in, holds nothing

    Livewire::test($component)->assertForbidden();
})->with([
    SuppliersIndex::class,
    RequisitionsIndex::class,
    PurchaseOrdersIndex::class,
    GoodsReceiptsIndex::class,
]);

it('blocks the PO capture screen without order_manage', function () {
    f2ProcUser(ProcurementPermission::VIEW);

    Livewire::test(PurchaseOrderEdit::class)->assertForbidden();
});

// ── Suppliers ───────────────────────────────────────────────────────────

it('renders the supplier list with rows and KPIs', function () {
    $manager = f2ProcUser(ProcurementPermission::VIEW, ProcurementPermission::SUPPLIER_MANAGE);
    $supplier = f2ProcSupplier(['name' => 'Papeterie du Marche'], $manager);

    Livewire::test(SuppliersIndex::class)
        ->assertSee(__('opes.procurement_screen.suppliers_title'))
        ->assertSee('Papeterie du Marche')
        ->assertSee($supplier->code);
});

it('searches suppliers by NIU', function () {
    $manager = f2ProcUser(ProcurementPermission::VIEW, ProcurementPermission::SUPPLIER_MANAGE);
    f2ProcSupplier(['name' => 'Alpha Fournitures', 'niu' => 'M111222333444A'], $manager);
    f2ProcSupplier(['name' => 'Beta Livres'], $manager);

    Livewire::test(SuppliersIndex::class)
        ->set('search', 'M111222333444A')
        ->assertSee('Alpha Fournitures')
        ->assertDontSee('Beta Livres');
});

it('renders the supplier profile with masked bank details', function () {
    $manager = f2ProcUser(ProcurementPermission::VIEW, ProcurementPermission::SUPPLIER_MANAGE);
    $supplier = f2ProcSupplier(['bank_account_rib' => '10023-00123-45678901234-56'], $manager);

    Livewire::test(SuppliersShow::class, ['supplier' => $supplier->id])
        ->assertSee($supplier->name)
        // The plaintext RIB must NEVER render; the masked tail may.
        ->assertDontSee('10023-00123-45678901234-56')
        ->assertSee('***');
});

// ── Requisitions: the approve queue drives the real Actions ─────────────

it('lists requisitions and submits a draft from the screen', function () {
    $calendar = f2ProcCalendar();
    $requester = f2ProcUser(ProcurementPermission::VIEW);
    $requisition = f2ProcRequisition($requester, $calendar);

    Livewire::test(RequisitionsIndex::class)
        ->assertSee(__('opes.procurement_screen.requisitions_title'))
        ->assertSee($requisition->requisition_no)
        ->call('submit', $requisition->id);

    expect($requisition->refresh()->status)->toBe(RequisitionStatus::Submitted);
});

it('approves from the queue as a separate approver and surfaces budget warnings', function () {
    $calendar = f2ProcCalendar();
    $manager = f2ProcUser(ProcurementPermission::SUPPLIER_MANAGE);
    app(App\Modules\Procurement\Actions\SaveProcurementSettings::class)
        ->handle(['budget_enforcement' => 'warn'], f2ProcActor($manager));

    $requester = f2ProcUser(ProcurementPermission::VIEW);
    $requisition = f2ProcRequisition($requester, $calendar, ['budget_line_id' => 7]);
    app(SubmitRequisition::class)->handle($requisition->id, f2ProcActor($requester));

    f2ProcUser(ProcurementPermission::VIEW, ProcurementPermission::REQUISITION_APPROVE);

    Livewire::test(RequisitionsIndex::class)
        ->call('approve', $requisition->id)
        ->assertSee(__('opes.procurement_screen.budget_warnings'));

    expect($requisition->refresh()->status)->toBe(RequisitionStatus::Approved);
});

// ── Purchase orders ─────────────────────────────────────────────────────

it('renders the PO list with open-commitment KPI', function () {
    $creator = f2ProcUser(
        ProcurementPermission::VIEW,
        ProcurementPermission::ORDER_MANAGE,
        ProcurementPermission::SUPPLIER_MANAGE,
    );
    $supplier = f2ProcSupplier([], $creator);
    $po = f2ProcPurchaseOrder($creator, $supplier, f2ProcCalendar());

    Livewire::test(PurchaseOrdersIndex::class)
        ->assertSee(__('opes.procurement_screen.orders_title'))
        ->assertSee($po->po_no);
});

it('saves a whole PO grid in one request from the capture screen', function () {
    $creator = f2ProcUser(ProcurementPermission::ORDER_MANAGE, ProcurementPermission::SUPPLIER_MANAGE);
    $supplier = f2ProcSupplier([], $creator);
    f2ProcCalendar(); // fiscal + academic year the component will resolve

    $component = Livewire::test(PurchaseOrderEdit::class)
        ->set('supplierId', $supplier->id)
        ->set('orderDate', '2031-03-15')
        ->call('save', [
            ['description' => 'Reams of A4 paper', 'quantity' => '40', 'unit_price_ht' => 3250,
                'discount_rate_bp' => 0, 'expense_account_id' => (string) f2ProcExpenseAccountId()],
            ['description' => '', 'quantity' => '1', 'unit_price_ht' => 0, 'discount_rate_bp' => 0,
                'expense_account_id' => ''], // the blank trailing grid row
        ]);

    /** @var string|null $poNo */
    $poNo = $component->get('savedPoNo');

    expect($poNo)->not->toBeNull();

    /** @var PurchaseOrder $po */
    $po = PurchaseOrder::query()->where('po_no', $poNo)->firstOrFail();

    expect($po->subtotal_ht)->toBe(130_000)
        ->and($po->lines()->count())->toBe(1);
});

// ── Goods receipts ──────────────────────────────────────────────────────

it('renders the receipts list with the discrepancy flag', function () {
    $creator = f2ProcUser(
        ProcurementPermission::VIEW,
        ProcurementPermission::ORDER_MANAGE,
        ProcurementPermission::SUPPLIER_MANAGE,
        ProcurementPermission::ORDER_APPROVE,
    );
    $supplier = f2ProcSupplier([], $creator);
    $calendar = f2ProcCalendar();
    $po = f2ProcPurchaseOrder($creator, $supplier, $calendar);

    $approver = f2ProcUser(
        ProcurementPermission::VIEW,
        ProcurementPermission::ORDER_APPROVE,
        ProcurementPermission::ORDER_MANAGE,
    );
    app(App\Modules\Procurement\Actions\ApprovePurchaseOrder::class)->handle($po->id, f2ProcActor($approver));

    $receipt = app(App\Modules\Procurement\Actions\SaveGoodsReceipt::class)->handle(
        ['supplier_id' => $supplier->id, 'purchase_order_id' => $po->id, 'received_on' => '2031-03-15',
            'academic_year_id' => $calendar['academic_year_id'], 'fiscal_year_id' => $calendar['fiscal_year_id']],
        [['purchase_order_line_id' => $po->lines()->firstOrFail()->id, 'qty_received' => '40',
            'qty_rejected' => '2', 'rejection_reason' => 'Damaged']],
        f2ProcActor($approver),
    );
    app(App\Modules\Procurement\Actions\ConfirmGoodsReceipt::class)->handle($receipt->id, f2ProcActor($approver));

    Livewire::test(GoodsReceiptsIndex::class)
        ->assertSee(__('opes.procurement_screen.receipts_title'))
        ->assertSee($receipt->receipt_no)
        ->assertSee(__('opes.procurement_screen.yes'));
});
