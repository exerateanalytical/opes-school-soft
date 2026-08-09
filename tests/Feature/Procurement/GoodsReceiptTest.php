<?php

declare(strict_types=1);

use App\Modules\Procurement\Actions\ApprovePurchaseOrder;
use App\Modules\Procurement\Actions\ConfirmGoodsReceipt;
use App\Modules\Procurement\Actions\SaveGoodsReceipt;
use App\Modules\Procurement\Actions\SaveProcurementSettings;
use App\Modules\Procurement\Domain\GoodsReceiptStatus;
use App\Modules\Procurement\Domain\ProcurementPermission;
use App\Modules\Procurement\Domain\PurchaseOrderStatus;
use App\Modules\Procurement\Events\GoodsReceived;
use App\Modules\Procurement\Models\GoodsReceipt;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/ProcurementTestHelpers.php';

uses(RefreshDatabase::class);

if (! function_exists('f2ProcApprovedPo')) {
    /**
     * A signed-in receiving clerk (who can also approve and edit settings),
     * a supplier, an APPROVED 40 x 3 250 PO, and the calendar it lives in.
     *
     * @return array{0: App\Modules\Identity\Models\User, 1: Supplier, 2: PurchaseOrder, 3: array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}}
     */
    function f2ProcApprovedPo(): array
    {
        $creator = f2ProcUser(ProcurementPermission::ORDER_MANAGE, ProcurementPermission::SUPPLIER_MANAGE);
        $supplier = f2ProcSupplier([], $creator);
        $calendar = f2ProcCalendar();
        $po = f2ProcPurchaseOrder($creator, $supplier, $calendar);

        $approver = f2ProcUser(
            ProcurementPermission::ORDER_APPROVE,
            ProcurementPermission::ORDER_MANAGE,
            ProcurementPermission::SUPPLIER_MANAGE,
        );
        app(ApprovePurchaseOrder::class)->handle($po->id, f2ProcActor($approver));

        return [$approver, $supplier, $po->refresh(), $calendar];
    }
}

// ── Recording & confirming ──────────────────────────────────────────────

it('records a draft BR and advances the PO line under lock on confirm', function () {
    [$clerk, $supplier, $po, $calendar] = f2ProcApprovedPo();
    $poLine = $po->lines()->firstOrFail();

    $receipt = app(SaveGoodsReceipt::class)->handle(
        ['supplier_id' => $supplier->id, 'purchase_order_id' => $po->id, 'received_on' => '2031-03-15',
            'delivery_note_ref' => 'BL-7841',
            'academic_year_id' => $calendar['academic_year_id'], 'fiscal_year_id' => $calendar['fiscal_year_id']],
        [['purchase_order_line_id' => $poLine->id, 'qty_received' => '15']],
        f2ProcActor($clerk),
    );

    expect($receipt->receipt_no)->toStartWith('BR/2031/')
        ->and($receipt->status)->toBe(GoodsReceiptStatus::Draft)
        // Nothing advanced while draft.
        ->and((string) $poLine->refresh()->qty_received)->toBe('0.000');

    Event::fake([GoodsReceived::class]);
    app(ConfirmGoodsReceipt::class)->handle($receipt->id, f2ProcActor($clerk));

    expect((string) $poLine->refresh()->qty_received)->toBe('15.000')
        ->and($po->refresh()->status)->toBe(PurchaseOrderStatus::PartiallyReceived);

    Event::assertDispatched(GoodsReceived::class, fn (GoodsReceived $e): bool => $e->purchaseOrderId === $po->id);
});

it('flips the PO to received when the last outstanding quantity arrives', function () {
    [$clerk, $supplier, $po, $calendar] = f2ProcApprovedPo();
    $poLine = $po->lines()->firstOrFail();

    foreach (['25', '15'] as $qty) {
        $receipt = app(SaveGoodsReceipt::class)->handle(
            ['supplier_id' => $supplier->id, 'purchase_order_id' => $po->id, 'received_on' => '2031-03-15',
                'academic_year_id' => $calendar['academic_year_id'], 'fiscal_year_id' => $calendar['fiscal_year_id']],
            [['purchase_order_line_id' => $poLine->id, 'qty_received' => $qty]],
            f2ProcActor($clerk),
        );
        app(ConfirmGoodsReceipt::class)->handle($receipt->id, f2ProcActor($clerk));
    }

    expect((string) $poLine->refresh()->qty_received)->toBe('40.000')
        ->and($po->refresh()->status)->toBe(PurchaseOrderStatus::Received);
});

it('posts NOTHING to the ledger on receipt (§4.3)', function () {
    [$clerk, $supplier, $po, $calendar] = f2ProcApprovedPo();
    $poLine = $po->lines()->firstOrFail();
    $entriesBefore = DB::table('journal_entries')->count();

    $receipt = app(SaveGoodsReceipt::class)->handle(
        ['supplier_id' => $supplier->id, 'purchase_order_id' => $po->id, 'received_on' => '2031-03-15',
            'academic_year_id' => $calendar['academic_year_id'], 'fiscal_year_id' => $calendar['fiscal_year_id']],
        [['purchase_order_line_id' => $poLine->id, 'qty_received' => '40']],
        f2ProcActor($clerk),
    );
    app(ConfirmGoodsReceipt::class)->handle($receipt->id, f2ProcActor($clerk));

    expect(DB::table('journal_entries')->count())->toBe($entriesBefore);
});

