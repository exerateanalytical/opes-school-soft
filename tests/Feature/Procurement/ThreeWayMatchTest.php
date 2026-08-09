<?php

declare(strict_types=1);

use App\Modules\Procurement\Actions\ApprovePurchaseOrder;
use App\Modules\Procurement\Actions\ApproveSupplierInvoice;
use App\Modules\Procurement\Actions\ConfirmGoodsReceipt;
use App\Modules\Procurement\Actions\CreatePurchaseOrder;
use App\Modules\Procurement\Actions\MatchSupplierInvoice;
use App\Modules\Procurement\Actions\OverrideMatchException;
use App\Modules\Procurement\Actions\SaveGoodsReceipt;
use App\Modules\Procurement\Actions\SaveProcurementSettings;
use App\Modules\Procurement\Domain\MatchStatus;
use App\Modules\Procurement\Domain\ProcurementPermission;
use App\Modules\Procurement\Domain\SupplierInvoicePermission;
use App\Modules\Procurement\Domain\SupplierInvoiceStatus;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\Supplier;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/SupplierInvoiceTestHelpers.php';

uses(RefreshDatabase::class);

if (! function_exists('f3InvThreeWayFixture')) {
    /**
     * The §4.4 worked-example substrate: goods PO of 40 reams at 3 250 HT,
     * receipt of 38 accepted / 2 rejected, tolerances 200 bp / 100 FCFA,
     * receipts required for goods.
     *
     * @return array{clerk: User, supplier: Supplier, po: PurchaseOrder, po_line_id: int, tax_code: \App\Modules\Tax\Models\TaxCode}
     */
    function f3InvThreeWayFixture(): array
    {
        $fixture = f3InvBaseline();

        $buyer = f3InvUser(
            ProcurementPermission::ORDER_MANAGE,
            ProcurementPermission::SUPPLIER_MANAGE,
        );

        app(SaveProcurementSettings::class)->handle([
            'receipt_required_for_goods' => true,
            'price_tolerance_bp' => 200,
            'price_tolerance_absolute' => 100,
            'quantity_tolerance_bp' => 0,
        ], f3InvActor($buyer));

        $supplier = f3InvSupplier(['is_withholding_exempt' => true, 'withholding_exemption_ref' => 'EXO-3W-01'], $buyer);

        $po = app(CreatePurchaseOrder::class)->handle(
            [
                'supplier_id' => $supplier->id,
                'order_date' => '2031-03-10',
                'academic_year_id' => f3InvCalendarShared()['academic_year_id'],
                'fiscal_year_id' => f3InvCalendarShared()['fiscal_year_id'],
            ],
            [
                [
                    'description' => 'Reams of A4 paper',
                    'quantity' => '40',
                    'unit_price_ht' => 3_250,
                    'expense_account_id' => f3InvExpenseAccountId(),
                    // A GOODS line: the inventory reference is what routes it
                    // into the three-way mode.
                    'inventory_item_id' => 1,
                ],
            ],
            f3InvActor($buyer),
        );

        $approver = f3InvUser(
            ProcurementPermission::ORDER_APPROVE,
            ProcurementPermission::ORDER_MANAGE,
        );
        app(ApprovePurchaseOrder::class)->handle($po->id, f3InvActor($approver));

        $poLine = $po->lines()->firstOrFail();

        // Receipt: 38 accepted, 2 rejected (damaged).
        $receipt = app(SaveGoodsReceipt::class)->handle(
            [
                'supplier_id' => $supplier->id,
                'purchase_order_id' => $po->id,
                'received_on' => '2031-03-12',
                'academic_year_id' => f3InvCalendarShared()['academic_year_id'],
                'fiscal_year_id' => f3InvCalendarShared()['fiscal_year_id'],
            ],
            [[
                'purchase_order_line_id' => $poLine->id,
                'qty_received' => '40',
                'qty_rejected' => '2',
                'rejection_reason' => 'damaged',
            ]],
            f3InvActor($approver),
        );
        app(ConfirmGoodsReceipt::class)->handle($receipt->id, f3InvActor($approver));

        $clerk = f3InvUser(SupplierInvoicePermission::CREATE, SupplierInvoicePermission::VIEW);

        return [
            'clerk' => $clerk,
            'supplier' => $supplier,
            'po' => $po->refresh(),
            'po_line_id' => (int) $poLine->id,
            'tax_code' => $fixture['tax_code'],
        ];
    }
}

