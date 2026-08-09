<?php

declare(strict_types=1);

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Procurement\Actions\ApproveSupplierInvoice;
use App\Modules\Procurement\Actions\IssueSupplierCreditNote;
use App\Modules\Procurement\Actions\MatchSupplierInvoice;
use App\Modules\Procurement\Actions\PostSupplierInvoice;
use App\Modules\Procurement\Domain\SupplierCreditNoteStatus;
use App\Modules\Procurement\Domain\SupplierInvoicePermission;
use App\Modules\Procurement\Models\SupplierInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/SupplierInvoiceTestHelpers.php';

uses(RefreshDatabase::class);

if (! function_exists('f3InvPostedInvoice')) {
    /**
     * A POSTED 40 x 3 250 goods-free invoice (exempt supplier, no
     * withholding noise) plus a user armed to credit it.
     *
     * @return array{invoice: SupplierInvoice, user: \App\Modules\Identity\Models\User, tax_code: \App\Modules\Tax\Models\TaxCode}
     */
    function f3InvPostedInvoice(): array
    {
        $fixture = f3InvBaseline();
        $supplier = f3InvSupplier(['is_withholding_exempt' => true, 'withholding_exemption_ref' => 'EXO-CN-01']);

        $clerk = f3InvUser(SupplierInvoicePermission::CREATE);
        $invoice = f3InvCapture($clerk, $supplier, [
            f3InvServiceLine($fixture['tax_code'], [
                'description' => 'Reams of A4 paper',
                'quantity' => '40',
                'unit_price_ht' => 3_250,
            ]),
        ]);
        app(MatchSupplierInvoice::class)->handle($invoice->id, f3InvActor($clerk));

        $user = f3InvUser(
            SupplierInvoicePermission::CREATE,
            SupplierInvoicePermission::APPROVE,
            SupplierInvoicePermission::APPROVE_UNMATCHED,
            \App\Modules\Identity\Domain\Permission::LedgerPost->value,
        );
        app(ApproveSupplierInvoice::class)->handle($invoice->id, f3InvActor($user), 'below PO threshold');
        $invoice = app(PostSupplierInvoice::class)->handle($invoice->id, f3InvActor($user));

        return ['invoice' => $invoice, 'user' => $user, 'tax_code' => $fixture['tax_code']];
    }
}

// ── Issue against an invoice (§4.8) ─────────────────────────────────────

it('issues an AVF credit note mirroring the invoice line and posts the reversal Dr payable / Cr expense + TVA', function () {
    $result = f3InvPostedInvoice();
    $invoice = $result['invoice'];
    $invoiceLine = $invoice->lines()->firstOrFail();

    $creditNote = app(IssueSupplierCreditNote::class)->handle(
        [
            'original_invoice_id' => $invoice->id,
            'reason_type' => 'quantity_correction',
            'reason_note' => '2 reams damaged in transit',
            'credit_note_date' => '2031-03-20',
        ],
        [[
            'supplier_invoice_line_id' => (int) $invoiceLine->id,
            'quantity' => '2',
            'unit_price_ht' => 3_250,
        ]],
        f3InvActor($result['user']),
    );

    expect($creditNote->credit_note_no)->toMatch('/^AVF\/2031\/\d{6}$/')
        ->and($creditNote->status)->toBe(SupplierCreditNoteStatus::Issued)
        ->and($creditNote->supplier_id)->toBe($invoice->supplier_id)
        ->and($creditNote->subtotal_ht)->toBe(6_500)
        ->and($creditNote->journal_entry_id)->not->toBeNull();

    // The mirrored snapshot: same tax code, same expense account, same
    // applied rate as the ORIGINAL line (§4.8).
    $creditLine = $creditNote->lines()->firstOrFail();
    expect($creditLine->tax_code_id)->toBe($invoiceLine->tax_code_id)
        ->and($creditLine->expense_account_id)->toBe($invoiceLine->expense_account_id)
        ->and($creditLine->tax_rate_bp_applied)->toBe($invoiceLine->tax_rate_bp_applied)
        ->and($creditLine->deductible_tax_amount + $creditLine->non_deductible_tax_amount)->toBe($creditLine->tax_amount);

    /** @var JournalEntry $entry */
    $entry = JournalEntry::query()->findOrFail($creditNote->journal_entry_id);

    expect($entry->total_debit)->toBe($entry->total_credit)
        ->and($entry->total_debit)->toBe($creditNote->total_ttc);

    // Dr the payable (with the supplier partner), Cr the expense.
    $payableLine = $entry->lines()->where('account_id', $invoice->payable_account_id)->firstOrFail();
    expect((int) $payableLine->debit)->toBe($creditNote->total_ttc)
        ->and($payableLine->partner_type?->value)->toBe('supplier')
        ->and($payableLine->partner_id)->toBe($invoice->supplier_id);

    $expenseLine = $entry->lines()->where('account_id', $invoiceLine->expense_account_id)->firstOrFail();
    expect((int) $expenseLine->credit)->toBe(6_500);
});