// ── Invariant 3: the over-receipt tolerance, checked INSIDE the lock ────

it('caps cumulative receiving at the ordered quantity plus tolerance', function () {
    [$clerk, $supplier, $po, $calendar] = f2ProcApprovedPo();
    app(SaveProcurementSettings::class)->handle(
        ['over_receipt_tolerance_bp' => 500], // 5% -> ceiling 42.000
        f2ProcActor($clerk),
    );
    $poLine = $po->lines()->firstOrFail();

    // 40 ordered; receive 39 first.
    $first = app(SaveGoodsReceipt::class)->handle(
        ['supplier_id' => $supplier->id, 'purchase_order_id' => $po->id, 'received_on' => '2031-03-15',
            'academic_year_id' => $calendar['academic_year_id'], 'fiscal_year_id' => $calendar['fiscal_year_id']],
        [['purchase_order_line_id' => $poLine->id, 'qty_received' => '39']],
        f2ProcActor($clerk),
    );
    app(ConfirmGoodsReceipt::class)->handle($first->id, f2ProcActor($clerk));

    expect((string) $poLine->refresh()->qty_received)->toBe('39.000');

    // A SECOND receipt of 4 more (cumulative 43 > 42) refuses - exactly
    // the two-clerks-one-delivery race the FOR UPDATE window exists for:
    // the check runs against the locked, current counter, not the stale
    // value either clerk saw on screen.
    $second = app(SaveGoodsReceipt::class)->handle(
        ['supplier_id' => $supplier->id, 'purchase_order_id' => $po->id, 'received_on' => '2031-03-15',
            'academic_year_id' => $calendar['academic_year_id'], 'fiscal_year_id' => $calendar['fiscal_year_id']],
        [['purchase_order_line_id' => $poLine->id, 'qty_received' => '4']],
        f2ProcActor($clerk),
    );

    expect(fn () => app(ConfirmGoodsReceipt::class)->handle($second->id, f2ProcActor($clerk)))
        ->toThrow(ValidationException::class, 'tolerance');

    // The refused confirmation left NOTHING advanced (transactional).
    expect((string) $poLine->refresh()->qty_received)->toBe('39.000')
        ->and($second->refresh()->status)->toBe(GoodsReceiptStatus::Draft);

    // Receiving exactly to the ceiling (39 + 3 = 42.000) still fits.
    $third = app(SaveGoodsReceipt::class)->handle(
        ['supplier_id' => $supplier->id, 'purchase_order_id' => $po->id, 'received_on' => '2031-03-15',
            'academic_year_id' => $calendar['academic_year_id'], 'fiscal_year_id' => $calendar['fiscal_year_id']],
        [['purchase_order_line_id' => $poLine->id, 'qty_received' => '3']],
        f2ProcActor($clerk),
    );
    app(ConfirmGoodsReceipt::class)->handle($third->id, f2ProcActor($clerk));

    expect((string) $poLine->refresh()->qty_received)->toBe('42.000');
});

it('refuses any over-receipt at zero tolerance', function () {
    [$clerk, $supplier, $po, $calendar] = f2ProcApprovedPo();
    $poLine = $po->lines()->firstOrFail();

    $receipt = app(SaveGoodsReceipt::class)->handle(
        ['supplier_id' => $supplier->id, 'purchase_order_id' => $po->id, 'received_on' => '2031-03-15',
            'academic_year_id' => $calendar['academic_year_id'], 'fiscal_year_id' => $calendar['fiscal_year_id']],
        [['purchase_order_line_id' => $poLine->id, 'qty_received' => '40.001']],
        f2ProcActor($clerk),
    );

    app(ConfirmGoodsReceipt::class)->handle($receipt->id, f2ProcActor($clerk));
})->throws(ValidationException::class);

// ── Discrepancies (§4.3) ────────────────────────────────────────────────

it('requires a reason for rejected quantities and flags the discrepancy', function () {
    [$clerk, $supplier, $po, $calendar] = f2ProcApprovedPo();
    $poLine = $po->lines()->firstOrFail();

    // No reason -> refused at save.
    expect(fn () => app(SaveGoodsReceipt::class)->handle(
        ['supplier_id' => $supplier->id, 'purchase_order_id' => $po->id, 'received_on' => '2031-03-15',
            'academic_year_id' => $calendar['academic_year_id'], 'fiscal_year_id' => $calendar['fiscal_year_id']],
        [['purchase_order_line_id' => $poLine->id, 'qty_received' => '40', 'qty_rejected' => '2']],
        f2ProcActor($clerk),
    ))->toThrow(ValidationException::class);

    // The §4.4 worked-example receipt: 40 delivered, 38 accepted, 2 damaged.
    $receipt = app(SaveGoodsReceipt::class)->handle(
        ['supplier_id' => $supplier->id, 'purchase_order_id' => $po->id, 'received_on' => '2031-03-15',
            'academic_year_id' => $calendar['academic_year_id'], 'fiscal_year_id' => $calendar['fiscal_year_id']],
        [['purchase_order_line_id' => $poLine->id, 'qty_received' => '40', 'qty_rejected' => '2',
            'rejection_reason' => 'Two reams water-damaged']],
        f2ProcActor($clerk),
    );
    app(ConfirmGoodsReceipt::class)->handle($receipt->id, f2ProcActor($clerk));

    expect($receipt->refresh()->has_discrepancy)->toBeTrue()
        // Only the ACCEPTED 38 advance the PO line.
        ->and((string) $poLine->refresh()->qty_received)->toBe('38.000');

    /** @var App\Modules\Procurement\Models\GoodsReceiptLine $line */
    $line = $receipt->lines()->firstOrFail();
    expect((string) $line->qty_accepted)->toBe('38.000')
        ->and((string) $line->qty_rejected)->toBe('2.000');
});

