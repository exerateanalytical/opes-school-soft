<?php

declare(strict_types=1);

use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Procurement\Actions\ApproveSupplierInvoice;
use App\Modules\Procurement\Actions\CancelSupplierInvoice;
use App\Modules\Procurement\Actions\MatchSupplierInvoice;
use App\Modules\Procurement\Actions\PostSupplierInvoice;
use App\Modules\Procurement\Domain\SupplierInvoicePermission;
use App\Modules\Procurement\Domain\SupplierInvoiceStatus;
use App\Modules\Procurement\Models\SupplierInvoice;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/SupplierInvoiceTestHelpers.php';

uses(RefreshDatabase::class);

// ── Money conservation (§11 obligation 1) ───────────────────────────────

it('conserves every franc across random line sets: Σ HT + Σ tax = TTC and deductible + non-deductible = tax per line', function () {
    $fixture = f3InvBaseline();

    foreach (range(1, 4) as $round) {
        $lines = [];
        $lineCount = random_int(1, 6);

        foreach (range(1, $lineCount) as $i) {
            $lines[] = [
                'description' => "Random line {$round}.{$i}",
                'quantity' => (string) random_int(1, 40),
                'unit_price_ht' => random_int(101, 4_999_999),
                'discount_rate_bp' => random_int(0, 2_000),
                'tax_code_id' => (int) $fixture['tax_code']->id,
                'expense_account_id' => f3InvExpenseAccountId(),
            ];
        }

        $invoice = f3InvCapture($fixture['clerk'], $fixture['supplier'], $lines);

        $rows = $invoice->lines()->get();

        $sumHt = (int) $rows->sum('amount_ht');
        $sumTax = (int) $rows->sum('tax_amount');
        $sumWithheld = (int) $rows->sum('withholding_amount');

        expect($invoice->subtotal_ht)->toBe($sumHt)
            ->and($invoice->tax_total)->toBe($sumTax)
            ->and($invoice->total_ttc)->toBe($sumHt + $sumTax)
            ->and($invoice->withholding_total)->toBe($sumWithheld)
            ->and($invoice->net_payable)->toBe($invoice->total_ttc - $invoice->withholding_total);

        foreach ($rows as $row) {
            expect($row->deductible_tax_amount + $row->non_deductible_tax_amount)->toBe($row->tax_amount);
        }
    }
});

// ── Duplicate control at the DATABASE (§11 obligation 5) ────────────────

