<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
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

it('clears the Heritage default on the two colours the shell chrome is built from', function (): void {
    p13coreUserAs(Role::Administrator);

    // The plan expected NO warnings at all for the built-in palette. Measured
    // against WCAG 2.1, that is arithmetically false: Heritage's shipped amber
    // (#D99A20) is 2.44:1 on white and its red (#D64545) is 4.38:1, both under
    // the 4.5 AA floor. Those are real - `bg-warning-bg text-warning` is a
    // pattern this codebase actually renders - so the check stays honest and
    // this test asserts the truth instead.
    //
    // What must hold is that the colours a school PICKS and the shell paints
    // its chrome, buttons and table headers from are clean.
    $flagged = array_column(
        Livewire::test(Branding::class)->instance()->contrastWarnings(),
        'token'
    );

    expect($flagged)->not->toContain('primary')
        ->and($flagged)->not->toContain('secondary')
        ->and($flagged)->not->toContain('success')
        ->and($flagged)->not->toContain('accent');
});
