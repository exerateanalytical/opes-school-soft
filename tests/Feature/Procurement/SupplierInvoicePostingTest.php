<?php

declare(strict_types=1);

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Procurement\Actions\ApproveSupplierInvoice;
use App\Modules\Procurement\Actions\MatchSupplierInvoice;
use App\Modules\Procurement\Actions\PostSupplierInvoice;
use App\Modules\Procurement\Domain\SupplierInvoicePermission;
use App\Modules\Procurement\Models\SupplierInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/SupplierInvoiceTestHelpers.php';

uses(RefreshDatabase::class);

if (! function_exists('f3InvPostReady')) {
    /**
     * Capture + match + approve one invoice and hand back the poster.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $header
     * @return array{invoice: SupplierInvoice, poster: \App\Modules\Identity\Models\User}
     */
    function f3InvPostReady(\App\Modules\Procurement\Models\Supplier $supplier, array $lines, array $header = []): array
    {
        $clerk = f3InvUser(SupplierInvoicePermission::CREATE);
        $invoice = f3InvCapture($clerk, $supplier, $lines, $header);
        app(MatchSupplierInvoice::class)->handle($invoice->id, f3InvActor($clerk));

        $poster = f3InvUser(
            SupplierInvoicePermission::APPROVE,
            SupplierInvoicePermission::APPROVE_UNMATCHED,
            \App\Modules\Identity\Domain\Permission::LedgerPost->value,
        );
        app(ApproveSupplierInvoice::class)->handle($invoice->id, f3InvActor($poster), 'below PO threshold');

        return ['invoice' => $invoice->refresh(), 'poster' => $poster];
    }
}

// ── §5.4 worked example: the prorata split posts to the franc ───────────

it('posts the §5.4 fuel example to the franc: Dr 4 000 000 / Dr 90 244 déductible / Dr 679 756 non déductible / Cr 4 770 000', function () {
    $fixture = f3InvBaseline('on_invoice', 11_720);
    $supplier = f3InvSupplier(['is_withholding_exempt' => true, 'withholding_exemption_ref' => 'EXO-PST-01']);

    $expenseAccountId = f3InvExpenseAccountId();
    $deductibleAccountId = (int) $fixture['tax_code']->deductible_account_id;
    $nonDeductibleAccountId = (int) $fixture['tax_code']->non_deductible_expense_account_id;

    $ready = f3InvPostReady($supplier, [
        f3InvServiceLine($fixture['tax_code'], [
            'description' => 'Generator fuel',
            'unit_price_ht' => 4_000_000,
        ]),
    ]);

    $invoice = app(PostSupplierInvoice::class)->handle($ready['invoice']->id, f3InvActor($ready['poster']));

    // The stored split first (§5.4 mechanics rule 2).
    $line = $invoice->lines()->firstOrFail();
    expect($line->tax_amount)->toBe(770_000)
        ->and($line->deductible_tax_amount)->toBe(90_244)
        ->and($line->non_deductible_tax_amount)->toBe(679_756);

    /** @var JournalEntry $entry */
    $entry = JournalEntry::query()->findOrFail($invoice->journal_entry_id);
    $lines = $entry->lines()->orderBy('sequence')->get();

    expect($entry->total_debit)->toBe(4_770_000)
        ->and($entry->total_credit)->toBe(4_770_000)
        ->and($lines)->toHaveCount(4);

    expect((int) $entry->lines()->where('account_id', $expenseAccountId)->value('debit'))->toBe(4_000_000)
        ->and((int) $entry->lines()->where('account_id', $deductibleAccountId)->value('debit'))->toBe(90_244)
        ->and((int) $entry->lines()->where('account_id', $nonDeductibleAccountId)->value('debit'))->toBe(679_756);

    $payable = $entry->lines()->where('account_id', f3InvAccountId('401'))->firstOrFail();
    expect((int) $payable->credit)->toBe(4_770_000)
        ->and($payable->partner_type?->value)->toBe('supplier')
        ->and($payable->partner_id)->toBe($supplier->id);
});

// ── Single posting path (02-accounting §11.1) ───────────────────────────

it('produces every ledger write through PostFromEvent: rule provenance stamped, no second path', function () {
    $fixture = f3InvBaseline('on_invoice');
    $supplier = f3InvSupplier(['is_withholding_exempt' => true, 'withholding_exemption_ref' => 'EXO-PST-02']);

    $entriesBefore = DB::table('journal_entries')->count();

    $ready = f3InvPostReady($supplier, [f3InvServiceLine($fixture['tax_code'])]);
    $invoice = app(PostSupplierInvoice::class)->handle($ready['invoice']->id, f3InvActor($ready['poster']));

    // Exactly ONE entry for a single-family, no-withholding invoice.
    expect(DB::table('journal_entries')->count())->toBe($entriesBefore + 1);

    /** @var JournalEntry $entry */
    $entry = JournalEntry::query()->findOrFail($invoice->journal_entry_id);

    expect($entry->status)->toBe(JournalEntry::STATUS_POSTED)
        // Provenance: which rule version produced this entry, forever.
        ->and($entry->posting_rule_id)->not->toBeNull()
        ->and($entry->source_type)->toBe('posting_event')
        ->and($entry->piece_no)->not->toBeNull();
});

// ── on_invoice withholding recognition (§4.6/§6.4) ──────────────────────