it('rejects a second invoice with the same (supplier_id, supplier_invoice_no) at the database, not only in validation', function () {
    $fixture = f3InvBaseline();

    $first = f3InvCapture($fixture['clerk'], $fixture['supplier'], [f3InvServiceLine($fixture['tax_code'])], [
        'supplier_invoice_no' => 'DUP-001',
    ]);

    // Straight SQL, bypassing every Action-level check: the UNIQUE
    // constraint itself must refuse.
    expect(fn () => DB::table('supplier_invoices')->insert([
        'internal_no' => 'FF/2031/999999',
        'supplier_invoice_no' => 'DUP-001',
        'supplier_id' => $fixture['supplier']->id,
        'invoice_date' => '2031-03-15',
        'received_date' => '2031-03-15',
        'value_date' => '2031-03-15',
        'due_date' => '2031-04-14',
        'payable_account_id' => $first->payable_account_id,
        'status' => 'draft',
        'match_status' => 'not_required',
        'created_by' => $fixture['clerk']->id,
        'academic_year_id' => $first->academic_year_id,
        'fiscal_year_id' => $first->fiscal_year_id,
        'accounting_period_id' => $first->accounting_period_id,
        'net_payable' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('refuses the duplicate with a friendly message before the constraint fires', function () {
    $fixture = f3InvBaseline();

    f3InvCapture($fixture['clerk'], $fixture['supplier'], [f3InvServiceLine($fixture['tax_code'])], [
        'supplier_invoice_no' => 'DUP-002',
    ]);

    f3InvCapture($fixture['clerk'], $fixture['supplier'], [f3InvServiceLine($fixture['tax_code'])], [
        'supplier_invoice_no' => 'DUP-002',
    ]);
})->throws(Illuminate\Validation\ValidationException::class);

// ── Capex payable family (§3.3) ─────────────────────────────────────────

it('refuses a capitalised line against an operating (401) payable', function () {
    $fixture = f3InvBaseline();

    f3InvCapture($fixture['clerk'], $fixture['supplier'], [
        f3InvServiceLine($fixture['tax_code'], [
            'description' => 'Minibus',
            'is_capitalised' => true,
            'asset_category_id' => 1,
            'expense_account_id' => f3InvCapexAccountId(),
            'unit_price_ht' => 25_000_000,
        ]),
    ]);
})->throws(DomainException::class, '481');

it('refuses a capitalised line that names no asset category', function () {
    $fixture = f3InvBaseline();

    f3InvCapture($fixture['clerk'], $fixture['supplier'], [
        f3InvServiceLine($fixture['tax_code'], [
            'is_capitalised' => true,
            'expense_account_id' => f3InvCapexAccountId(),
        ]),
    ], ['payable_account_id' => f3InvAccountId('4812')]);
})->throws(Illuminate\Validation\ValidationException::class);

// ── Segregation of duties (§11 obligation 14) ───────────────────────────

it('blocks the creator from approving their own invoice even with the permission', function () {
    $fixture = f3InvBaseline();
    // The clerk holds BOTH permissions - the check is identity, not
    // ability.
    $clerk = f3InvUser(
        SupplierInvoicePermission::CREATE,
        SupplierInvoicePermission::APPROVE,
        SupplierInvoicePermission::APPROVE_UNMATCHED,
    );

    $invoice = f3InvCapture($clerk, $fixture['supplier'], [f3InvServiceLine($fixture['tax_code'])]);
    app(MatchSupplierInvoice::class)->handle($invoice->id, f3InvActor($clerk));

    app(ApproveSupplierInvoice::class)->handle($invoice->id, f3InvActor($clerk), 'chalk purchase below PO threshold');
})->throws(DomainException::class, 'cannot approve');

// ── Mixed invoice: payable split at posting (§3.3) ──────────────────────

it('splits a mixed invoice into one balanced entry per payable family, each payable leg carrying the supplier partner', function () {
    $fixture = f3InvBaseline();
    // Exempt supplier: this test isolates the payable split from
    // withholding.
    $supplier = f3InvSupplier(['is_withholding_exempt' => true, 'withholding_exemption_ref' => 'EXO-2031-01']);

    $clerk = f3InvUser(SupplierInvoicePermission::CREATE);
    $invoice = f3InvCapture($clerk, $supplier, [
        f3InvServiceLine($fixture['tax_code'], [
            'description' => 'Box of chalk',
            'unit_price_ht' => 100_000,
        ]),
        f3InvServiceLine($fixture['tax_code'], [
            'description' => 'Minibus',
            'unit_price_ht' => 25_000_000,
            'is_capitalised' => true,
            'asset_category_id' => 7,
            'expense_account_id' => f3InvCapexAccountId(),
        ]),
    ], ['payable_account_id' => f3InvAccountId('4812')]);

    app(MatchSupplierInvoice::class)->handle($invoice->id, f3InvActor($clerk));

    $approver = f3InvUser(
        SupplierInvoicePermission::APPROVE,
        SupplierInvoicePermission::APPROVE_UNMATCHED,
        \App\Modules\Identity\Domain\Permission::LedgerPost->value,
    );
    app(ApproveSupplierInvoice::class)->handle($invoice->id, f3InvActor($approver), 'direct purchase, no PO required');

    $invoice = app(PostSupplierInvoice::class)->handle($invoice->id, f3InvActor($approver));

    expect($invoice->status)->toBe(SupplierInvoiceStatus::Posted)
        ->and($invoice->journal_entry_id)->not->toBeNull()
        ->and($invoice->secondary_journal_entry_id)->not->toBeNull();

    /** @var JournalEntry $capexEntry */
    $capexEntry = JournalEntry::query()->findOrFail($invoice->journal_entry_id);
    /** @var JournalEntry $opexEntry */
    $opexEntry = JournalEntry::query()->findOrFail($invoice->secondary_journal_entry_id);

    // Each entry balances on its own.
    expect($capexEntry->total_debit)->toBe($capexEntry->total_credit)
        ->and($opexEntry->total_debit)->toBe($opexEntry->total_credit);

    $capexPayable = $capexEntry->lines()->where('account_id', f3InvAccountId('4812'))->firstOrFail();
    $opexPayable = $opexEntry->lines()->where('account_id', f3InvAccountId('401'))->firstOrFail();

    // §3.3: one payable line per family, BOTH carrying the same supplier
    // partner - never a single line spanning both.
    expect($capexPayable->partner_type?->value)->toBe('supplier')
        ->and($capexPayable->partner_id)->toBe($supplier->id)
        ->and($opexPayable->partner_type?->value)->toBe('supplier')
        ->and($opexPayable->partner_id)->toBe($supplier->id);

    // The two payable credits together carry the full TTC.
    expect((int) $capexPayable->credit + (int) $opexPayable->credit)->toBe($invoice->total_ttc);
});

// ── Closed period: forward-post keeping value_date (§4.5 inv. 5) ────────

it('forward-posts a late invoice into the first open period, keeping value_date', function () {
    $fixture = f3InvBaseline();
    $supplier = f3InvSupplier(['is_withholding_exempt' => true, 'withholding_exemption_ref' => 'EXO-2031-02']);

    $clerk = f3InvUser(SupplierInvoicePermission::CREATE);
    $invoice = f3InvCapture($clerk, $supplier, [f3InvServiceLine($fixture['tax_code'])]);
    app(MatchSupplierInvoice::class)->handle($invoice->id, f3InvActor($clerk));

    $approver = f3InvUser(
        SupplierInvoicePermission::APPROVE,
        SupplierInvoicePermission::APPROVE_UNMATCHED,
        \App\Modules\Identity\Domain\Permission::LedgerPost->value,
    );
    app(ApproveSupplierInvoice::class)->handle($invoice->id, f3InvActor($approver), 'below PO threshold');

    // April opens, then March hard-locks: the invoice's own period is now
    // closed.
    AccountingPeriod::factory()->create([
        'fiscal_year_id' => $fixture['calendar']['fiscal_year_id'],
        'period_month' => '2031-04-01',
        'starts_on' => '2031-04-01',
        'ends_on' => '2031-04-30',
        'status' => \App\Modules\Accounting\Domain\AccountingPeriodStatus::Open,
    ]);
    DB::table('accounting_periods')
        ->where('id', $fixture['calendar']['accounting_period_id'])
        ->update(['status' => 'hard_locked']);

    $invoice = app(PostSupplierInvoice::class)->handle($invoice->id, f3InvActor($approver));

    /** @var JournalEntry $entry */
    $entry = JournalEntry::query()->findOrFail($invoice->journal_entry_id);

    expect($entry->date->toDateString())->toBe('2031-04-01')
        // 02-accounting C4: the ECONOMIC date survives on the invoice.
        ->and($invoice->value_date)->toBe('2031-03-15')
        ->and((string) DB::table('accounting_periods')->where('id', $invoice->accounting_period_id)->value('period_month'))
        ->toStartWith('2031-04');
});

// ── Deletion and cancellation (§9) ──────────────────────────────────────

it('refuses to delete a non-draft invoice at the database trigger', function () {
    $fixture = f3InvBaseline();

    $invoice = f3InvCapture($fixture['clerk'], $fixture['supplier'], [f3InvServiceLine($fixture['tax_code'])]);
    app(MatchSupplierInvoice::class)->handle($invoice->id, f3InvActor($fixture['clerk']));

    // Straight SQL DELETE, bypassing the model observer entirely.
    expect(fn () => DB::table('supplier_invoices')->where('id', $invoice->id)->delete())
        ->toThrow(QueryException::class);
});

it('cancels a posted invoice by reversing every entry it produced', function () {
    $fixture = f3InvBaseline();
    $supplier = f3InvSupplier(['is_withholding_exempt' => true, 'withholding_exemption_ref' => 'EXO-2031-03']);

    $clerk = f3InvUser(SupplierInvoicePermission::CREATE);
    $invoice = f3InvCapture($clerk, $supplier, [f3InvServiceLine($fixture['tax_code'])]);
    app(MatchSupplierInvoice::class)->handle($invoice->id, f3InvActor($clerk));

    $approver = f3InvUser(
        SupplierInvoicePermission::APPROVE,
        SupplierInvoicePermission::APPROVE_UNMATCHED,
        SupplierInvoicePermission::CREATE,
        \App\Modules\Identity\Domain\Permission::LedgerPost->value,
    );
    app(ApproveSupplierInvoice::class)->handle($invoice->id, f3InvActor($approver), 'below PO threshold');
    $invoice = app(PostSupplierInvoice::class)->handle($invoice->id, f3InvActor($approver));

    $invoice = app(CancelSupplierInvoice::class)->handle($invoice->id, 'captured against the wrong supplier', f3InvActor($approver));

    expect($invoice->status)->toBe(SupplierInvoiceStatus::Cancelled);

    /** @var JournalEntry $entry */
    $entry = JournalEntry::query()->findOrFail($invoice->journal_entry_id);
    expect($entry->reversed_by_entry_id)->not->toBeNull();
});

// ── Migrated invoices never re-post (02-accounting H) ───────────────────

it('refuses to post a migrated invoice', function () {
    $fixture = f3InvBaseline();
    $supplier = f3InvSupplier(['is_withholding_exempt' => true, 'withholding_exemption_ref' => 'EXO-2031-04']);

    $clerk = f3InvUser(SupplierInvoicePermission::CREATE);
    $invoice = f3InvCapture($clerk, $supplier, [f3InvServiceLine($fixture['tax_code'])], ['is_migration' => true]);
    app(MatchSupplierInvoice::class)->handle($invoice->id, f3InvActor($clerk));

    $approver = f3InvUser(
        SupplierInvoicePermission::APPROVE,
        SupplierInvoicePermission::APPROVE_UNMATCHED,
        \App\Modules\Identity\Domain\Permission::LedgerPost->value,
    );
    app(ApproveSupplierInvoice::class)->handle($invoice->id, f3InvActor($approver), 'migration cut-over document');

    app(PostSupplierInvoice::class)->handle($invoice->id, f3InvActor($approver));
})->throws(DomainException::class, 'migrated');

// ── Empty tax substrate blocks capture (§11.16) ─────────────────────────

it('refuses to capture while TaxSettings.withholding_recognition is unconfirmed', function () {
    $calendar = f3InvCalendar();
    f3InvIdentity();
    // NO tax_settings row at all.
    f3InvConfirmedProrata($calendar['fiscal_year_id']);

    $supplier = f3InvSupplier();
    $taxCode = f3InvInputTaxCode();
    $clerk = f3InvUser(SupplierInvoicePermission::CREATE);

    f3InvCapture($clerk, $supplier, [f3InvServiceLine($taxCode)]);
})->throws(DomainException::class, 'withholding_recognition');

it('immutably freezes a posted invoice via the model observer', function () {
    $fixture = f3InvBaseline();
    $supplier = f3InvSupplier(['is_withholding_exempt' => true, 'withholding_exemption_ref' => 'EXO-2031-05']);

    $clerk = f3InvUser(SupplierInvoicePermission::CREATE);
    $invoice = f3InvCapture($clerk, $supplier, [f3InvServiceLine($fixture['tax_code'])]);
    app(MatchSupplierInvoice::class)->handle($invoice->id, f3InvActor($clerk));

    $approver = f3InvUser(
        SupplierInvoicePermission::APPROVE,
        SupplierInvoicePermission::APPROVE_UNMATCHED,
        \App\Modules\Identity\Domain\Permission::LedgerPost->value,
    );
    app(ApproveSupplierInvoice::class)->handle($invoice->id, f3InvActor($approver), 'below PO threshold');
    $invoice = app(PostSupplierInvoice::class)->handle($invoice->id, f3InvActor($approver));

    /** @var SupplierInvoice $fresh */
    $fresh = SupplierInvoice::query()->findOrFail($invoice->id);
    $fresh->subtotal_ht = 1;

    expect(fn () => $fresh->save())->toThrow(RuntimeException::class, 'immutable');
});
