<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\SchoolProfile\Livewire\Branding;
use App\Support\Branding\BrandPalette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

require_once __DIR__.'/../Reporting/P13CoreHelpers.php';

uses(RefreshDatabase::class);

/**
 * /settings/branding - the one brand-colour choice this platform gives a
 * school, per the migration's rationale: single-tenant-per-deployment, so
 * "branding" means picking a colour to replace the built-in Heritage green,
 * not a multi-tenant theme system.
 */
beforeEach(function (): void {
    // The seeding migration is what puts the setting row in place -
    // WriteSetting refuses to write a key that doesn't already exist, by
    // design (its own firstOrFail() contract).
    DB::table('settings')->updateOrInsert(
        ['key' => 'branding.primary_color', 'scope' => 'global'],
        [
            'value' => json_encode('#0B5A32'),
            'default_value' => json_encode('#0B5A32'),
            'value_type' => 'string',
            'setting_class' => 'cosmetic',
            'scope' => 'global',
            'validation_rule' => 'regex:/^#[0-9A-Fa-f]{6}$/',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );
});

it('derives a coherent chrome/chrome-light/primary trio from one colour', function (): void {
    $palette = BrandPalette::fromPrimary('#0B5A32');

    expect($palette['primary'])->toBe('#0B5A32')
        // chrome is the darkest of the three - the sidebar body.
        ->and($palette['chrome'])->not->toBe($palette['chromeLight'])
        ->and($palette['chromeLight'])->not->toBe($palette['primary']);

    // Darkening a channel toward black never overflows the 00-FF range.
    foreach ([$palette['chrome'], $palette['chromeLight']] as $hex) {
        expect($hex)->toMatch('/^#[0-9A-F]{6}$/');
    }
});

it('rejects a colour that is not a 6-digit hex triplet', function (): void {
    expect(fn () => BrandPalette::fromPrimary('not-a-color'))
        ->toThrow(InvalidArgumentException::class);
});

it('lets a user with setting.edit save a new brand colour', function (): void {
    p13coreUserAs(Role::Administrator);

    Livewire::test(Branding::class)
        ->set('primaryColor', '#7C3AED')
        ->call('save')
        ->assertHasNoErrors();

    expect(DB::table('settings')->where('key', 'branding.primary_color')->value('value'))
        ->toBe(json_encode('#7C3AED'));
});

it('rejects a save with an invalid hex value, through the real validation_rule', function (): void {
    p13coreUserAs(Role::Administrator);

    Livewire::test(Branding::class)
        ->set('primaryColor', 'javascript:alert(1)')
        ->call('save')
        ->assertHasErrors('primaryColor');

    // The bad value never reached the store - still the seeded default.
    expect(DB::table('settings')->where('key', 'branding.primary_color')->value('value'))
        ->toBe(json_encode('#0B5A32'));
});

it('resets the picker to the built-in Heritage green', function (): void {
    p13coreUserAs(Role::Administrator);

    Livewire::test(Branding::class)
        ->set('primaryColor', '#7C3AED')
        ->call('resetToHeritageGreen')
        ->assertSet('primaryColor', '#0B5A32');
});

it('refuses the screen to a user without setting.edit', function (): void {
    p13coreUserAs(Role::Teacher);

    get(route('settings.branding'))->assertForbidden();
});

it('applies the saved colour as an inline shell override on the next page load', function (): void {
    $user = p13coreUserAs(Role::Administrator);

    DB::table('settings')
        ->where('key', 'branding.primary_color')
        ->update(['value' => json_encode('#7C3AED')]);

    // Cache::rememberForever means the write path (Cache::forget in
    // WriteSetting) is what keeps this correct - a raw DB update here
    // deliberately bypasses that, so the assertion is really checking that
    // the shell reads the CURRENT row rather than trusting a stale cache
    // an earlier test left behind.
    \Illuminate\Support\Facades\Cache::forget(
        \App\Modules\SchoolProfile\Actions\ReadSetting::cacheKey('branding.primary_color', 'global', null)
    );

    actingAs($user);
    $response = get('/dashboard');

    $response->assertOk();
    $response->assertSee('--color-primary: #7C3AED', false);
});

it('falls back to the Heritage default when no override is set, so an unconfigured install is unaffected', function (): void {
    $user = p13coreUserAs(Role::Administrator);

    DB::table('settings')->where('key', 'branding.primary_color')->delete();
    \Illuminate\Support\Facades\Cache::forget(
        \App\Modules\SchoolProfile\Actions\ReadSetting::cacheKey('branding.primary_color', 'global', null)
    );

    actingAs($user);
    $response = get('/dashboard');

    $response->assertOk();
    $response->assertSee('--color-primary: #0B5A32', false);
});
