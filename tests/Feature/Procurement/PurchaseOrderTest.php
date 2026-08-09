<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Procurement\Actions\AmendPurchaseOrder;
use App\Modules\Procurement\Actions\ApprovePurchaseOrder;
use App\Modules\Procurement\Actions\ApproveRequisition;
use App\Modules\Procurement\Actions\CancelPurchaseOrder;
use App\Modules\Procurement\Actions\CreatePurchaseOrder;
use App\Modules\Procurement\Actions\SaveProcurementSettings;
use App\Modules\Procurement\Actions\SendPurchaseOrder;
use App\Modules\Procurement\Actions\SubmitRequisition;
use App\Modules\Procurement\Domain\ProcurementPermission;
use App\Modules\Procurement\Domain\PurchaseOrderStatus;
use App\Modules\Procurement\Domain\RequisitionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/ProcurementTestHelpers.php';

uses(RefreshDatabase::class);

// ── Creation & the invariant-1 rounding ─────────────────────────────────

it('allocates BC numbers, computes line amounts once and sums the header', function () {
    $creator = f2ProcUser(ProcurementPermission::ORDER_MANAGE, ProcurementPermission::SUPPLIER_MANAGE);
    $supplier = f2ProcSupplier([], $creator);
    $calendar = f2ProcCalendar();

    $po = f2ProcPurchaseOrder($creator, $supplier, $calendar, [], [
        // 12.5 x 3999 x (1 - 250bp) = 48 737.8125 -> 48 738 half-up, ONCE.
        ['description' => 'Exercise books', 'quantity' => '12.500', 'unit_price_ht' => 3_999,
            'discount_rate_bp' => 250, 'expense_account_id' => f2ProcExpenseAccountId()],
        ['description' => 'Chalk boxes', 'quantity' => '3', 'unit_price_ht' => 1_000,
            'expense_account_id' => f2ProcExpenseAccountId()],
    ]);

    expect($po->po_no)->toStartWith('BC/2031/')
        ->and($po->status)->toBe(PurchaseOrderStatus::Draft)
        ->and((int) $po->lines()->where('line_no', 1)->value('amount_ht'))->toBe(48_738)
        ->and($po->subtotal_ht)->toBe(48_738 + 3_000)
        ->and($po->total_ttc)->toBe($po->subtotal_ht + $po->tax_total);
});

it('advances qty_ordered and flips the requisition when ordering from it', function () {
    $calendar = f2ProcCalendar();
    $requester = f2ProcUser(ProcurementPermission::VIEW);
    $requisition = f2ProcRequisition($requester, $calendar);
    app(SubmitRequisition::class)->handle($requisition->id, f2ProcActor($requester));
    $approver = f2ProcUser(ProcurementPermission::REQUISITION_APPROVE);
    app(ApproveRequisition::class)->handle($requisition->id, f2ProcActor($approver));

    $creator = f2ProcUser(ProcurementPermission::ORDER_MANAGE, ProcurementPermission::SUPPLIER_MANAGE);
    $supplier = f2ProcSupplier([], $creator);
    $reqLine = $requisition->lines()->firstOrFail();

    $po = f2ProcPurchaseOrder($creator, $supplier, $calendar, ['requisition_id' => $requisition->id], [
        ['description' => 'Reams of A4 paper', 'quantity' => '40', 'unit_price_ht' => 3_250,
            'requisition_line_id' => $reqLine->id, 'expense_account_id' => f2ProcExpenseAccountId()],
    ]);

    expect($po->requisition_id)->toBe($requisition->id)
        ->and((string) $reqLine->refresh()->qty_ordered)->toBe('40.000')
        ->and($requisition->refresh()->status)->toBe(RequisitionStatus::Ordered);

    // Over-ordering the same line again refuses inside the lock.
    expect(fn () => f2ProcPurchaseOrder($creator, $supplier, $calendar, ['requisition_id' => $requisition->id], [
        ['description' => 'Reams again', 'quantity' => '1', 'unit_price_ht' => 3_250,
            'requisition_line_id' => $reqLine->id, 'expense_account_id' => f2ProcExpenseAccountId()],
    ]))->toThrow(ValidationException::class);
});

// ── Approval: SoD + threshold routing ───────────────────────────────────

it('refuses the creator approving their own PO', function () {
    $creator = f2ProcUser(
        ProcurementPermission::ORDER_MANAGE,
        ProcurementPermission::ORDER_APPROVE,
        ProcurementPermission::SUPPLIER_MANAGE,
    );
    $supplier = f2ProcSupplier([], $creator);
    $po = f2ProcPurchaseOrder($creator, $supplier, f2ProcCalendar());

    app(ApprovePurchaseOrder::class)->handle($po->id, f2ProcActor($creator));
})->throws(ValidationException::class, 'segregation of duties');

