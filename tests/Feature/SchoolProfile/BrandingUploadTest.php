<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\SchoolProfile\Livewire\Branding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Reporting/P13CoreHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

/** The stored settings value, decoded. */
function brandingSettingValue(string $key): mixed
{
    return json_decode((string) DB::table('settings')->where('key', $key)->value('value'), true);
}

it('stores an uploaded app logo and persists its path in settings', function (): void {
    p13coreUserAs(Role::Administrator);

    Livewire::test(Branding::class)
        ->set('appLogoUpload', UploadedFile::fake()->image('logo.png', 300, 100))
        ->call('save')
        ->assertHasNoErrors();

    $path = brandingSettingValue('branding.app_logo_path');

    expect($path)->toStartWith('branding/app-logo-')
        ->and(Storage::disk('public')->exists((string) $path))->toBeTrue();
});

it('stores an uploaded favicon', function (): void {
    p13coreUserAs(Role::Administrator);

    Livewire::test(Branding::class)
        ->set('faviconUpload', UploadedFile::fake()->image('icon.png', 64, 64))
        ->call('save')
        ->assertHasNoErrors();

    expect(brandingSettingValue('branding.favicon_path'))->toStartWith('branding/favicon-');
});

it('refuses an SVG logo', function (): void {
    p13coreUserAs(Role::Administrator);

    Livewire::test(Branding::class)
        ->set('appLogoUpload', UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml'))
        ->call('save')
        ->assertHasErrors('appLogoUpload');
});

it('refuses a favicon larger than 512px, which is a logo in the wrong slot', function (): void {
    p13coreUserAs(Role::Administrator);

    Livewire::test(Branding::class)
        ->set('faviconUpload', UploadedFile::fake()->image('big.png', 900, 900))
        ->call('save')
        ->assertHasErrors('faviconUpload');
});

it('clears the logo when the operator removes it', function (): void {
    p13coreUserAs(Role::Administrator);

    Livewire::test(Branding::class)
        ->set('appLogoUpload', UploadedFile::fake()->image('logo.png', 300, 100))
        ->call('save');

    $stored = (string) brandingSettingValue('branding.app_logo_path');

    Livewire::test(Branding::class)
        ->call('removeAppLogo')
        ->call('save');

    expect(brandingSettingValue('branding.app_logo_path'))->toBe('')
        // The shell logo carries no reproducibility burden, so the file goes
        // with the setting that pointed at it.
        ->and(Storage::disk('public')->exists($stored))->toBeFalse();
});