it('recognises on_invoice withholding as Dr 401 / Cr 447-liability, netting the supplier to 1 365 000', function () {
    $fixture = f3InvBaseline('on_invoice');
    $liabilityAccountId = $fixture['rule']->liability_account_id;

    $ready = f3InvPostReady($fixture['supplier'], [
        f3InvServiceLine($fixture['tax_code'], [
            'description' => 'IT consulting',
            'unit_price_ht' => 1_200_000,
        ]),
    ]);

    $invoice = app(PostSupplierInvoice::class)->handle($ready['invoice']->id, f3InvActor($ready['poster']));

    expect($invoice->withholding_journal_entry_id)->not->toBeNull();

    /** @var JournalEntry $main */
    $main = JournalEntry::query()->findOrFail($invoice->journal_entry_id);
    /** @var JournalEntry $withholding */
    $withholding = JournalEntry::query()->findOrFail($invoice->withholding_journal_entry_id);

    // Main entry credits the FULL TTC to the supplier.
    expect((int) $main->lines()->where('account_id', f3InvAccountId('401'))->sum('credit'))->toBe(1_431_000);

    // The recognition entry moves 66 000 from the payable to the 447
    // liability, with the supplier partner on the payable leg.
    $payableLeg = $withholding->lines()->where('account_id', f3InvAccountId('401'))->firstOrFail();
    $liabilityLeg = $withholding->lines()->where('account_id', $liabilityAccountId)->firstOrFail();

    expect((int) $payableLeg->debit)->toBe(66_000)
        ->and($payableLeg->partner_id)->toBe($fixture['supplier']->id)
        ->and((int) $liabilityLeg->credit)->toBe(66_000)
        ->and($withholding->posting_rule_id)->not->toBeNull();

    // Net position on 401 for this supplier: 1 431 000 - 66 000.
    $net = (int) DB::table('journal_entry_lines')
        ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
        ->where('journal_entry_lines.account_id', f3InvAccountId('401'))
        ->where('journal_entry_lines.partner_id', $fixture['supplier']->id)
        ->whereIn('journal_entries.id', [$invoice->journal_entry_id, $invoice->withholding_journal_entry_id])
        ->selectRaw('COALESCE(SUM(credit - debit), 0) as net')
        ->value('net');

    expect($net)->toBe(1_365_000);
});

// ── Exempt lines post no tax legs at all ────────────────────────────────

it('posts an exempt line with no TVA legs: Dr expense / Cr payable only', function () {
    f3InvBaseline('on_invoice');
    $supplier = f3InvSupplier(['is_withholding_exempt' => true, 'withholding_exemption_ref' => 'EXO-PST-03']);

    $exemptCode = \App\Modules\Tax\Models\TaxCode::factory()->create([
        'direction' => 'input',
        'rate_bp' => 0,
        'is_exempt' => true,
        'exemption_legal_ref' => 'CGI art. 128 (à vérifier)',
        'exemption_condition' => 'ministry_accreditation',
        'affects_prorata_numerator' => false,
    ]);

    $ready = f3InvPostReady($supplier, [
        [
            'description' => 'Exempt training services',
            'quantity' => '1',
            'unit_price_ht' => 800_000,
            'tax_code_id' => (int) $exemptCode->id,
            'expense_account_id' => f3InvExpenseAccountId(),
        ],
    ]);

    $invoice = app(PostSupplierInvoice::class)->handle($ready['invoice']->id, f3InvActor($ready['poster']));

    /** @var JournalEntry $entry */
    $entry = JournalEntry::query()->findOrFail($invoice->journal_entry_id);

    expect($entry->lines()->count())->toBe(2)
        ->and($entry->total_debit)->toBe(800_000);
});

// ── Capitalised line: non-deductible TVA enters the asset cost (§5.5) ───

it('capitalises the non-deductible TVA into the asset cost line', function () {
    $fixture = f3InvBaseline('on_invoice', 11_720);
    $supplier = f3InvSupplier([
        'is_withholding_exempt' => true,
        'withholding_exemption_ref' => 'EXO-PST-04',
        'payable_account_id' => f3InvAccountId('4812'),
    ]);

    $capexAccountId = f3InvCapexAccountId();

    $ready = f3InvPostReady($supplier, [
        [
            'description' => 'Generator',
            'quantity' => '1',
            'unit_price_ht' => 4_000_000,
            'tax_code_id' => (int) $fixture['tax_code']->id,
            'expense_account_id' => $capexAccountId,
            'is_capitalised' => true,
            'asset_category_id' => 3,
        ],
    ], ['payable_account_id' => f3InvAccountId('4812')]);

    $invoice = app(PostSupplierInvoice::class)->handle($ready['invoice']->id, f3InvActor($ready['poster']));

    /** @var JournalEntry $entry */
    $entry = JournalEntry::query()->findOrFail($invoice->journal_entry_id);

    // §5.5: Dr 2xxx (HT + non-deductible 679 756), Dr 4451 deductible,
    // Cr 4812 - and NO non-deductible expense leg.
    $assetLeg = $entry->lines()->where('account_id', $capexAccountId)->firstOrFail();
    $payableLeg = $entry->lines()->where('account_id', f3InvAccountId('4812'))->firstOrFail();

    expect((int) $assetLeg->debit)->toBe(4_679_756)
        ->and((int) $entry->lines()->where('account_id', $fixture['tax_code']->deductible_account_id)->value('debit'))->toBe(90_244)
        ->and($entry->lines()->count())->toBe(3)
        ->and((int) $payableLeg->credit)->toBe(4_770_000)
        ->and($payableLeg->partner_id)->toBe($supplier->id);
});