it('routes approval through the threshold bands by role', function () {
    (new Database\Seeders\RolePermissionSeeder)->run();
    $creator = f2ProcUser(ProcurementPermission::ORDER_MANAGE, ProcurementPermission::SUPPLIER_MANAGE);
    app(SaveProcurementSettings::class)->handle([], f2ProcActor($creator), [
        ['min_amount' => 0, 'max_amount' => 1_000_000, 'required_role' => Role::Bursar->value, 'sequence' => 1],
        ['min_amount' => 1_000_001, 'max_amount' => null, 'required_role' => Role::Principal->value, 'sequence' => 2],
    ]);

    $supplier = f2ProcSupplier([], $creator);
    $calendar = f2ProcCalendar();

    // A 5,000,000 order: permission alone is NOT enough...
    $big = f2ProcPurchaseOrder($creator, $supplier, $calendar, [], [
        ['description' => 'Minibus deposit', 'quantity' => '1', 'unit_price_ht' => 5_000_000,
            'expense_account_id' => f2ProcExpenseAccountId()],
    ]);

    $bursar = f2ProcUser(ProcurementPermission::ORDER_APPROVE);
    $bursar->assignRole(Role::Bursar->value);

    expect(fn () => app(ApprovePurchaseOrder::class)->handle($big->id, f2ProcActor($bursar)))
        ->toThrow(ValidationException::class);

    // ...the Principal's band it is.
    $principal = f2ProcUser(ProcurementPermission::ORDER_APPROVE);
    $principal->assignRole(Role::Principal->value);
    $approved = app(ApprovePurchaseOrder::class)->handle($big->id, f2ProcActor($principal));

    expect($approved->status)->toBe(PurchaseOrderStatus::Approved);

    // The small order stops at the Bursar's band. (Gate::authorize reads
    // the SIGNED-IN user, so each step acts as the person doing it.)
    actingAs($creator);
    $small = f2ProcPurchaseOrder($creator, $supplier, $calendar);

    actingAs($bursar);
    $approvedSmall = app(ApprovePurchaseOrder::class)->handle($small->id, f2ProcActor($bursar));

    expect($approvedSmall->status)->toBe(PurchaseOrderStatus::Approved);
});

// ── Invariant 5: immutability -> amendment ──────────────────────────────

it('freezes an approved PO - direct edits throw at the model layer', function () {
    $creator = f2ProcUser(ProcurementPermission::ORDER_MANAGE, ProcurementPermission::SUPPLIER_MANAGE);
    $supplier = f2ProcSupplier([], $creator);
    $po = f2ProcPurchaseOrder($creator, $supplier, f2ProcCalendar());

    $approver = f2ProcUser(ProcurementPermission::ORDER_APPROVE);
    app(ApprovePurchaseOrder::class)->handle($po->id, f2ProcActor($approver));

    $po->refresh();
    $po->subtotal_ht = 1;

    expect(fn () => $po->save())->toThrow(RuntimeException::class, 'immutable');

    // The line is frozen too.
    $line = $po->lines()->firstOrFail();
    $line->unit_price_ht = 1;

    expect(fn () => $line->save())->toThrow(RuntimeException::class, 'immutable');
});

it('changes an approved PO ONLY through an amendment, snapshotting the prior lines', function () {
    $creator = f2ProcUser(ProcurementPermission::ORDER_MANAGE, ProcurementPermission::SUPPLIER_MANAGE);
    $supplier = f2ProcSupplier([], $creator);
    $po = f2ProcPurchaseOrder($creator, $supplier, f2ProcCalendar());

    $approver = f2ProcUser(ProcurementPermission::ORDER_APPROVE);
    app(ApprovePurchaseOrder::class)->handle($po->id, f2ProcActor($approver));
    $po->refresh();

    // Reason is mandatory.
    expect(fn () => app(AmendPurchaseOrder::class)->handle($po->id, '  ', [
        ['line_no' => 1, 'description' => 'Reams of A4 paper', 'quantity' => '40', 'unit_price_ht' => 3_400,
            'expense_account_id' => f2ProcExpenseAccountId()],
    ], $po->version, f2ProcActor($approver)))->toThrow(ValidationException::class);

    $amended = app(AmendPurchaseOrder::class)->handle($po->id, 'Supplier price change agreed', [
        ['line_no' => 1, 'description' => 'Reams of A4 paper', 'quantity' => '40', 'unit_price_ht' => 3_400,
            'expense_account_id' => f2ProcExpenseAccountId()],
    ], $po->version, f2ProcActor($approver));

    expect($amended->subtotal_ht)->toBe(136_000)
        ->and($amended->version)->toBe($po->version + 1)
        ->and($amended->amendments()->count())->toBe(1);

    /** @var App\Modules\Procurement\Models\PurchaseOrderAmendment $amendment */
    $amendment = $amended->amendments()->firstOrFail();

    expect($amendment->previous_subtotal_ht)->toBe(130_000)
        ->and($amendment->previous_lines[0]['unit_price_ht'])->toBe(3_250)
        ->and($amendment->reason)->toBe('Supplier price change agreed');

    // A stale version refuses (optimistic lock, 00-core 10.6).
    expect(fn () => app(AmendPurchaseOrder::class)->handle($amended->id, 'Stale edit', [
        ['line_no' => 1, 'description' => 'x', 'quantity' => '40', 'unit_price_ht' => 3_500,
            'expense_account_id' => f2ProcExpenseAccountId()],
    ], $po->version, f2ProcActor($approver)))->toThrow(ValidationException::class);
});

