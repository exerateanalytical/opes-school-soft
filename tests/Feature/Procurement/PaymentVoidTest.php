<?php

declare(strict_types=1);

use App\Modules\Procurement\Actions\VoidSupplierPayment;
use App\Modules\Procurement\Domain\SupplierPaymentPermission;
use App\Modules\Procurement\Domain\SupplierPaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/SupplierPaymentTestHelpers.php';

uses(RefreshDatabase::class);

if (! function_exists('f4PayVoider')) {
    /** A signed-in voider (void + ledger.post for the reversal chain). */
    function f4PayVoider(): \App\Modules\Identity\Models\User
    {
        return f4PayUser(
            SupplierPaymentPermission::VOID,
            \App\Modules\Identity\Domain\Permission::LedgerPost->value,
        );
    }
}

// ── §11.9: the full cascade, one transaction ────────────────────────────

it('voids a paid payment with the full §11.9 cascade: allocations reversed, invoice re-opened, lettering undone, attestation cancelled, ledger reversed', function () {
    $fixture = f4PayBaseline('on_payment');
    $invoice = f4PayPostedInvoice($fixture['supplier'], [f4PayServiceLine($fixture['tax_code'])]);

    ['payment' => $draft] = f4PayRecordDraft($fixture['supplier'], [
        ['supplier_invoice_id' => (int) $invoice->id, 'amount' => 1_431_000],
    ]);
    ['payment' => $paid] = f4PayApproveAndPay($draft);

    $allocationBefore = f4PayRow(DB::table('supplier_payment_allocations')->where('supplier_payment_id', $paid->id)->first());
    $letteringId = (int) $allocationBefore->lettering_id;

    expect($invoice->refresh()->status->value)->toBe('paid')
        ->and($letteringId)->toBeGreaterThan(0);

    $voider = f4PayVoider();
    $void = app(VoidSupplierPayment::class)->handle((int) $paid->id, 'duplicate disbursement caught by the bank', f4PayActor($voider));

    // Payment: voided, void row carries the C9 reversal of THE entry.
    expect($paid->refresh()->status)->toBe(SupplierPaymentStatus::Voided)
        ->and($void->reversal_journal_entry_id)->not->toBeNull();

    $reversal = f4PayRow(DB::table('journal_entries')->where('id', $void->reversal_journal_entry_id)->first());
    expect((int) $reversal->reverses_entry_id)->toBe((int) $paid->journal_entry_id);

    // Allocations: stamped reversed, never deleted.
    $allocation = f4PayRow(DB::table('supplier_payment_allocations')->where('supplier_payment_id', $paid->id)->first());
    expect($allocation->reversed_at)->not->toBeNull();

    // Invoice: re-opened.
    expect($invoice->refresh()->status->value)->toBe('posted');

    // Lettering: group unlettered, member lines released.
    $lettering = f4PayRow(DB::table('letterings')->where('id', $letteringId)->first());
    expect($lettering->unlettered_at)->not->toBeNull()
        ->and(DB::table('journal_entry_lines')->where('lettering_id', $letteringId)->count())->toBe(0);

    // Attestation: cancelled in the same transaction - a §6.6 invariant 2
    // attestation for a payment that never happened is a false tax document.
    $attestation = f4PayRow(DB::table('withholding_attestations')->where('supplier_payment_id', $paid->id)->first());
    expect((string) $attestation->status)->toBe('cancelled');
});

it('refuses the recorder voiding their own payment (§11.14) and refuses a second void', function () {
    $fixture = f4PayBaseline('on_payment');
    $invoice = f4PayPostedInvoice($fixture['supplier'], [f4PayServiceLine($fixture['tax_code'])]);

    ['payment' => $draft, 'recorder' => $recorder] = f4PayRecordDraft($fixture['supplier'], [
        ['supplier_invoice_id' => (int) $invoice->id, 'amount' => 1_431_000],
    ]);
    ['payment' => $paid] = f4PayApproveAndPay($draft);

    // Recorder holds the void permission - identity still blocks.
    \Spatie\Permission\Models\Permission::findOrCreate(SupplierPaymentPermission::VOID, 'web');
    \Spatie\Permission\Models\Permission::findOrCreate(\App\Modules\Identity\Domain\Permission::LedgerPost->value, 'web');
    $recorder->givePermissionTo(SupplierPaymentPermission::VOID, \App\Modules\Identity\Domain\Permission::LedgerPost->value);
    \Pest\Laravel\actingAs($recorder->fresh() ?? $recorder);

    expect(fn () => app(VoidSupplierPayment::class)->handle((int) $paid->id, 'recorder voiding own payment', f4PayActor($recorder)))
        ->toThrow(DomainException::class, 'cannot void');

    $voider = f4PayVoider();
    app(VoidSupplierPayment::class)->handle((int) $paid->id, 'legitimate first void by someone else', f4PayActor($voider));

    expect(fn () => app(VoidSupplierPayment::class)->handle((int) $paid->id, 'a second void must be refused', f4PayActor($voider)))
        ->toThrow(DomainException::class, 'already voided');
});

it('requires a substantive reason to void', function () {
    $fixture = f4PayBaseline('on_payment');
    $invoice = f4PayPostedInvoice($fixture['supplier'], [f4PayServiceLine($fixture['tax_code'])]);

    ['payment' => $draft] = f4PayRecordDraft($fixture['supplier'], [
        ['supplier_invoice_id' => (int) $invoice->id, 'amount' => 1_000],
    ]);

    $voider = f4PayVoider();

    expect(fn () => app(VoidSupplierPayment::class)->handle((int) $draft->id, 'short', f4PayActor($voider)))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

it('voids a draft as a plain cancellation: allocation released, no ledger reversal, invoice untouched', function () {
    $fixture = f4PayBaseline('on_payment');
    $invoice = f4PayPostedInvoice($fixture['supplier'], [f4PayServiceLine($fixture['tax_code'])]);

    ['payment' => $draft] = f4PayRecordDraft($fixture['supplier'], [
        ['supplier_invoice_id' => (int) $invoice->id, 'amount' => 1_431_000],
    ]);

    $voider = f4PayVoider();
    $void = app(VoidSupplierPayment::class)->handle((int) $draft->id, 'clerk picked the wrong supplier', f4PayActor($voider));

    expect($void->reversal_journal_entry_id)->toBeNull()
        ->and($draft->refresh()->status)->toBe(SupplierPaymentStatus::Voided)
        ->and($invoice->refresh()->status->value)->toBe('posted');

    // The reservation is released: the full amount can be re-recorded.
    ['payment' => $again] = f4PayRecordDraft($fixture['supplier'], [
        ['supplier_invoice_id' => (int) $invoice->id, 'amount' => 1_431_000],
    ]);
    expect($again->gross_amount)->toBe(1_431_000);
});
