<?php

declare(strict_types=1);

use App\Modules\Procurement\Actions\ApproveSupplierPayment;
use App\Modules\Procurement\Actions\PaySupplierPayment;
use App\Modules\Procurement\Actions\RecordSupplierPayment;
use App\Modules\Procurement\Domain\SupplierPaymentPermission;
use App\Modules\Procurement\Domain\SupplierPaymentStatus;
use App\Modules\Procurement\Models\SupplierPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/SupplierPaymentTestHelpers.php';

uses(RefreshDatabase::class);

// ── §6.4 worked example, to the franc ───────────────────────────────────

it('pays the §6.4 worked example exactly: Dr 401 1 431 000 / Cr 5x 1 365 000 / Cr 447 66 000, and issues the attestation', function () {
    $fixture = f4PayBaseline('on_payment');
    $invoice = f4PayPostedInvoice($fixture['supplier'], [f4PayServiceLine($fixture['tax_code'])]);

    expect($invoice->total_ttc)->toBe(1_431_000)
        ->and($invoice->withholding_total)->toBe(0); // on_payment: nothing recognised at invoice

    ['payment' => $draft] = f4PayRecordDraft($fixture['supplier'], [
        ['supplier_invoice_id' => (int) $invoice->id, 'amount' => 1_431_000],
    ]);

    expect($draft->gross_amount)->toBe(1_431_000)
        ->and($draft->withholding_amount)->toBe(66_000)
        ->and($draft->net_amount)->toBe(1_365_000);

    ['payment' => $paid] = f4PayApproveAndPay($draft);

    expect($paid->status)->toBe(SupplierPaymentStatus::Paid)
        ->and($paid->journal_entry_id)->not->toBeNull();

    $liabilityAccountId = (int) $fixture['rule']->liability_account_id;
    $lines = DB::table('journal_entry_lines')
        ->where('journal_entry_id', $paid->journal_entry_id)
        ->get(['account_id', 'debit', 'credit']);

    $payableId = f4PayAccountId('401');
    $treasuryId = f4PayAccountId('52');

    expect((int) $lines->where('account_id', $payableId)->sum('debit'))->toBe(1_431_000)
        ->and((int) $lines->where('account_id', $treasuryId)->sum('credit'))->toBe(1_365_000)
        ->and((int) $lines->where('account_id', $liabilityAccountId)->sum('credit'))->toBe(66_000);

    // §6.6: the supplier receives an attestation for the 66 000.
    $attestation = f4PayRow(DB::table('withholding_attestations')->where('supplier_payment_id', $paid->id)->first());

    expect((int) $attestation->withheld_amount)->toBe(66_000)
        ->and((int) $attestation->base_amount)->toBe(1_200_000)
        ->and((string) $attestation->status)->toBe('issued');

    // Full settlement letters the payable (C10).
    expect($invoice->refresh()->status->value)->toBe('paid');

    $allocation = f4PayRow(DB::table('supplier_payment_allocations')->where('supplier_payment_id', $paid->id)->first());
    expect($allocation->letter_code)->not->toBeNull()
        ->and((int) $allocation->withholding_amount)->toBe(66_000);
});

it('books a school-borne mobile-money fee to the fee expense account, never netting it into the supplier', function () {
    $fixture = f4PayBaseline('on_payment');
    $invoice = f4PayPostedInvoice($fixture['supplier'], [f4PayServiceLine($fixture['tax_code'])]);

    ['payment' => $draft] = f4PayRecordDraft($fixture['supplier'], [
        ['supplier_invoice_id' => (int) $invoice->id, 'amount' => 1_431_000],
    ], [
        'payment_method' => 'mobile_money',
        'reference' => 'MOMO-778812',
        'fee_amount' => 500,
        'fee_bearer' => 'school',
        'fee_expense_account_id' => f4PayAccountId('6317'),
    ]);

    ['payment' => $paid] = f4PayApproveAndPay($draft);

    $lines = DB::table('journal_entry_lines')
        ->where('journal_entry_id', $paid->journal_entry_id)
        ->get(['account_id', 'debit', 'credit']);

    // Dr 6317 500 and the treasury credits net + fee.
    expect((int) $lines->where('account_id', f4PayAccountId('6317'))->sum('debit'))->toBe(500)
        ->and((int) $lines->where('account_id', f4PayAccountId('52'))->sum('credit'))->toBe(1_365_500)
        ->and((int) $lines->where('account_id', f4PayAccountId('401'))->sum('debit'))->toBe(1_431_000);
});

// ── Allocation race: outstanding recomputed under lock ──────────────────

it('refuses an allocation exceeding the outstanding recomputed under lock - a draft reserves its amount', function () {
    $fixture = f4PayBaseline('on_payment');
    $invoice = f4PayPostedInvoice($fixture['supplier'], [f4PayServiceLine($fixture['tax_code'])]);

    // Clerk 1 reserves the full outstanding.
    f4PayRecordDraft($fixture['supplier'], [
        ['supplier_invoice_id' => (int) $invoice->id, 'amount' => 1_431_000],
    ]);

    // Clerk 2, same invoice: outstanding is now zero INSIDE the lock.
    expect(fn () => f4PayRecordDraft($fixture['supplier'], [
        ['supplier_invoice_id' => (int) $invoice->id, 'amount' => 1],
    ]))->toThrow(ValidationException::class, 'over-settles');
});