it('enforces accepted + rejected = received at the DATABASE', function () {
    [$clerk, $supplier, $po, $calendar] = f2ProcApprovedPo();

    $receipt = app(SaveGoodsReceipt::class)->handle(
        ['supplier_id' => $supplier->id, 'purchase_order_id' => $po->id, 'received_on' => '2031-03-15',
            'academic_year_id' => $calendar['academic_year_id'], 'fiscal_year_id' => $calendar['fiscal_year_id']],
        [['purchase_order_line_id' => $po->lines()->firstOrFail()->id, 'qty_received' => '10']],
        f2ProcActor($clerk),
    );

    expect(fn () => DB::table('goods_receipt_lines')
        ->where('goods_receipt_id', $receipt->id)
        ->update(['qty_accepted' => 9]))
        ->toThrow(Illuminate\Database\QueryException::class);
});

// ── §9 lifecycle & immutability ─────────────────────────────────────────

it('freezes a confirmed receipt and blocks its deletion at both layers', function () {
    [$clerk, $supplier, $po, $calendar] = f2ProcApprovedPo();

    $receipt = app(SaveGoodsReceipt::class)->handle(
        ['supplier_id' => $supplier->id, 'purchase_order_id' => $po->id, 'received_on' => '2031-03-15',
            'academic_year_id' => $calendar['academic_year_id'], 'fiscal_year_id' => $calendar['fiscal_year_id']],
        [['purchase_order_line_id' => $po->lines()->firstOrFail()->id, 'qty_received' => '10']],
        f2ProcActor($clerk),
    );
    app(ConfirmGoodsReceipt::class)->handle($receipt->id, f2ProcActor($clerk));
    $receipt->refresh();

    // Editing the confirmed document throws...
    $receipt->delivery_note_ref = 'rewritten';
    expect(fn () => $receipt->save())->toThrow(RuntimeException::class, 'immutable');

    // ...double-confirm refuses...
    expect(fn () => app(ConfirmGoodsReceipt::class)->handle($receipt->id, f2ProcActor($clerk)))
        ->toThrow(ValidationException::class);

    // ...and deletion dies at the DB trigger even through raw SQL.
    expect(fn () => DB::table('goods_receipts')->where('id', $receipt->id)->delete())
        ->toThrow(Illuminate\Database\QueryException::class, 'only be deleted while draft');
});

it('deletes a draft receipt with its lines', function () {
    [$clerk, $supplier, $po, $calendar] = f2ProcApprovedPo();

    $receipt = app(SaveGoodsReceipt::class)->handle(
        ['supplier_id' => $supplier->id, 'purchase_order_id' => $po->id, 'received_on' => '2031-03-15',
            'academic_year_id' => $calendar['academic_year_id'], 'fiscal_year_id' => $calendar['fiscal_year_id']],
        [['purchase_order_line_id' => $po->lines()->firstOrFail()->id, 'qty_received' => '10']],
        f2ProcActor($clerk),
    );
    $receiptId = $receipt->id;

    GoodsReceipt::query()->findOrFail($receiptId)->delete();

    expect(DB::table('goods_receipts')->where('id', $receiptId)->exists())->toBeFalse()
        ->and(DB::table('goods_receipt_lines')->where('goods_receipt_id', $receiptId)->exists())->toBeFalse();
});

it('refuses receiving against a draft (unapproved) purchase order', function () {
    $creator = f2ProcUser(ProcurementPermission::ORDER_MANAGE, ProcurementPermission::SUPPLIER_MANAGE);
    $supplier = f2ProcSupplier([], $creator);
    $calendar = f2ProcCalendar();
    $po = f2ProcPurchaseOrder($creator, $supplier, $calendar); // still draft

    app(SaveGoodsReceipt::class)->handle(
        ['supplier_id' => $supplier->id, 'purchase_order_id' => $po->id, 'received_on' => '2031-03-15',
            'academic_year_id' => $calendar['academic_year_id'], 'fiscal_year_id' => $calendar['fiscal_year_id']],
        [['purchase_order_line_id' => $po->lines()->firstOrFail()->id, 'qty_received' => '10']],
        f2ProcActor($creator),
    );
})->throws(ValidationException::class);
