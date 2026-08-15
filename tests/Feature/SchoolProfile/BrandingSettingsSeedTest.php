<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\SchoolProfile\Actions\WriteSetting;
use App\Support\Branding\BrandTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Reporting/P13CoreHelpers.php';

uses(RefreshDatabase::class);

it('seeds the palette, app logo and favicon keys', function (): void {
    foreach (['branding.palette', 'branding.app_logo_path', 'branding.favicon_path'] as $key) {
        expect(DB::table('settings')->where('key', $key)->where('scope', 'global')->exists())
            ->toBeTrue("setting [{$key}] was not seeded");
    }
});

it('seeds the palette as the Heritage defaults', function (): void {
    $raw = DB::table('settings')->where('key', 'branding.palette')->value('value');

    // MySQL normalises JSON object key ORDER on storage, so this compares the
    // mapping, not the literal key sequence.
    expect(json_decode((string) $raw, true))->toEqualCanonicalizing(BrandTokens::DEFAULTS);
});

it('lets WriteSetting write the seeded palette key', function (): void {
    // WriteSetting::handle() does firstOrFail(): an unseeded key can never be
    // written at all, which is the whole reason this migration exists.
    $user = p13coreUserAs(Role::Administrator);

    app(WriteSetting::class)->handle(
        'branding.palette',
        ['primary' => '#123456'] + BrandTokens::DEFAULTS,
        $user->toAuditActor(),
    );

    $raw = DB::table('settings')->where('key', 'branding.palette')->value('value');

    expect(json_decode((string) $raw, true)['primary'])->toBe('#123456');
});
