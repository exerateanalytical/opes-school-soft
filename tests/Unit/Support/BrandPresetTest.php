<?php

declare(strict_types=1);

use App\Modules\SchoolProfile\Domain\BrandPreset;
use App\Support\Branding\BrandTokens;
use App\Support\Branding\ColorContrast;

it('offers at least six presets, each with a stable key and a label', function (): void {
    $presets = BrandPreset::all();

    expect(count($presets))->toBeGreaterThanOrEqual(6);

    $keys = array_column($presets, 'key');
    expect($keys)->toBe(array_unique($keys))->toContain('heritage');

    foreach ($presets as $preset) {
        expect($preset['label'])->toBeString()->not->toBe('');
    }
});

it('ships only presets whose primary is readable on white', function (): void {
    // A preset the platform itself offers must never be one the contrast
    // warning would then flag. Shipping an unreadable preset is worse than
    // letting a school pick one by hand - it reads as an endorsement.
    foreach (BrandPreset::all() as $preset) {
        $tokens = BrandTokens::fromArray($preset['colors']);

        expect(ColorContrast::passesAA($tokens->get('primary'), '#FFFFFF'))
            ->toBeTrue("preset [{$preset['key']}] primary fails AA on white");
    }
});

it('builds valid BrandTokens from every preset', function (): void {
    foreach (BrandPreset::all() as $preset) {
        expect(BrandTokens::fromArray($preset['colors'])->all())
            ->toHaveKeys(array_keys(BrandTokens::DEFAULTS));
    }
});

it('returns the Heritage preset by key', function (): void {
    expect(BrandPreset::find('heritage')['colors']['primary'])->toBe('#0B5A32');
});

it('returns null for an unknown key', function (): void {
    expect(BrandPreset::find('not-a-preset'))->toBeNull();
});