// ── Over-credit refused under lock (§4.8) ───────────────────────────────

it('refuses Σ credit notes above the invoiced TTC', function () {
    $result = f3InvPostedInvoice();
    $invoice = $result['invoice'];
    $invoiceLine = $invoice->lines()->firstOrFail();

    // First avoir: the full 40 reams.
    app(IssueSupplierCreditNote::class)->handle(
        [
            'original_invoice_id' => $invoice->id,
            'reason_type' => 'cancellation',
            'reason_note' => 'full delivery returned to the supplier',
            'credit_note_date' => '2031-03-20',
        ],
        [[
            'supplier_invoice_line_id' => (int) $invoiceLine->id,
            'quantity' => '40',
            'unit_price_ht' => 3_250,
        ]],
        f3InvActor($result['user']),
    );

    // A second franc over the invoice must refuse.
    app(IssueSupplierCreditNote::class)->handle(
        [
            'original_invoice_id' => $invoice->id,
            'reason_type' => 'rebate',
            'reason_note' => 'one more franc than was ever invoiced',
            'credit_note_date' => '2031-03-21',
        ],
        [[
            'supplier_invoice_line_id' => (int) $invoiceLine->id,
            'quantity' => '1',
            'unit_price_ht' => 3_250,
        ]],
        f3InvActor($result['user']),
    );
})->throws(DomainException::class, 'exceeding');

it('refuses a credit note against an unposted invoice', function () {
    $fixture = f3InvBaseline();
    $supplier = f3InvSupplier(['is_withholding_exempt' => true, 'withholding_exemption_ref' => 'EXO-CN-02']);

    $clerk = f3InvUser(SupplierInvoicePermission::CREATE);
    $draft = f3InvCapture($clerk, $supplier, [f3InvServiceLine($fixture['tax_code'])]);

    app(IssueSupplierCreditNote::class)->handle(
        [
            'original_invoice_id' => $draft->id,
            'reason_type' => 'return',
            'reason_note' => 'credit attempted against a draft',
            'credit_note_date' => '2031-03-20',
        ],
        [['supplier_invoice_line_id' => (int) $draft->lines()->firstOrFail()->id, 'quantity' => '1', 'unit_price_ht' => 1_000]],
        f3InvActor($clerk),
    );
})->throws(DomainException::class, 'POSTED');

// ── Standalone credit note (annual rebate) ──────────────────────────────

it('issues a standalone rebate credit note with its own snapshot at credit_note_date', function () {
    $result = f3InvPostedInvoice();

    $creditNote = app(IssueSupplierCreditNote::class)->handle(
        [
            'supplier_id' => $result['invoice']->supplier_id,
            'reason_type' => 'rebate',
            'reason_note' => 'annual volume rebate 2031',
            'credit_note_date' => '2031-03-25',
        ],
        [[
            'description' => 'Annual volume rebate',
            'amount_ht' => 500_000,
            'tax_code_id' => (int) $result['tax_code']->id,
            'expense_account_id' => f3InvExpenseAccountId(),
        ]],
        f3InvActor($result['user']),
    );

    expect($creditNote->original_invoice_id)->toBeNull()
        ->and($creditNote->subtotal_ht)->toBe(500_000)
        // 19.25% snapshotted at the credit note's own date.
        ->and($creditNote->tax_total)->toBe(96_250)
        ->and($creditNote->lines()->firstOrFail()->tax_rate_bp_applied)->toBe(19_250);
});

// ── Immutability and deletion (§9) ──────────────────────────────────────

it('freezes an issued credit note and refuses its deletion at the trigger', function () {
    $result = f3InvPostedInvoice();
    $invoiceLine = $result['invoice']->lines()->firstOrFail();

    $creditNote = app(IssueSupplierCreditNote::class)->handle(
        [
            'original_invoice_id' => $result['invoice']->id,
            'reason_type' => 'price_correction',
            'reason_note' => 'agreed price was 3 200 per ream',
            'credit_note_date' => '2031-03-22',
        ],
        [['supplier_invoice_line_id' => (int) $invoiceLine->id, 'quantity' => '2', 'unit_price_ht' => 3_250]],
        f3InvActor($result['user']),
    );

    $creditNote->subtotal_ht = 1;
    expect(fn () => $creditNote->save())->toThrow(RuntimeException::class, 'immutable');

    $fresh = $creditNote->refresh();
    expect(fn () => DB::table('supplier_credit_notes')->where('id', $fresh->id)->delete())
        ->toThrow(Illuminate\Database\QueryException::class);
});
