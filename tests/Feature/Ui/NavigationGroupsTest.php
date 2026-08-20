<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Support\Navigation;

/**
 * The sidebar's eighteen top-level groups are a PRESENTATION layer over
 * Navigation::items(), and this is what keeps them one.
 *
 * The failure this exists to prevent is specific and silent: the sidebar is
 * the product's whole permission surface, so an item that falls out of the
 * grouping does not error - it simply stops being reachable for every role
 * that holds it, and nothing in the suite notices. Grouping is the kind of
 * change that gets made in a hurry (a module is added, a group is renamed),
 * which is exactly when a list gets forgotten.
 */
it('places every navigation item in exactly one group', function (): void {
    $itemKeys = array_column(Navigation::items(), 'key');
    $grouped = array_merge(...array_values(Navigation::groups()));

    expect(array_values(array_diff($itemKeys, $grouped)))
        ->toBe([], 'Every nav item must appear in Navigation::groups().');

    expect(array_values(array_diff($grouped, $itemKeys)))
        ->toBe([], 'Navigation::groups() must not name an item that does not exist.');

    // Once, not twice: an item in two groups renders in two places in the
    // sidebar, which reads as a duplicate module rather than a shortcut.
    $duplicates = array_values(array_unique(array_diff_assoc($grouped, array_unique($grouped))));

    expect($duplicates)->toBe([], 'No nav item may appear in more than one group.');
});

it('gives every group a label in every locale', function (): void {
    foreach (array_keys(Navigation::groups()) as $groupKey) {
        foreach (['en', 'fr'] as $locale) {
            app()->setLocale($locale);

            $key = 'opes.nav_group.'.$groupKey;

            // __() returns the KEY itself when the translation is missing,
            // which renders as "opes.nav_group.boarding" in the sidebar -
            // visibly broken, but only to whoever happens to load that
            // locale.
            expect(__($key))->not->toBe($key, "Missing {$locale} label for nav group '{$groupKey}'.");
        }
    }

    app()->setLocale('en');
});

it('hides a group entirely when the reader may see none of its items', function (): void {
    // A holder of nothing: groupedItems() must return only groups whose
    // members are ungated, never an empty disclosure that opens onto
    // nothing.
    $groups = Navigation::groupedItems(static fn (Permission $permission): bool => false);

    foreach ($groups as $group) {
        expect($group['items'])->not->toBe([], "Group '{$group['key']}' rendered with no items.");

        foreach ($group['items'] as $item) {
            expect($item['permission'])->toBeNull();
        }
    }
});

it('keeps every item a permitted reader holds', function (): void {
    // The inverse: a holder of everything must be offered every item, in
    // some group. This is the assertion that actually catches an item
    // dropped during a regrouping.
    $groups = Navigation::groupedItems(static fn (Permission $permission): bool => true);

    $offered = [];

    foreach ($groups as $group) {
        foreach ($group['items'] as $item) {
            $offered[] = $item['key'];
        }
    }

    sort($offered);
    $expected = array_column(Navigation::items(), 'key');
    sort($expected);

    expect($offered)->toBe($expected);
});

it('gives every navigation item a label in every locale', function (): void {
    /*
     * Found in the running product, not in review: `accounting_dashboard`
     * had no entry in either locale, so the sidebar rendered the literal
     * string "opes.nav.accounting_dashboard" to anyone with ledger.view.
     * __() returns the KEY when a translation is missing, which means this
     * class of bug is always visible and never throws - exactly the kind
     * that survives until someone happens to look at the right row.
     */
    foreach (Navigation::items() as $item) {
        foreach (['en', 'fr'] as $locale) {
            app()->setLocale($locale);

            $key = 'opes.nav.'.$item['key'];

            expect(__($key))->not->toBe($key, "Missing {$locale} label for nav item '{$item['key']}'.");
        }
    }

    app()->setLocale('en');
});
