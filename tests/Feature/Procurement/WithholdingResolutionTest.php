<?php

declare(strict_types=1);

use App\Modules\Procurement\Actions\ApproveSupplierInvoice;
use App\Modules\Procurement\Actions\MatchSupplierInvoice;
use App\Modules\Procurement\Domain\SupplierInvoicePermission;
use App\Modules\Procurement\Domain\SupplierInvoiceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/SupplierInvoiceTestHelpers.php';

uses(RefreshDatabase::class);

// ── The §6.4 worked example, to the franc ───────────────────────────────

it('reproduces the §6.4 worked example: AIR 5.5% on 1 200 000 HT withholds 66 000 and nets 1 365 000', function () {
    // Recognition on_invoice so the amounts are visible at capture.
    $fixture = f3InvBaseline('on_invoice');

    $invoice = f3InvCapture($fixture['clerk'], $fixture['supplier'], [
        f3InvServiceLine($fixture['tax_code'], [
            'description' => 'IT consulting',
            'unit_price_ht' => 1_200_000,
        ]),
    ]);

    $line = $invoice->lines()->firstOrFail();

    // TVA 19.25% = 231 000 → TTC 1 431 000; AIR 5.5% on HT = 66 000.
    expect($invoice->total_ttc)->toBe(1_431_000)
        ->and($line->withholding_rule_id)->toBe((int) $fixture['rule']->getKey())
        ->and($line->withholding_base)->toBe(1_200_000)
        ->and($line->withholding_rate_bp_applied)->toBe(5_500)
        ->and($line->withholding_amount)->toBe(66_000)
        ->and($invoice->withholding_total)->toBe(66_000)
        ->and($invoice->net_payable)->toBe(1_365_000)
        ->and($invoice->withholding_unresolved)->toBeFalse();
});

it('records the rule but no amount under on_payment recognition', function () {
    $fixture = f3InvBaseline('on_payment');

    $invoice = f3InvCapture($fixture['clerk'], $fixture['supplier'], [
        f3InvServiceLine($fixture['tax_code'], ['unit_price_ht' => 1_200_000]),
    ]);

    $line = $invoice->lines()->firstOrFail();

    // The rule is NAMED (preview) but recognition waits for the payment.
    expect($line->withholding_rule_id)->toBe((int) $fixture['rule']->getKey())
        ->and($line->withholding_amount)->toBe(0)
        ->and($invoice->withholding_total)->toBe(0)
        ->and($invoice->net_payable)->toBe($invoice->total_ttc);
});

// ── §6.4 step 7: unresolved flags, never silent zero ────────────────────

it('flags withholding_unresolved when no rule matches and blocks approval without the waive permission', function () {
    $fixture = f3InvBaseline();

    // The only confirmed rule is scoped to RENT - nothing matches a
    // service line, which is NOT the same as zero withholding.
    \App\Modules\Tax\Models\WithholdingRule::query()
        ->whereKey($fixture['rule']->getKey())
        ->update(['applies_to' => 'rent']);

    $invoice = f3InvCapture($fixture['clerk'], $fixture['supplier'], [
        f3InvServiceLine($fixture['tax_code']),
    ]);

    expect($invoice->withholding_unresolved)->toBeTrue()
        ->and($invoice->lines()->firstOrFail()->withholding_reason)->toBe('unresolved');

    app(MatchSupplierInvoice::class)->handle($invoice->id, f3InvActor($fixture['clerk']));

    // An approver WITHOUT the waive permission is blocked.
    $approver = f3InvUser(SupplierInvoicePermission::APPROVE, SupplierInvoicePermission::APPROVE_UNMATCHED);
    expect(fn () => app(ApproveSupplierInvoice::class)->handle($invoice->id, f3InvActor($approver), 'below PO threshold'))
        ->toThrow(DomainException::class, 'waive_withholding');

    // WITH it but with no reason: still refused.
    $waiver = f3InvUser(
        SupplierInvoicePermission::APPROVE,
        SupplierInvoicePermission::APPROVE_UNMATCHED,
        SupplierInvoicePermission::WAIVE_WITHHOLDING,
    );
    expect(fn () => app(ApproveSupplierInvoice::class)->handle($invoice->id, f3InvActor($waiver), 'below PO threshold'))
        ->toThrow(Illuminate\Validation\ValidationException::class);

    // WITH permission AND reason: the waiver is RECORDED.
    $approved = app(ApproveSupplierInvoice::class)->handle(
        $invoice->id,
        f3InvActor($waiver),
        'below PO threshold',
        'individual below the rule scope per accountant advice',
    );

    expect($approved->status)->toBe(SupplierInvoiceStatus::Approved)
        ->and($approved->withholding_waived_reason)->toBe('individual below the rule scope per accountant advice')
        ->and($approved->withholding_waived_by)->toBe($waiver->id);
});

