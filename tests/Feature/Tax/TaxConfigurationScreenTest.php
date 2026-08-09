<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Tax\Livewire\FiscalIdentity as FiscalIdentityScreen;
use App\Modules\Tax\Livewire\TaxConfiguration;
use App\Modules\Tax\Models\FiscalIdentity;
use App\Modules\Tax\Models\TaxCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/*
 * Routes for /settings/fiscal-identity and /settings/tax are wired by
 * Agent F5's pass (phase-05 plan §5 - F5 owns routes/web.php); these tests
 * exercise the components directly, the permission gate included.
 */

if (! function_exists('f1TaxUiUserAs')) {
    function f1TaxUiUserAs(bool $withPermission = true): User
    {
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('ledger.configure', 'web');

        $user = User::factory()->create();

        if ($withPermission) {
            $user->givePermissionTo('ledger.configure');
        }

        return $user->fresh() ?? $user;
    }
}

// ── TaxConfiguration ────────────────────────────────────────────────────

it('forbids the tax configuration screen without ledger.configure', function () {
    actingAs(f1TaxUiUserAs(withPermission: false));

    Livewire::test(TaxConfiguration::class)->assertForbidden();
});

it('shows every not-configured badge on a virgin system', function () {
    // 00-core §16 made visible: the empty-and-blocking state must be
    // legible on the screen, not a surprise at the first refusal.
    actingAs(f1TaxUiUserAs());

    Livewire::test(TaxConfiguration::class)
        ->assertSee('Fiscal identity: not confirmed')
        ->assertSee('Tax codes: not configured')
        ->assertSee('Withholding rules: not configured')
        ->assertSee('Withholding recognition (invoice vs payment): not decided')
        ->assertSee('Prorata rounding rule: not decided')
        ->assertViewHas('taxCodesConfigured', false)
        ->assertViewHas('withholdingConfigured', false)
        ->assertViewHas('identityConfigured', false);
});

it('lists a configured tax code with its rate on the tax codes tab', function () {
    actingAs(f1TaxUiUserAs());

    $taxCode = TaxCode::factory()->create(['rate_bp' => 19_250]);

    Livewire::test(TaxConfiguration::class)
        ->assertSee($taxCode->code)
        ->assertSee('19.25')
        ->assertViewHas('taxCodesConfigured', true);
});

it('switches tabs and rejects an unknown tab name', function () {
    actingAs(f1TaxUiUserAs());

    Livewire::test(TaxConfiguration::class)
        ->call('setTab', 'withholding')
        ->assertSet('tab', 'withholding')
        ->assertSee('Recognition basis')
        ->call('setTab', 'nonsense')
        ->assertSet('tab', 'withholding');
});

it('shows the seeded DSF obligation with its verified due rule', function () {
    // The ONE seeded obligation (§7.5, verified dates) - everything else
    // waits for the accountant.
    actingAs(f1TaxUiUserAs());

    Livewire::test(TaxConfiguration::class)
        ->call('setTab', 'obligations')
        ->assertSee('Déclaration Statistique et Fiscale')
        ->assertSee('tax_centre_dependent(DGE=03-15,CIME=04-15,other=05-15)')
        ->assertSee('never files');
});

// ── FiscalIdentity screen ───────────────────────────────────────────────

it('forbids the fiscal identity screen without ledger.configure', function () {
    actingAs(f1TaxUiUserAs(withPermission: false));

    Livewire::test(FiscalIdentityScreen::class)->assertForbidden();
});

it('shows the blocking banner while the identity is unconfirmed', function () {
    actingAs(f1TaxUiUserAs());

    Livewire::test(FiscalIdentityScreen::class)
        ->assertSee('Not confirmed yet')
        ->assertSee('blocked until');
});

it('refuses to save without the confirmation checkbox', function () {
    actingAs(f1TaxUiUserAs());

    Livewire::test(FiscalIdentityScreen::class)
        ->set('legalName', 'Collège Bilingue OPES')
        ->call('save')
        ->assertSet('confirmChecked', false);

    expect(FiscalIdentity::current())->toBeNull();
});

it('surfaces the domain refusal verbatim for an incomplete identity', function () {
    actingAs(f1TaxUiUserAs());

    $component = Livewire::test(FiscalIdentityScreen::class)
        ->set('legalName', 'Collège Bilingue OPES')
        ->set('confirmChecked', true)
        ->call('save');

    /** @var string $error */
    $error = $component->get('errorMessage');

    expect($error)->toContain('incomplete');
    expect(FiscalIdentity::current())->toBeNull();
});

it('confirms a complete identity from the screen', function () {
    actingAs(f1TaxUiUserAs());

    Livewire::test(FiscalIdentityScreen::class)
        ->set('legalName', 'Collège Bilingue OPES')
        ->set('legalForm', 'etablissement_prive_laic')
        ->set('niu', 'M012345678901U')
        ->set('taxCentreCode', 'CIME-YDE1')
        ->set('taxCentreName', 'CIME Yaoundé 1er')
        ->set('taxCentreType', 'CIME')
        ->set('taxRegime', 'reel')
        ->set('taxRegimeEffectiveFrom', '2020-01-01')
        ->set('ministryAccreditationNumber', 'ARR-2015-0042')
        ->set('ministryAccreditationAuthority', 'MINESEC')
        ->set('ministryAccreditationDate', '2015-09-01')
        ->set('confirmChecked', true)
        ->call('save')
        ->assertSet('errorMessage', '')
        ->assertSet('successMessage', 'Fiscal identity confirmed.');

    $identity = FiscalIdentity::current();

    expect($identity)->not->toBeNull()
        ->and($identity?->isConfirmed())->toBeTrue()
        ->and($identity?->niu)->toBe('M012345678901U');
});

it('prefills the form from the confirmed identity on mount', function () {
    $user = f1TaxUiUserAs();
    actingAs($user);

    Livewire::test(FiscalIdentityScreen::class)
        ->set('legalName', 'Collège Bilingue OPES')
        ->set('legalForm', 'etablissement_prive_laic')
        ->set('niu', 'M012345678901U')
        ->set('taxCentreCode', 'CIME-YDE1')
        ->set('taxCentreName', 'CIME Yaoundé 1er')
        ->set('taxCentreType', 'CIME')
        ->set('taxRegime', 'reel')
        ->set('taxRegimeEffectiveFrom', '2020-01-01')
        ->set('ministryAccreditationNumber', 'ARR-2015-0042')
        ->set('ministryAccreditationAuthority', 'MINESEC')
        ->set('ministryAccreditationDate', '2015-09-01')
        ->set('confirmChecked', true)
        ->call('save');

    Livewire::test(FiscalIdentityScreen::class)
        ->assertSet('niu', 'M012345678901U')
        ->assertSet('taxRegime', 'reel')
        ->assertSee('Confirmed on');
});
