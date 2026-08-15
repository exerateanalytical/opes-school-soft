<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\SchoolProfile\Actions\WriteSetting;
use App\Support\Branding\BrandTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\get;

require_once __DIR__.'/../Reporting/P13CoreHelpers.php';

uses(RefreshDatabase::class);

it('emits every palette token as an unlayered custom property', function (): void {
    $user = p13coreUserAs(Role::Administrator);

    app(WriteSetting::class)->handle(
        'branding.palette',
        BrandTokens::fromArray(['primary' => '#1B3A6B', 'secondary' => '#132B50'] + BrandTokens::DEFAULTS)->all(),
        $user->toAuditActor(),
    );

    $html = get('/settings')->assertOk()->getContent();

    expect($html)
        ->toContain('--color-primary: #1B3A6B')
        ->toContain('--color-chrome-light: #132B50')
        ->toContain('--color-heritage-yellow: #D9A829')
        // Unlayered. Tailwind 4 compiles utilities into @layer utilities and
        // unlayered CSS outranks every layered rule regardless of
        // specificity; wrapping this in @layer would ship a silent no-op.
        ->not->toContain('@layer');
});

it('renders the app logo in the shell when one is set', function (): void {
    $user = p13coreUserAs(Role::Administrator);

    app(WriteSetting::class)->handle('branding.app_logo_path', 'branding/logo-abc123.png', $user->toAuditActor());

    get('/settings')->assertOk()->assertSee('branding/logo-abc123.png', false);
});

it('renders a favicon link when one is set', function (): void {
    $user = p13coreUserAs(Role::Administrator);

    app(WriteSetting::class)->handle('branding.favicon_path', 'branding/icon-abc123.png', $user->toAuditActor());

    get('/settings')->assertOk()->assertSee('rel="icon"', false);
});

it('falls back to the Heritage defaults when the palette row is missing', function (): void {
    p13coreUserAs(Role::Administrator);

    DB::table('settings')->where('key', 'branding.palette')->delete();

    get('/settings')->assertOk()->assertSee('--color-primary: #0B5A32', false);
});