// ── Exemptions (§6.4 step 1 / §11 obligation 7) ─────────────────────────

it('does not withhold from a supplier holding an unexpired exemption, recording the reference', function () {
    $fixture = f3InvBaseline();
    $exempt = f3InvSupplier([
        'is_withholding_exempt' => true,
        'withholding_exemption_ref' => 'EXO-DGI-2030-17',
        'withholding_exemption_expires_on' => '2031-12-31',
    ]);

    $clerk = f3InvUser(SupplierInvoicePermission::CREATE);
    $invoice = f3InvCapture($clerk, $exempt, [f3InvServiceLine($fixture['tax_code'])]);

    $line = $invoice->lines()->firstOrFail();

    expect($invoice->withholding_total)->toBe(0)
        ->and($invoice->withholding_unresolved)->toBeFalse()
        ->and($line->withholding_reason)->toBe('exempt_supplier')
        ->and($line->withholding_exemption_ref)->toBe('EXO-DGI-2030-17');
});

it('WITHHOLDS from a supplier whose exemption expired before the invoice date', function () {
    $fixture = f3InvBaseline();
    $expired = f3InvSupplier([
        'is_withholding_exempt' => true,
        'withholding_exemption_ref' => 'EXO-DGI-2029-03',
        'withholding_exemption_expires_on' => '2031-01-31',
    ]);

    $clerk = f3InvUser(SupplierInvoicePermission::CREATE);
    $invoice = f3InvCapture($clerk, $expired, [
        f3InvServiceLine($fixture['tax_code'], ['unit_price_ht' => 1_200_000]),
    ]);

    // Invoice date 2031-03-15 is past the expiry: the exemption is dead
    // and 5.5% applies in full.
    expect($invoice->withholding_total)->toBe(66_000)
        ->and($invoice->lines()->firstOrFail()->withholding_amount)->toBe(66_000);
});

// ── Empty-seed refusal (§6.1 / §11.16) ──────────────────────────────────

it('refuses to capture any invoice while no confirmed withholding rule exists', function () {
    $calendar = f3InvCalendar();
    f3InvIdentity();
    f3InvTaxSettings('on_invoice');
    f3InvConfirmedProrata($calendar['fiscal_year_id']);
    // Deliberately NO withholding rule at all.

    $supplier = f3InvSupplier();
    $taxCode = f3InvInputTaxCode();
    $clerk = f3InvUser(SupplierInvoicePermission::CREATE);

    f3InvCapture($clerk, $supplier, [f3InvServiceLine($taxCode)]);
})->throws(DomainException::class, 'configure withholding rules');

it('honours minimum_base: below the threshold no withholding is due, with the reason stored', function () {
    $calendar = f3InvCalendar();
    f3InvIdentity();
    f3InvTaxSettings('on_invoice');
    f3InvConfirmedProrata($calendar['fiscal_year_id']);

    $configurer = f3InvUser(\App\Modules\Identity\Domain\Permission::LedgerConfigure->value);
    f3InvWithholdingRule($configurer, ['minimum_base' => 500_000]);
    f3InvSavePostingRules($configurer);

    $supplier = f3InvSupplier();
    $taxCode = f3InvInputTaxCode();
    $clerk = f3InvUser(SupplierInvoicePermission::CREATE);

    $invoice = f3InvCapture($clerk, $supplier, [
        f3InvServiceLine($taxCode, ['unit_price_ht' => 300_000]),
    ]);

    $line = $invoice->lines()->firstOrFail();

    expect($invoice->withholding_total)->toBe(0)
        ->and($invoice->withholding_unresolved)->toBeFalse()
        ->and($line->withholding_reason)->toBe('below_threshold');
});
