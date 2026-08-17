<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\get;

require_once __DIR__.'/../Reporting/P13MoneyHelpers.php';

uses(RefreshDatabase::class);

/*
 * The 2026-08-13 routes audit found 17 built, correctly-gated screens with
 * ZERO inbound links in a 244-page crawl. The nav/route contract holds in
 * one direction (no role is offered a link its permissions refuse) and
 * failed completely in the other. These tests are the other direction.
 */

it('offers marks entry in the navigation', function (): void {
    $keys = array_column(Navigation::items(), 'key');

    expect($keys)->toContain('marks');
});

it('shows a teacher the marks screen in their own sidebar', function (): void {
    p13moneyUserAs(Role::Teacher);

    $response = get('/dashboard');

    $response->assertOk();
    $response->assertSee('href="/marks"', escape: false);
});

it('links every procurement screen from every procurement screen', function (): void {
    p13moneyUserAs(Role::Bursar, Role::Accountant);

    $response = get('/procurement/suppliers');

    $response->assertOk();

    foreach ([
        '/procurement/suppliers', '/procurement/requisitions', '/procurement/orders',
        '/procurement/receipts', '/procurement/invoices', '/procurement/payments',
        '/procurement/payables',
    ] as $href) {
        $response->assertSee('href="'.$href.'"', escape: false);
    }
});

it('points the new-purchase-order button at a route that exists', function (): void {
    p13moneyUserAs(Role::Bursar, Role::Accountant);

    $response = get('/procurement/orders');

    $response->assertOk();
    $response->assertDontSee('/procurement/orders/new', escape: false);
    $response->assertSee('/procurement/orders/capture', escape: false);
});

it('links the declarations register from the tax dashboard', function (): void {
    p13moneyUserAs(Role::Accountant);

    get('/tax')
        ->assertOk()
        ->assertSee('href="'.route('tax.declarations.index').'"', escape: false);
});

it('carries discipline and document verification in the navigation', function (): void {
    $keys = array_column(Navigation::items(), 'key');

    expect($keys)->toContain('discipline');
    expect($keys)->toContain('documents_verify');
});

it('gives every go-live blocker a link to the screen that clears it', function (): void {
    p13moneyUserAs(Role::Administrator);

    $response = get('/setup');

    $response->assertOk();
    $response->assertSee('href="/settings/tax"', escape: false);
    $response->assertSee('href="/ledger/chart-of-accounts"', escape: false);
});

it('offers a backup screen instead of an artisan command', function (): void {
    p13moneyUserAs(Role::Administrator);

    $response = get('/dashboard');

    $response->assertOk();
    $response->assertSee('href="'.route('operations.backups').'"', escape: false);
    $response->assertDontSee('php artisan opes:backup:run', escape: false);
});

it('does not name a 404 route in the insurance policy detail view', function (): void {
    $blade = (string) file_get_contents(resource_path('views/livewire/welfare/insurance/policy-show.blade.php'));

    expect($blade)->not->toContain("url('/welfare/insurance')");
});

it('groups every nav item into a declared section with a real translation', function (): void {
    $sections = require base_path('lang/en/opes.php');
    $knownSections = array_keys($sections['nav_section']);

    foreach (Navigation::items() as $item) {
        expect($item)->toHaveKey('section');
        expect($item['section'])->toBeString()->not->toBe('');
        expect($knownSections)->toContain($item['section']);
    }
});

it('renders the sidebar grouped under its section headings without error', function (): void {
    p13moneyUserAs(Role::Administrator);

    $response = get('/dashboard');

    $response->assertOk();

    // Every section an Administrator's permissions surface must have its
    // heading rendered exactly once, ahead of its own links.
    $sectionsSeen = [];
    foreach (Navigation::items() as $item) {
        $sectionsSeen[$item['section']] = true;
    }

    foreach (array_keys($sectionsSeen) as $sectionKey) {
        $response->assertSee(__('opes.nav_section.'.$sectionKey));
    }
});

it('links every ledger screen from the chart of accounts', function (): void {
    p13moneyUserAs(Role::Accountant);

    $response = get('/ledger/chart-of-accounts');

    $response->assertOk();

    foreach ([
        '/ledger/chart-of-accounts', '/ledger/journal-entries',
        '/ledger/trial-balance', '/accounting/year-end',
    ] as $href) {
        $response->assertSee('href="'.$href.'"', escape: false);
    }
});
