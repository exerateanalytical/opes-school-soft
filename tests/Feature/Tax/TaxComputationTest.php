<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Tax\Actions\ComputeLineTax;
use App\Modules\Tax\Actions\EvaluateTaxCode;
use App\Modules\Tax\Actions\ResolveTaxCodeFor;
use App\Modules\Tax\Domain\TaxDirection;
use App\Modules\Tax\Models\TaxCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

if (! function_exists('f1TaxCompIdentity')) {
    /**
     * Fixture: a confirmed fiscal identity. Overridable for the gate tests.
     *
     * @param  array<string, mixed>  $overrides
     */
    function f1TaxCompIdentity(array $overrides = []): void
    {
        $user = User::factory()->create();

        DB::table('fiscal_identities')->insert([
            'id' => 1,
            'legal_name' => 'Collège Bilingue OPES',
            'legal_form' => 'etablissement_prive_laic',
            'niu' => 'M012345678901C',
            'tax_centre_code' => 'CIME-YDE1',
            'tax_centre_name' => 'CIME Yaoundé 1er',
            'tax_centre_type' => 'CIME',
            'tax_regime' => 'reel',
            'tax_regime_effective_from' => '2020-01-01',
            'is_tva_registered' => true,
            'tva_registered_from' => '2020-01-01',
            'ministry_accreditation_number' => 'ARR-2015-0042',
            'ministry_accreditation_authority' => 'MINESEC',
            'ministry_accreditation_date' => '2015-09-01',
            'ministry_accreditation_expires_on' => null,
            'fiscal_year_end_month' => 12,
            'fiscal_year_end_day' => 31,
            'fiscal_identity_confirmed_by' => $user->getKey(),
            'fiscal_identity_confirmed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }
}

// ── Empty-seed refusal (§11.16) ─────────────────────────────────────────

it('refuses to resolve a tax code outside any effective version window', function () {
    // The catalog may point at a version, but no version in force on the
    // DOCUMENT date means refuse - configure with your accountant - never
    // silently reuse a stale rate.
    f1TaxCompIdentity();
    $taxCode = TaxCode::factory()->create(['effective_from' => '2026-01-01']);

    app(ResolveTaxCodeFor::class)->handle((int) $taxCode->id, '2025-06-30');
})->throws(DomainException::class, 'No active version');

it('refuses an inactive tax code version', function () {
    f1TaxCompIdentity();
    $taxCode = TaxCode::factory()->create(['is_active' => false]);

    app(ResolveTaxCodeFor::class)->handle((int) $taxCode->id, '2026-03-01');
})->throws(DomainException::class, 'No active version');

it('refuses a TVA-bearing computation while the school is not TVA-registered', function () {
    // §2.2 invariant 3 - collecting or deducting TVA as a non-assujetti is
    // refused outright, with the reason surfaced.
    f1TaxCompIdentity(['is_tva_registered' => false, 'tva_registered_from' => null]);
    $taxCode = TaxCode::factory()->create();

    app(ComputeLineTax::class)->handle(100_000, (int) $taxCode->id, '2026-03-01', TaxDirection::Output);
})->throws(DomainException::class, 'not TVA-registered');

it('refuses a TVA-bearing computation with no fiscal identity configured at all', function () {
    $taxCode = TaxCode::factory()->create();

    app(ComputeLineTax::class)->handle(100_000, (int) $taxCode->id, '2026-03-01', TaxDirection::Output);
})->throws(DomainException::class, 'not TVA-registered');

it('refuses when the régime réel is not yet effective on the document date', function () {
    f1TaxCompIdentity(['tax_regime_effective_from' => '2026-06-01']);
    $taxCode = TaxCode::factory()->create();

    app(ComputeLineTax::class)->handle(100_000, (int) $taxCode->id, '2026-03-01', TaxDirection::Output);
})->throws(DomainException::class, 'régime réel');

// ── Exemption gating (§5.2, §11.13) ─────────────────────────────────────

it('refuses to invoice exempt when the ministry accreditation has expired', function () {
    // The exemption is a conditional privilege, not a property of the fee
    // item: invoicing tuition exempt on an expired arrêté is the exact
    // finding a contrôle looks for.
    f1TaxCompIdentity(['ministry_accreditation_expires_on' => '2026-06-30']);

    $exempt = TaxCode::factory()->create([
        'rate_bp' => 0,
        'is_exempt' => true,
        'exemption_legal_ref' => 'CGI art. 128 (à vérifier)',
        'exemption_condition' => 'ministry_accreditation',
        'affects_prorata_numerator' => false,
    ]);

    app(ComputeLineTax::class)->handle(500_000, (int) $exempt->id, '2026-09-01', TaxDirection::Output);
})->throws(DomainException::class, 'accreditation');

it('refuses the exemption when no accreditation is recorded at all', function () {
    f1TaxCompIdentity(['ministry_accreditation_number' => null]);

    $exempt = TaxCode::factory()->create([
        'rate_bp' => 0,
        'is_exempt' => true,
        'exemption_legal_ref' => 'CGI art. 128 (à vérifier)',
        'exemption_condition' => 'ministry_accreditation',
        'affects_prorata_numerator' => false,
    ]);

    app(EvaluateTaxCode::class)->handle($exempt, '2026-09-01');
})->throws(DomainException::class, 'accreditation');

it('invoices exempt with zero tax while the accreditation is valid', function () {
    f1TaxCompIdentity(['ministry_accreditation_expires_on' => '2027-08-31']);

    $exempt = TaxCode::factory()->create([
        'rate_bp' => 0,
        'is_exempt' => true,
        'exemption_legal_ref' => 'CGI art. 128 (à vérifier)',
        'exemption_condition' => 'ministry_accreditation',
        'affects_prorata_numerator' => false,
    ]);

    $lineTax = app(ComputeLineTax::class)->handle(500_000, (int) $exempt->id, '2026-09-01', TaxDirection::Output);

    expect($lineTax->taxAmount)->toBe(0)
        ->and($lineTax->deductible)->toBe(0)
        ->and($lineTax->nonDeductible)->toBe(0);
});

// ── Output computation and version selection ────────────────────────────

it('computes output TVA at the standard rate with no prorata involvement', function () {
    f1TaxCompIdentity();
    $taxCode = TaxCode::factory()->create(['rate_bp' => 19_250]);

    $lineTax = app(ComputeLineTax::class)->handle(1_000_000, (int) $taxCode->id, '2026-03-01', TaxDirection::Output);

    // 19.25% of 1 000 000 - collection is never limited by the prorata.
    expect($lineTax->taxAmount)->toBe(192_500)
        ->and($lineTax->deductible)->toBe(0)
        ->and($lineTax->nonDeductible)->toBe(0);
});

it('selects the tax code version by DOCUMENT date, not by the referenced row', function () {
    // §5.3 selection-date rule: a catalog default may point at the old
    // version; the document date picks the version that governs.
    f1TaxCompIdentity();

    $v1 = TaxCode::factory()->create([
        'code' => 'TVASTD',
        'rate_bp' => 19_250,
        'effective_from' => '2020-01-01',
        'effective_to' => '2027-01-01',
    ]);
    TaxCode::factory()->create([
        'code' => 'TVASTD',
        'rate_bp' => 20_000,
        'effective_from' => '2027-01-01',
    ]);

    $resolver = app(ResolveTaxCodeFor::class);

    expect($resolver->handle((int) $v1->id, '2026-12-31')->rate_bp)->toBe(19_250)
        ->and($resolver->handle((int) $v1->id, '2027-01-01')->rate_bp)->toBe(20_000);

    $lineTax = app(ComputeLineTax::class)->handle(100_000, (int) $v1->id, '2027-02-01', TaxDirection::Output);

    expect($lineTax->taxAmount)->toBe(20_000);
});

it('resolves each line of a batch against its own code', function () {
    f1TaxCompIdentity();

    $standard = TaxCode::factory()->create(['rate_bp' => 19_250]);
    $reduced = TaxCode::factory()->create(['rate_bp' => 10_000]);

    $resolved = app(ResolveTaxCodeFor::class)->forLines(
        [(int) $standard->id, (int) $reduced->id, (int) $standard->id],
        '2026-03-01',
    );

    expect($resolved)->toHaveCount(2)
        ->and($resolved[(int) $standard->id]->rate_bp)->toBe(19_250)
        ->and($resolved[(int) $reduced->id]->rate_bp)->toBe(10_000);
});

it('refuses an output-side code on an input line', function () {
    f1TaxCompIdentity();
    $taxCode = TaxCode::factory()->create(['direction' => 'output']);

    app(ComputeLineTax::class)->handle(100_000, (int) $taxCode->id, '2026-03-01', TaxDirection::Input);
})->throws(DomainException::class, 'output-side');