if (! function_exists('f3InvCalendarShared')) {
    /**
     * The calendar rows f3InvBaseline() built, re-read instead of rebuilt
     * so every document in a test shares ONE 2031 calendar.
     *
     * @return array{fiscal_year_id: int, academic_year_id: int}
     */
    function f3InvCalendarShared(): array
    {
        return [
            'fiscal_year_id' => (int) \Illuminate\Support\Facades\DB::table('fiscal_years')->orderByDesc('id')->value('id'),
            'academic_year_id' => (int) \Illuminate\Support\Facades\DB::table('academic_years')->orderByDesc('id')->value('id'),
        ];
    }
}

// ── The §4.4 worked example, exact ──────────────────────────────────────

it('produces exactly the two named exceptions of the §4.4 worked example and blocks approval', function () {
    $fixture = f3InvThreeWayFixture();

    // Invoice: 40 reams at 3 400 = 136 000.
    $invoice = f3InvCapture($fixture['clerk'], $fixture['supplier'], [
        [
            'description' => 'Reams of A4 paper',
            'quantity' => '40',
            'unit_price_ht' => 3_400,
            'tax_code_id' => (int) $fixture['tax_code']->id,
            'expense_account_id' => f3InvExpenseAccountId(),
            'purchase_order_line_id' => $fixture['po_line_id'],
            'nature' => 'goods',
        ],
    ], ['purchase_order_id' => $fixture['po']->id]);

    $invoice = app(MatchSupplierInvoice::class)->handle($invoice->id, f3InvActor($fixture['clerk']));

    expect($invoice->status)->toBe(SupplierInvoiceStatus::MatchException)
        ->and($invoice->match_status)->toBe(MatchStatus::Exception);

    $line = $invoice->lines()->firstOrFail();

    // Quantity: invoiced 40 vs accepted 38 → over by 2 (quantity_variance).
    // Price: 3 400 vs 3 250 → +150, past both the 200 bp (65) and the
    // absolute 100 tolerance (price_variance stored on the same line).
    expect($line->match_status)->toBe(MatchStatus::Exception)
        ->and($line->match_exception_reason)->toBe('quantity_variance')
        ->and((string) $line->quantity_variance)->toBe('2.000')
        ->and($line->price_variance)->toBe(150)
        ->and((string) $line->matched_qty)->toBe('38.000');

    // Approval is BLOCKED - even a fully-armed approver cannot approve a
    // match_exception invoice.
    $approver = f3InvUser(
        SupplierInvoicePermission::APPROVE,
        SupplierInvoicePermission::APPROVE_UNMATCHED,
    );

    expect(fn () => app(ApproveSupplierInvoice::class)->handle($invoice->id, f3InvActor($approver)))
        ->toThrow(DomainException::class);
});

it('matches within tolerance: 38 reams at 3 250 passes clean', function () {
    $fixture = f3InvThreeWayFixture();

    $invoice = f3InvCapture($fixture['clerk'], $fixture['supplier'], [
        [
            'description' => 'Reams of A4 paper',
            'quantity' => '38',
            'unit_price_ht' => 3_250,
            'tax_code_id' => (int) $fixture['tax_code']->id,
            'expense_account_id' => f3InvExpenseAccountId(),
            'purchase_order_line_id' => $fixture['po_line_id'],
            'nature' => 'goods',
        ],
    ], ['purchase_order_id' => $fixture['po']->id]);

    $invoice = app(MatchSupplierInvoice::class)->handle($invoice->id, f3InvActor($fixture['clerk']));

    expect($invoice->status)->toBe(SupplierInvoiceStatus::PendingApproval)
        ->and($invoice->match_status)->toBe(MatchStatus::Matched)
        ->and($invoice->lines()->firstOrFail()->match_status)->toBe(MatchStatus::Matched);
});

