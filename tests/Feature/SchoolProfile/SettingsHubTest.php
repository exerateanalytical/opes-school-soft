<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\get;

require_once __DIR__.'/../Reporting/P13MoneyHelpers.php';

uses(RefreshDatabase::class);

it('links every settings screen an administrator is allowed to open', function (): void {
    p13moneyUserAs(Role::Administrator);

    $response = get('/settings');

    $response->assertOk();

    foreach ([
        '/settings/school-identity',
        '/settings/branding',
        '/settings/tax',
        '/settings/fiscal-identity',
        '/academics/settings',
    ] as $href) {
        $response->assertSee('href="'.$href.'"', escape: false);
    }
});

it('hides a settings card the role may not open', function (): void {
    // Administrator deliberately lacks licence.manage - /settings/licence
    // returns 403 by design, so a card pointing at it would be a link the
    // nav-and-route-agree contract forbids.
    p13moneyUserAs(Role::Administrator);

    get('/settings')->assertDontSee('href="/settings/licence"', escape: false);
});
