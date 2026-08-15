<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\SchoolProfile\Domain\BrandPreset;
use App\Modules\SchoolProfile\Livewire\Branding;
use App\Support\Branding\BrandTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/../Reporting/P13CoreHelpers.php';

uses(RefreshDatabase::class);

it('loads the seeded Heritage palette', function (): void {
    p13coreUserAs(Role::Administrator);

    Livewire::test(Branding::class)
        ->assertSet('primary', '#0B5A32')
        ->assertSet('accent', '#D9A829');
});

it('saves the whole palette as one settings key and mirrors the primary', function (): void {
    p13coreUserAs(Role::Administrator);

    Livewire::test(Branding::class)
        ->set('primary', '#1B3A6B')
        ->set('secondary', '#132B50')
        ->call('save')
        ->assertDispatched('settings-saved');

    $palette = json_decode((string) DB::table('settings')->where('key', 'branding.palette')->value('value'), true);

    expect($palette['primary'])->toBe('#1B3A6B')
        ->and($palette['secondary'])->toBe('#132B50')
        // The mirror: the shell layout and the old screen both read this key.
        ->and(json_decode((string) DB::table('settings')->where('key', 'branding.primary_color')->value('value'), true))
        ->toBe('#1B3A6B');
});

it('refuses a malformed hex without writing anything', function (): void {
    p13coreUserAs(Role::Administrator);

    Livewire::test(Branding::class)
        ->set('primary', 'not-a-colour')
        ->call('save')
        ->assertHasErrors('primary');

    $palette = json_decode((string) DB::table('settings')->where('key', 'branding.palette')->value('value'), true);

    expect($palette['primary'])->toBe(BrandTokens::DEFAULTS['primary']);
});

it('applies a preset to every token in one click', function (): void {
    p13coreUserAs(Role::Administrator);

    Livewire::test(Branding::class)
        ->call('applyPreset', 'navy')
        ->assertSet('primary', '#1B3A6B')
        ->assertSet('secondary', '#132B50');
});

it('reports a contrast failure for the chosen primary on white', function (): void {
    p13coreUserAs(Role::Administrator);

    // Heritage Gold on white is ~1.9:1 - the exact mistake the warning exists
    // to catch.
    $component = Livewire::test(Branding::class)->set('primary', '#D9A829');

    expect($component->instance()->contrastWarnings())->not->toBeEmpty();
});

it('raises no contrast warning at all on a fresh unconfigured install', function (): void {
    p13coreUserAs(Role::Administrator);

    // This test used to DOCUMENT a failure: the shipped amber measured 2.44:1
    // on white and the check flagged it, so a school that had changed nothing
    // was greeted by a warning about a colour it never picked and could not
    // fix. Both halves of that were wrong.
    //
    // The check was measuring the wrong pair - amber is never body text at
    // its vivid value, it is a FILL - and the real failure was one nobody was
    // measuring: `bg-warning-bg text-warning`, amber on its own tint, at
    // 2.25:1. That is now a separate, derived TEXT role that clears AA by
    // construction, so the built-in palette is genuinely clean and the
    // warning means something again.
    expect(Livewire::test(Branding::class)->instance()->contrastWarnings())->toBe([]);
});

it('raises no contrast warning for any preset the platform itself offers', function (): void {
    p13coreUserAs(Role::Administrator);

    // A preset the platform endorses must never be one its own warning flags.
    foreach (BrandPreset::all() as $preset) {
        expect(
            Livewire::test(Branding::class)
                ->call('applyPreset', $preset['key'])
                ->instance()
                ->contrastWarnings()
        )->toBe([], "preset [{$preset['key']}] raises a contrast warning");
    }
});
