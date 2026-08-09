<?php

declare(strict_types=1);

use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Identity\Models\User;
use App\Modules\Tax\Actions\ComputeLineTax;
use App\Modules\Tax\Actions\ComputeVatProrata;
use App\Modules\Tax\Actions\ConfirmVatProrata;
use App\Modules\Tax\Domain\ProrataBasis;
use App\Modules\Tax\Domain\TaxDirection;
use App\Modules\Tax\Models\TaxCode;
use App\Modules\Tax\Models\VatProrata;
use App\Support\Audit\Actor;
use App\Support\Money\Money;
use App\Support\Rate\Rate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

if (! function_exists('f1TaxPrUserAs')) {
    function f1TaxPrUserAs(): User
    {
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('ledger.configure', 'web');

        $user = User::factory()->create();
        $user->givePermissionTo('ledger.configure');

        return $user->fresh() ?? $user;
    }
}

if (! function_exists('f1TaxPrActor')) {
    function f1TaxPrActor(User $user): Actor
    {
        return new Actor((int) $user->getKey(), $user->name);
    }
}

if (! function_exists('f1TaxPrSettings')) {
    /** Fixture: the accountant decided the rounding rule. */
    function f1TaxPrSettings(string $rounding = 'exact_bp'): void
    {
        DB::table('tax_settings')->updateOrInsert(['id' => 1], [
            'prorata_rounding' => $rounding,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('f1TaxPrIdentity')) {
    /** Fixture: a confirmed, TVA-registered fiscal identity. */
    function f1TaxPrIdentity(User $confirmedBy): void
    {
        DB::table('fiscal_identities')->insert([
            'id' => 1,
            'legal_name' => 'Collège Bilingue OPES',
            'legal_form' => 'etablissement_prive_laic',
            'niu' => 'M012345678901P',
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
            'fiscal_year_end_month' => 12,
            'fiscal_year_end_day' => 31,
            'fiscal_identity_confirmed_by' => $confirmedBy->getKey(),
            'fiscal_identity_confirmed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('f1TaxPrFiscalYear2026')) {
    function f1TaxPrFiscalYear2026(): FiscalYear
    {
        return FiscalYear::factory()->create([
            'code' => '2026',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-31',
        ]);
    }
}

// ── Blocking gates ──────────────────────────────────────────────────────

it('refuses to compute a prorata while the rounding rule is undecided', function () {
    // §12 item 8: whether the CGI rounds up to the whole percent is
    // unverified - the setting ships unset and BLOCKS, never assumes.
    $user = f1TaxPrUserAs();
    actingAs($user);
    $year = f1TaxPrFiscalYear2026();

    app(ComputeVatProrata::class)->handle(
        (int) $year->id,
        ProrataBasis::Provisional,
        34_000_000,
        290_000_000,
        actor: f1TaxPrActor($user),
    );
})->throws(DomainException::class, 'rounding rule is not configured');

it('refuses input-VAT splitting while no confirmed prorata exists', function () {
    // §11.16 empty-seed refusal: an unconfirmed prorata must never be
    // applied - and its ABSENCE must never mean 100% deduction.
    $user = f1TaxPrUserAs();
    actingAs($user);
    f1TaxPrSettings();
    f1TaxPrIdentity($user);
    $year = f1TaxPrFiscalYear2026();

    $taxCode = TaxCode::factory()->create(['direction' => 'input']);

    // Computed but NOT confirmed.
    app(ComputeVatProrata::class)->handle(
        (int) $year->id,
        ProrataBasis::Provisional,
        34_000_000,
        290_000_000,
        actor: f1TaxPrActor($user),
    );

    expect(fn () => app(ComputeLineTax::class)->handle(
        4_000_000,
        (int) $taxCode->id,
        '2026-03-10',
        TaxDirection::Input,
    ))->toThrow(DomainException::class, 'CONFIRMED prorata');
});

it('requires a reason for a manual prorata', function () {
    $user = f1TaxPrUserAs();
    actingAs($user);
    f1TaxPrSettings();
    $year = f1TaxPrFiscalYear2026();

    app(ComputeVatProrata::class)->handle(
        (int) $year->id,
        ProrataBasis::Provisional,
        34_000_000,
        290_000_000,
        source: 'manual',
        actor: f1TaxPrActor($user),
    );
})->throws(DomainException::class, 'reason');

it('rejects impossible turnover fractions', function () {
    $user = f1TaxPrUserAs();
    actingAs($user);
    f1TaxPrSettings();
    $year = f1TaxPrFiscalYear2026();

    $compute = app(ComputeVatProrata::class);

    expect(fn () => $compute->handle((int) $year->id, ProrataBasis::Provisional, 10, 0, actor: f1TaxPrActor($user)))
        ->toThrow(DomainException::class, 'denominator')
        ->and(fn () => $compute->handle((int) $year->id, ProrataBasis::Provisional, 11, 10, actor: f1TaxPrActor($user)))
        ->toThrow(DomainException::class, 'numerator');
});

// ── The §5.4 worked example, to the franc ───────────────────────────────

it('reproduces the §5.4 worked example to the franc', function () {
    // Taxable turnover 34 000 000 HT over total 290 000 000 HT →
    // 11.72% (1 172 per-10k points = 11 720 in Rate scale). Fuel invoice
    // HT 4 000 000, TVA 19.25% = 770 000:
    //   deductible     = round_half_up(770 000 × 11.72%) =  90 244
    //   non_deductible = 770 000 − 90 244               = 679 756
    // The subtraction, not a second rounding, conserves the franc.
    $user = f1TaxPrUserAs();
    actingAs($user);
    f1TaxPrSettings('exact_bp');
    f1TaxPrIdentity($user);
    $year = f1TaxPrFiscalYear2026();

    $prorata = app(ComputeVatProrata::class)->handle(
        (int) $year->id,
        ProrataBasis::Provisional,
        34_000_000,
        290_000_000,
        actor: f1TaxPrActor($user),
    );

    expect($prorata->rate_bp)->toBe(11_720)
        ->and($prorata->rate()->toPercentString())->toBe('11.72');

    app(ConfirmVatProrata::class)->handle((int) $prorata->getKey(), f1TaxPrActor($user));

    $taxCode = TaxCode::factory()->create(['direction' => 'input', 'rate_bp' => 19_250]);

    $lineTax = app(ComputeLineTax::class)->handle(
        4_000_000,
        (int) $taxCode->id,
        '2026-03-10',
        TaxDirection::Input,
    );

    expect($lineTax->taxAmount)->toBe(770_000)
        ->and($lineTax->deductible)->toBe(90_244)
        ->and($lineTax->nonDeductible)->toBe(679_756)
        ->and($lineTax->deductible + $lineTax->nonDeductible)->toBe($lineTax->taxAmount);
});

it('rounds up to the whole percent when the accountant configured that rule', function () {
    $user = f1TaxPrUserAs();
    actingAs($user);
    f1TaxPrSettings('up_to_whole_percent');
    $year = f1TaxPrFiscalYear2026();

    $prorata = app(ComputeVatProrata::class)->handle(
        (int) $year->id,
        ProrataBasis::Provisional,
        34_000_000,
        290_000_000,
        actor: f1TaxPrActor($user),
    );

    // 11.7241…% ceils to 12%.
    expect($prorata->rate_bp)->toBe(12_000)
        ->and($prorata->rate()->toPercentString())->toBe('12.00');
});

// ── Conservation properties ─────────────────────────────────────────────

it('conserves every franc across the deductible split for random tax amounts', function () {
    // §11 test obligation 2 - Σ deductible + Σ non_deductible = tax_total,
    // property-tested. Seeded RNG so a failure reproduces.
    mt_srand(20_260_809);
    $prorata = Rate::ofBasisPoints(11_720);

    for ($i = 0; $i < 200; $i++) {
        $tax = mt_rand(1, 500_000_000);

        $deductible = $prorata->applyTo(Money::of($tax));
        $nonDeductible = Money::of($tax)->minus($deductible);

        expect($deductible->amount() + $nonDeductible->amount())->toBe($tax)
            ->and($nonDeductible->isNegative())->toBeFalse();
    }
});

it('conserves through Money::allocate for the prorata ratio', function () {
    // Money::allocate splits with no franc lost or created - the same
    // allocator the worked paper uses to spread the regularisation.
    mt_srand(20_260_810);

    for ($i = 0; $i < 100; $i++) {
        $tax = Money::of(mt_rand(1, 500_000_000));

        [$deductible, $nonDeductible] = $tax->allocate([1_172, 8_828]);

        expect($deductible->plus($nonDeductible)->equals($tax))->toBeTrue();
    }
});

// ── Confirmation discipline ─────────────────────────────────────────────

it('refuses to recompute a confirmed prorata in place', function () {
    // Deductions were taken against it; the provisional→definitive delta
    // belongs to the year-end regularisation (§5.4.3), not an overwrite.
    $user = f1TaxPrUserAs();
    actingAs($user);
    f1TaxPrSettings();
    $year = f1TaxPrFiscalYear2026();

    $prorata = app(ComputeVatProrata::class)->handle(
        (int) $year->id,
        ProrataBasis::Provisional,
        34_000_000,
        290_000_000,
        actor: f1TaxPrActor($user),
    );
    app(ConfirmVatProrata::class)->handle((int) $prorata->getKey(), f1TaxPrActor($user));

    expect(fn () => app(ComputeVatProrata::class)->handle(
        (int) $year->id,
        ProrataBasis::Provisional,
        40_000_000,
        290_000_000,
        actor: f1TaxPrActor($user),
    ))->toThrow(DomainException::class, 'already confirmed');
});

it('recomputes an unconfirmed prorata in place and keeps one row per year and basis', function () {
    $user = f1TaxPrUserAs();
    actingAs($user);
    f1TaxPrSettings();
    $year = f1TaxPrFiscalYear2026();

    $first = app(ComputeVatProrata::class)->handle(
        (int) $year->id,
        ProrataBasis::Provisional,
        34_000_000,
        290_000_000,
        actor: f1TaxPrActor($user),
    );

    $second = app(ComputeVatProrata::class)->handle(
        (int) $year->id,
        ProrataBasis::Provisional,
        40_000_000,
        290_000_000,
        actor: f1TaxPrActor($user),
    );

    expect($second->getKey())->toBe($first->getKey())
        ->and(VatProrata::query()->count())->toBe(1)
        // 40/290 = 13.7931…% → 13.79%.
        ->and($second->rate_bp)->toBe(13_790);
});

it('prefers the confirmed definitive prorata over the provisional one', function () {
    $user = f1TaxPrUserAs();
    actingAs($user);
    f1TaxPrSettings();
    f1TaxPrIdentity($user);
    $year = f1TaxPrFiscalYear2026();

    $provisional = app(ComputeVatProrata::class)->handle(
        (int) $year->id,
        ProrataBasis::Provisional,
        34_000_000,
        290_000_000,
        actor: f1TaxPrActor($user),
    );
    app(ConfirmVatProrata::class)->handle((int) $provisional->getKey(), f1TaxPrActor($user));

    $definitive = app(ComputeVatProrata::class)->handle(
        (int) $year->id,
        ProrataBasis::Definitive,
        50_000_000,
        250_000_000,
        actor: f1TaxPrActor($user),
    );
    app(ConfirmVatProrata::class)->handle((int) $definitive->getKey(), f1TaxPrActor($user));

    $taxCode = TaxCode::factory()->create(['direction' => 'input', 'rate_bp' => 19_250]);

    $lineTax = app(ComputeLineTax::class)->handle(
        1_000_000,
        (int) $taxCode->id,
        '2026-06-15',
        TaxDirection::Input,
    );

    // 50/250 = 20% definitive, not 11.72% provisional:
    // tax 192 500 → deductible 38 500.
    expect($lineTax->taxAmount)->toBe(192_500)
        ->and($lineTax->deductible)->toBe(38_500)
        ->and($lineTax->nonDeductible)->toBe(154_000);
});