it('flags no_receipt when a three-way goods line has nothing received', function () {
    $fixture = f3InvBaseline();

    $buyer = f3InvUser(ProcurementPermission::ORDER_MANAGE, ProcurementPermission::SUPPLIER_MANAGE);
    app(SaveProcurementSettings::class)->handle(['receipt_required_for_goods' => true], f3InvActor($buyer));
    $supplier = f3InvSupplier(['is_withholding_exempt' => true, 'withholding_exemption_ref' => 'EXO-3W-02'], $buyer);

    $po = app(CreatePurchaseOrder::class)->handle(
        [
            'supplier_id' => $supplier->id,
            'order_date' => '2031-03-10',
            'academic_year_id' => f3InvCalendarShared()['academic_year_id'],
            'fiscal_year_id' => f3InvCalendarShared()['fiscal_year_id'],
        ],
        [[
            'description' => 'Projectors',
            'quantity' => '5',
            'unit_price_ht' => 400_000,
            'expense_account_id' => f3InvExpenseAccountId(),
            'inventory_item_id' => 2,
        ]],
        f3InvActor($buyer),
    );
    $approver = f3InvUser(ProcurementPermission::ORDER_APPROVE);
    app(ApprovePurchaseOrder::class)->handle($po->id, f3InvActor($approver));

    $clerk = f3InvUser(SupplierInvoicePermission::CREATE);
    $invoice = f3InvCapture($clerk, $supplier, [
        [
            'description' => 'Projectors',
            'quantity' => '5',
            'unit_price_ht' => 400_000,
            'tax_code_id' => (int) $fixture['tax_code']->id,
            'expense_account_id' => f3InvExpenseAccountId(),
            'purchase_order_line_id' => (int) $po->lines()->firstOrFail()->id,
            'nature' => 'goods',
        ],
    ], ['purchase_order_id' => $po->id]);

    $invoice = app(MatchSupplierInvoice::class)->handle($invoice->id, f3InvActor($clerk));

    expect($invoice->status)->toBe(SupplierInvoiceStatus::MatchException)
        ->and($invoice->lines()->firstOrFail()->match_exception_reason)->toBe('no_receipt');
});

it('flags supplier_mismatch when the invoice supplier is not the PO supplier', function () {
    $fixture = f3InvThreeWayFixture();
    $otherSupplier = f3InvSupplier(['is_withholding_exempt' => true, 'withholding_exemption_ref' => 'EXO-3W-03']);

    $clerk = f3InvUser(SupplierInvoicePermission::CREATE);
    $invoice = f3InvCapture($clerk, $otherSupplier, [
        [
            'description' => 'Reams of A4 paper',
            'quantity' => '38',
            'unit_price_ht' => 3_250,
            'tax_code_id' => (int) $fixture['tax_code']->id,
            'expense_account_id' => f3InvExpenseAccountId(),
            'purchase_order_line_id' => $fixture['po_line_id'],
            'nature' => 'goods',
        ],
    ], ['purchase_order_id' => $fixture['po']->id]);

    $invoice = app(MatchSupplierInvoice::class)->handle($invoice->id, f3InvActor($clerk));

    expect($invoice->lines()->firstOrFail()->match_exception_reason)->toBe('supplier_mismatch');
});

// ── Override: recorded, permissioned, evidence preserved (§4.4) ─────────

it('overrides a match exception with reason recorded, then approval proceeds', function () {
    $fixture = f3InvThreeWayFixture();

    $invoice = f3InvCapture($fixture['clerk'], $fixture['supplier'], [
        [
            'description' => 'Reams of A4 paper',
            'quantity' => '40',
            'unit_price_ht' => 3_400,
            'tax_code_id' => (int) $fixture['tax_code']->id,
            'expense_account_id' => f3InvExpenseAccountId(),
            'purchase_order_line_id' => $fixture['po_line_id'],
            'nature' => 'goods',
        ],
    ], ['purchase_order_id' => $fixture['po']->id]);
    app(MatchSupplierInvoice::class)->handle($invoice->id, f3InvActor($fixture['clerk']));

    // Without the permission: refused by the gate.
    $unarmed = f3InvUser(SupplierInvoicePermission::APPROVE);
    expect(fn () => app(OverrideMatchException::class)->handle($invoice->id, 'price rise agreed by phone', f3InvActor($unarmed)))
        ->toThrow(Illuminate\Auth\Access\AuthorizationException::class);

    $overrider = f3InvUser(SupplierInvoicePermission::OVERRIDE_MATCH, SupplierInvoicePermission::APPROVE);
    $invoice = app(OverrideMatchException::class)->handle($invoice->id, 'price rise agreed by phone with the bursar', f3InvActor($overrider));

    expect($invoice->match_status)->toBe(MatchStatus::Overridden)
        ->and($invoice->status)->toBe(SupplierInvoiceStatus::PendingApproval)
        ->and($invoice->match_override_reason)->toBe('price rise agreed by phone with the bursar')
        ->and($invoice->match_override_by)->toBe($overrider->id)
        // The evidence survives the override.
        ->and($invoice->lines()->firstOrFail()->price_variance)->toBe(150);

    $invoice = app(ApproveSupplierInvoice::class)->handle($invoice->id, f3InvActor($overrider));

    expect($invoice->status)->toBe(SupplierInvoiceStatus::Approved);
});