it('returns the same payment for the same idempotency key - a double-clicked Pay never disburses twice', function () {
    $fixture = f4PayBaseline('on_payment');
    $invoice = f4PayPostedInvoice($fixture['supplier'], [f4PayServiceLine($fixture['tax_code'])]);

    $recorder = f4PayUser(SupplierPaymentPermission::RECORD);
    $input = [
        'supplier_id' => (int) $fixture['supplier']->id,
        'payment_method' => 'bank',
        'treasury_account_id' => f4PayAccountId('52'),
        'payment_date' => '2031-03-20',
        'reference' => 'VIR-IDEM-1',
        'idempotency_key' => '3f5a0f5e-0000-4000-8000-1234567890ab',
        'allocations' => [['supplier_invoice_id' => (int) $invoice->id, 'amount' => 1_000]],
    ];

    $first = app(RecordSupplierPayment::class)->handle($input, f4PayActor($recorder));
    $second = app(RecordSupplierPayment::class)->handle($input, f4PayActor($recorder));

    expect($second->id)->toBe($first->id)
        ->and(SupplierPayment::query()->count())->toBe(1);
});

// ── §11.14 segregation of duties, identity not permission ───────────────

it('refuses the recorder approving their own payment, whatever permissions they hold', function () {
    $fixture = f4PayBaseline('on_payment');
    $invoice = f4PayPostedInvoice($fixture['supplier'], [f4PayServiceLine($fixture['tax_code'])]);

    ['payment' => $draft, 'recorder' => $recorder] = f4PayRecordDraft($fixture['supplier'], [
        ['supplier_invoice_id' => (int) $invoice->id, 'amount' => 1_000],
    ]);

    // Give the recorder the approve ability too - identity still blocks.
    \Spatie\Permission\Models\Permission::findOrCreate(SupplierPaymentPermission::APPROVE, 'web');
    $recorder->givePermissionTo(SupplierPaymentPermission::APPROVE);
    \Pest\Laravel\actingAs($recorder->fresh() ?? $recorder);

    expect(fn () => app(ApproveSupplierPayment::class)->handle((int) $draft->id, f4PayActor($recorder)))
        ->toThrow(DomainException::class, 'cannot approve');
});

it('refuses the approver executing the payment they approved', function () {
    $fixture = f4PayBaseline('on_payment');
    $invoice = f4PayPostedInvoice($fixture['supplier'], [f4PayServiceLine($fixture['tax_code'])]);

    ['payment' => $draft] = f4PayRecordDraft($fixture['supplier'], [
        ['supplier_invoice_id' => (int) $invoice->id, 'amount' => 1_000],
    ]);

    $approver = f4PayUser(
        SupplierPaymentPermission::APPROVE,
        SupplierPaymentPermission::RECORD,
        \App\Modules\Identity\Domain\Permission::LedgerPost->value,
    );
    app(ApproveSupplierPayment::class)->handle((int) $draft->id, f4PayActor($approver));

    expect(fn () => app(PaySupplierPayment::class)->handle((int) $draft->id, f4PayActor($approver)))
        ->toThrow(DomainException::class, 'cannot execute');
});

// ── on_invoice recognition: the payment settles NET, no 447 leg ─────────

it('under on_invoice recognition pays the net payable with no withholding leg - the 447 movement happened at posting', function () {
    $fixture = f4PayBaseline('on_invoice');
    $invoice = f4PayPostedInvoice($fixture['supplier'], [f4PayServiceLine($fixture['tax_code'])]);

    expect($invoice->withholding_total)->toBe(66_000)
        ->and($invoice->net_payable)->toBe(1_365_000);

    ['payment' => $draft] = f4PayRecordDraft($fixture['supplier'], [
        ['supplier_invoice_id' => (int) $invoice->id, 'amount' => 1_365_000],
    ]);

    expect($draft->withholding_amount)->toBe(0);

    ['payment' => $paid] = f4PayApproveAndPay($draft);

    $liabilityAccountId = (int) $fixture['rule']->liability_account_id;
    $lines = DB::table('journal_entry_lines')
        ->where('journal_entry_id', $paid->journal_entry_id)
        ->get(['account_id', 'debit', 'credit']);

    expect((int) $lines->where('account_id', f4PayAccountId('401'))->sum('debit'))->toBe(1_365_000)
        ->and((int) $lines->where('account_id', f4PayAccountId('52'))->sum('credit'))->toBe(1_365_000)
        ->and((int) $lines->where('account_id', $liabilityAccountId)->count())->toBe(0);

    expect($invoice->refresh()->status->value)->toBe('paid');
});

it('requires the transaction reference for bank and mobile-money payments', function () {
    $fixture = f4PayBaseline('on_payment');
    $invoice = f4PayPostedInvoice($fixture['supplier'], [f4PayServiceLine($fixture['tax_code'])]);

    $recorder = f4PayUser(SupplierPaymentPermission::RECORD);

    expect(fn () => app(RecordSupplierPayment::class)->handle([
        'supplier_id' => (int) $fixture['supplier']->id,
        'payment_method' => 'bank',
        'treasury_account_id' => f4PayAccountId('52'),
        'payment_date' => '2031-03-20',
        'allocations' => [['supplier_invoice_id' => (int) $invoice->id, 'amount' => 1_000]],
    ], f4PayActor($recorder)))->toThrow(ValidationException::class, 'reference');
});
