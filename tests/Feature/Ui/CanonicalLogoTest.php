<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\SchoolProfile\Actions\ResolveSchoolLogo;
use App\Modules\SchoolProfile\Actions\WriteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\get;

require_once __DIR__.'/../Reporting/P13CoreHelpers.php';
require_once __DIR__.'/../Guardians/P12PortalScreensHelpers.php';

uses(RefreshDatabase::class);

/*
 * ONE uploaded logo, read by every surface that shows one. Before
 * ResolveSchoolLogo the sign-in page hardcoded the built-in OPES mark, the
 * portal drew its own crest, and documents read a second column - so a school
 * that uploaded its logo saw it in exactly one of the four places.
 */

function canonLogoSetting(string $path): void
{
    $user = p13coreUserAs(Role::Administrator);

    app(WriteSetting::class)->handle('branding.app_logo_path', $path, $user->toAuditActor());
}

// ------------------------------------------------------------ precedence --

it('resolves the branding setting ahead of the document profile logo', function (): void {
    canonLogoSetting('branding/app-logo-1111111111111111.png');
    p13coreDocumentProfile(['logo_path' => 'branding/logo-2222222222222222.png']);

    expect(app(ResolveSchoolLogo::class)->handle())
        ->toBe('branding/app-logo-1111111111111111.png');
});

it('falls back to the document profile logo when no platform logo is uploaded', function (): void {
    p13coreUserAs(Role::Administrator);
    p13coreDocumentProfile(['logo_path' => 'branding/logo-2222222222222222.png']);

    expect(app(ResolveSchoolLogo::class)->handle())
        ->toBe('branding/logo-2222222222222222.png');
});

it('resolves to null when the school has uploaded nothing', function (): void {
    p13coreUserAs(Role::Administrator);
    p13coreDocumentProfile(['logo_path' => null]);

    expect(app(ResolveSchoolLogo::class)->handle())->toBeNull()
        ->and(app(ResolveSchoolLogo::class)->url())->toBeNull();
});

it('refuses a path outside the branding directory', function (): void {
    // Both sources are operator-editable text. A hand-edited value must never
    // become an arbitrary <img src>.
    p13coreUserAs(Role::Administrator);
    p13coreDocumentProfile(['logo_path' => '../../.env']);

    expect(app(ResolveSchoolLogo::class)->handle())->toBeNull();
});

// ---------------------------------------------------------------- shell --

it('shows the uploaded logo in the app shell', function (): void {
    canonLogoSetting('branding/app-logo-1111111111111111.png');

    get('/settings')->assertOk()->assertSee('branding/app-logo-1111111111111111.png', false);
});

// ------------------------------------------------------------- sign-in --

it('shows the uploaded logo on the sign-in page instead of the built-in mark', function (): void {
    canonLogoSetting('branding/app-logo-1111111111111111.png');

    auth()->logout();

    $html = get('/login')->assertOk()->getContent();

    expect($html)
        ->toContain('branding/app-logo-1111111111111111.png')
        // The hardcoded crest's gold star path is the marker for the built-in
        // mark; with a school logo present it must be gone entirely.
        ->not->toContain('M12 8.1l1.15 2.35');
});

it('keeps the built-in mark on the sign-in page for a school with no logo', function (): void {
    $html = (string) get('/login')->assertOk()->getContent();

    /*
     * The MARK, in whichever form it takes - not one specific SVG path.
     *
     * This asserted `M12 8.1l1.15 2.35`, the star from the drawn fallback in
     * guest.blade.php. The sign-in page moved to layouts.auth-wide and
     * x-portal.crest-mark, which prefers the real artwork
     * (public/images/opes-crest*.png) and only draws that SVG when the files
     * are absent. Both are the built-in mark; the test was pinned to the one
     * the page had stopped using, and had been failing ever since.
     *
     * The contract is unchanged and still checked: with no uploaded logo the
     * page shows the OPES mark, and it must NOT be pointing at a branding
     * upload.
     */
    expect($html)
        ->toMatch('/opes-crest(-dark)?\.png|M12 8\.1l1\.15 2\.35/')
        ->not->toContain('branding/app-logo-');
});

// -------------------------------------------------------------- portal --

it('shows the same uploaded logo in the guardian portal header', function (): void {
    canonLogoSetting('branding/app-logo-1111111111111111.png');
    auth()->logout();

    p12scrPortalGuardian();

    get(route('portal.dashboard'))->assertOk()
        ->assertSee('branding/app-logo-1111111111111111.png', false);
});

it('draws the portal crest when the school has uploaded no logo', function (): void {
    DB::table('settings')->where('key', 'branding.app_logo_path')->update(['value' => '""']);

    p12scrPortalGuardian();

    // The drawn crest's laurel path - a fresh install must show a mark, never
    // a broken image.
    get(route('portal.dashboard'))->assertOk()
        ->assertSee('M17 26c-4 6-4 14 1 20', false);
});
