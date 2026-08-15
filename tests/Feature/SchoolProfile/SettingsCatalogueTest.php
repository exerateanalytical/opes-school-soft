<?php

declare(strict_types=1);

use App\Modules\SchoolProfile\Domain\SettingsCatalogue;
use Illuminate\Support\Facades\Route;

/**
 * The nav contract (00-core 6.2): a link the holder's permission refuses is
 * the one thing the shell may never offer. The catalogue is the single list
 * the hub renders from, so every entry must name a route that EXISTS and a
 * permission string the Gate actually knows.
 */
it('names only routes that exist', function (): void {
    foreach (SettingsCatalogue::cards() as $card) {
        expect(Route::has($card['route']))->toBeTrue("route [{$card['route']}] is missing");
    }
});

it('gives every card a permission, an icon and lang keys', function (): void {
    foreach (SettingsCatalogue::cards() as $card) {
        expect($card['permission'])->toBeString()->not->toBe('')
            ->and($card['icon'])->toBeString()->not->toBe('')
            ->and($card['title_key'])->toStartWith('opes.settings_hub.')
            ->and($card['description_key'])->toStartWith('opes.settings_hub.');
    }
});

it('uses a stable, unique key per card', function (): void {
    $keys = array_column(SettingsCatalogue::cards(), 'key');

    expect($keys)->toBe(array_unique($keys))
        ->and($keys)->toContain('school_identity', 'branding', 'fiscal', 'tax', 'licence', 'academic', 'go_live', 'advanced');
});