// ── Invariant 6: a PO posts NOTHING ─────────────────────────────────────

it('leaves the ledger completely untouched across the whole PO lifecycle', function () {
    $creator = f2ProcUser(ProcurementPermission::ORDER_MANAGE, ProcurementPermission::SUPPLIER_MANAGE);
    $supplier = f2ProcSupplier([], $creator);
    $calendar = f2ProcCalendar();

    $entriesBefore = DB::table('journal_entries')->count();
    $linesBefore = DB::table('journal_entry_lines')->count();

    $po = f2ProcPurchaseOrder($creator, $supplier, $calendar);
    $approver = f2ProcUser(ProcurementPermission::ORDER_APPROVE, ProcurementPermission::ORDER_MANAGE);
    app(ApprovePurchaseOrder::class)->handle($po->id, f2ProcActor($approver));
    app(SendPurchaseOrder::class)->handle($po->id, f2ProcActor($approver));

    expect(DB::table('journal_entries')->count())->toBe($entriesBefore)
        ->and(DB::table('journal_entry_lines')->count())->toBe($linesBefore);
});

// ── §9 lifecycle ────────────────────────────────────────────────────────

it('deletes only drafts; the DB trigger blocks everything else', function () {
    $creator = f2ProcUser(ProcurementPermission::ORDER_MANAGE, ProcurementPermission::SUPPLIER_MANAGE);
    $supplier = f2ProcSupplier([], $creator);
    $calendar = f2ProcCalendar();

    $draft = f2ProcPurchaseOrder($creator, $supplier, $calendar);
    $draftId = $draft->id;
    $draft->delete();
    expect(DB::table('purchase_orders')->where('id', $draftId)->exists())->toBeFalse();

    $po = f2ProcPurchaseOrder($creator, $supplier, $calendar);
    $approver = f2ProcUser(ProcurementPermission::ORDER_APPROVE);
    app(ApprovePurchaseOrder::class)->handle($po->id, f2ProcActor($approver));

    expect(fn () => DB::table('purchase_orders')->where('id', $po->id)->delete())
        ->toThrow(Illuminate\Database\QueryException::class, 'only be deleted while draft');
});

it('cancels an approved PO with no fulfilment, refuses once anything was received', function () {
    $creator = f2ProcUser(ProcurementPermission::ORDER_MANAGE, ProcurementPermission::SUPPLIER_MANAGE);
    $supplier = f2ProcSupplier([], $creator);
    $calendar = f2ProcCalendar();
    $po = f2ProcPurchaseOrder($creator, $supplier, $calendar);
    $approver = f2ProcUser(ProcurementPermission::ORDER_APPROVE, ProcurementPermission::ORDER_MANAGE);
    app(ApprovePurchaseOrder::class)->handle($po->id, f2ProcActor($approver));

    $cancelled = app(CancelPurchaseOrder::class)->handle($po->id, f2ProcActor($approver));
    expect($cancelled->status)->toBe(PurchaseOrderStatus::Cancelled);

    // Receive against a second PO, then attempt cancellation.
    $po2 = f2ProcPurchaseOrder($creator, $supplier, $calendar);
    app(ApprovePurchaseOrder::class)->handle($po2->id, f2ProcActor($approver));
    $poLine = $po2->lines()->firstOrFail();

    $receipt = app(\App\Modules\Procurement\Actions\SaveGoodsReceipt::class)->handle(
        ['supplier_id' => $supplier->id, 'purchase_order_id' => $po2->id, 'received_on' => '2031-03-15',
            'academic_year_id' => $calendar['academic_year_id'], 'fiscal_year_id' => $calendar['fiscal_year_id']],
        [['purchase_order_line_id' => $poLine->id, 'qty_received' => '10']],
        f2ProcActor($approver),
    );
    app(\App\Modules\Procurement\Actions\ConfirmGoodsReceipt::class)->handle($receipt->id, f2ProcActor($approver));

    expect(fn () => app(CancelPurchaseOrder::class)->handle($po2->id, f2ProcActor($approver)))
        ->toThrow(ValidationException::class);
});
