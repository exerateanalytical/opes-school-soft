<?php

declare(strict_types=1);

use App\Modules\Procurement\Actions\ApproveSupplierInvoice;
use App\Modules\Procurement\Actions\MatchSupplierInvoice;
use App\Modules\Procurement\Actions\PostSupplierInvoice;
use App\Modules\Procurement\Domain\SupplierInvoicePermission;
use App\Modules\Procurement\Models\SupplierInvoice;
use App\Modules\Tax\Actions\CancelWithholdingAttestation;
use App\Modules\Tax\Actions\ReplaceWithholdingAttestation;
use App\Modules\Tax\Domain\AttestationStatus;
use App\Modules\Tax\Models\WithholdingAttestation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/SupplierInvoiceTestHelpers.php';

uses(RefreshDatabase::class);

if (! function_exists('f3InvPostedWithholdingInvoice')) {
    /**
     * A POSTED on_invoice invoice with 66 000 withheld, whose posting
     * issued the attestation - plus the poster still signed in.
     *
     * @return array{invoice: SupplierInvoice, attestation: WithholdingAttestation, poster: \App\Modules\Identity\Models\User}
     */
    function f3InvPostedWithholdingInvoice(): array
    {
        $fixture = f3InvBaseline('on_invoice');

        $invoice = f3InvCapture($fixture['clerk'], $fixture['supplier'], [
            f3InvServiceLine($fixture['tax_code'], ['unit_price_ht' => 1_200_000]),
        ]);
        app(MatchSupplierInvoice::class)->handle($invoice->id, f3InvActor($fixture['clerk']));

        $poster = f3InvUser(
            SupplierInvoicePermission::APPROVE,
            SupplierInvoicePermission::APPROVE_UNMATCHED,
            \App\Modules\Identity\Domain\Permission::LedgerPost->value,
        );
        app(ApproveSupplierInvoice::class)->handle($invoice->id, f3InvActor($poster), 'below PO threshold');
        $invoice = app(PostSupplierInvoice::class)->handle($invoice->id, f3InvActor($poster));

        /** @var WithholdingAttestation $attestation */
        $attestation = WithholdingAttestation::query()
            ->where('supplier_invoice_id', $invoice->id)
            ->firstOrFail();

        return ['invoice' => $invoice, 'attestation' => $attestation, 'poster' => $poster];
    }
}

// ── Issue-on-posting (§6.6, on_invoice basis) ───────────────────────────

it('issues an ATT-numbered attestation in the posting transaction, snapshotting base, rate and amount', function () {
    $result = f3InvPostedWithholdingInvoice();
    $attestation = $result['attestation'];

    expect($attestation->attestation_no)->toMatch('/^ATT\/2031\/\d{6}$/')
        ->and($attestation->status)->toBe(AttestationStatus::Issued)
        ->and($attestation->supplier_id)->toBe($result['invoice']->supplier_id)
        ->and($attestation->supplier_payment_id)->toBeNull()
        ->and($attestation->period_month)->toBe(3)
        ->and($attestation->period_year)->toBe(2031)
        ->and($attestation->base_amount)->toBe(1_200_000)
        ->and($attestation->rate_bp_applied)->toBe(5_500)
        ->and($attestation->withheld_amount)->toBe(66_000)
        ->and($attestation->issued_at)->not->toBeNull();
});

// ── Immutability (§6.6 invariant 1 / §11 obligation 4) ──────────────────

it('freezes an issued attestation: editing the snapshotted amount throws', function () {
    $result = f3InvPostedWithholdingInvoice();
    $attestation = $result['attestation'];

    $attestation->withheld_amount = 1;

    expect(fn () => $attestation->save())->toThrow(RuntimeException::class, 'immutable');
});

it('never deletes an attestation, in any status', function () {
    $result = f3InvPostedWithholdingInvoice();

    expect(fn () => $result['attestation']->delete())->toThrow(RuntimeException::class, 'never deleted');
});

// ── Replacement chain (§6.6 invariant 1) ────────────────────────────────

it('replaces an issued attestation: successor issued, original flipped to replaced and chained', function () {
    $result = f3InvPostedWithholdingInvoice();
    $original = $result['attestation'];

    $replacement = app(ReplaceWithholdingAttestation::class)->handle(
        (int) $original->getKey(),
        ['withheld_amount' => 60_000, 'base_amount' => 1_090_909],
        'supplier NIU corrected after DGI verification',
        f3InvActor($result['poster']),
    );

    $original = $original->refresh();

    expect($replacement->status)->toBe(AttestationStatus::Issued)
        ->and($replacement->withheld_amount)->toBe(60_000)
        ->and($replacement->supplier_invoice_id)->toBe($result['invoice']->id)
        ->and($replacement->attestation_no)->not->toBe($original->attestation_no)
        ->and($original->status)->toBe(AttestationStatus::Replaced)
        ->and($original->replaced_by_attestation_id)->toBe((int) $replacement->getKey());
});

it('refuses to replace a replaced attestation - the chain never forks', function () {
    $result = f3InvPostedWithholdingInvoice();
    $original = $result['attestation'];

    app(ReplaceWithholdingAttestation::class)->handle(
        (int) $original->getKey(),
        ['withheld_amount' => 60_000],
        'first correction of the certified base',
        f3InvActor($result['poster']),
    );

    app(ReplaceWithholdingAttestation::class)->handle(
        (int) $original->getKey(),
        ['withheld_amount' => 55_000],
        'second correction attempt on the same original',
        f3InvActor($result['poster']),
    );
})->throws(DomainException::class, 'replaced');

// ── Cancellation (§6.6 invariant 2) ─────────────────────────────────────

it('cancels an issued attestation with a mandatory reason', function () {
    $result = f3InvPostedWithholdingInvoice();

    $cancelled = app(CancelWithholdingAttestation::class)->handle(
        (int) $result['attestation']->getKey(),
        'underlying invoice cancelled by the bursar',
        f3InvActor($result['poster']),
    );

    expect($cancelled->status)->toBe(AttestationStatus::Cancelled)
        ->and($cancelled->cancellation_reason)->toBe('underlying invoice cancelled by the bursar')
        ->and($cancelled->cancelled_by)->toBe($result['poster']->id);
});

it('refuses to cancel an attestation already included in a filed declaration', function () {
    $result = f3InvPostedWithholdingInvoice();

    // Simulate F5's declaration inclusion (the FK arrives with Block E).
    DB::table('withholding_attestations')
        ->where('id', $result['attestation']->getKey())
        ->update(['tax_declaration_id' => 424242]);

    app(CancelWithholdingAttestation::class)->handle(
        (int) $result['attestation']->getKey(),
        'attempt to cancel a declared attestation',
        f3InvActor($result['poster']),
    );
})->throws(DomainException::class, 'filed declaration');

// ── The exactly-one-source CHECK (§6.6) ─────────────────────────────────

it('rejects an attestation naming neither or both sources at the database', function () {
    $result = f3InvPostedWithholdingInvoice();

    expect(fn () => DB::table('withholding_attestations')->insert([
        'attestation_no' => 'ATT/2031/909090',
        'supplier_id' => $result['invoice']->supplier_id,
        'supplier_invoice_id' => null,
        'supplier_payment_id' => null,
        'withholding_rule_id' => $result['attestation']->withholding_rule_id,
        'period_month' => 3,
        'period_year' => 2031,
        'base_amount' => 1,
        'rate_bp_applied' => 1,
        'withheld_amount' => 1,
        'status' => 'issued',
        'created_by' => $result['poster']->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(Illuminate\Database\QueryException::class);
});
