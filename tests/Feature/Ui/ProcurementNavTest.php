<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/*
 * Phase 5 wiring (docs/plans/phase-05.md §5): the procurement and tax nav
 * items, their routes, and the nav-and-route-agree-by-construction
 * contract. Hiding a link is presentation; every route refuses on its own.
 */
if (! function_exists('f5NavUser')) {
    function f5NavUser(Role $role): User
    {
        (new \Database\Seeders\RolePermissionSeeder())->run();
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user->fresh() ?? $user;
    }
}

it('registers procurement and tax as BUILT nav items, not placeholders', function () {
    $keys = array_column(Navigation::items(), 'key');

    expect($keys)->toContain('procurement')
        ->and($keys)->toContain('tax')
        ->and(Navigation::placeholderKeys())->not->toContain('procurement')
        ->and(Navigation::placeholderKeys())->not->toContain('tax');
});

it('shows the procurement and tax links to the roles that hold the permissions', function () {
    $html = (string) actingAs(f5NavUser(Role::Bursar))->get('/dashboard')->getContent();

    // The bursar records the payables chain and reads the tax dashboard.
    expect($html)->toContain('href="/procurement/suppliers"')
        ->and($html)->toContain('href="/tax"');
});

it('hides the procurement and tax links from a role without the permissions', function () {
    $html = (string) actingAs(f5NavUser(Role::Teacher))->get('/dashboard')->getContent();

    expect($html)->not->toContain('href="/procurement/suppliers"')
        ->and($html)->not->toContain('href="/tax"');
});

it('blocks the routes as well as hiding the links', function () {
    // 00-core 6.2: authorisation lives in the route and the Action.
    actingAs(f5NavUser(Role::Teacher));

    get('/procurement/suppliers')->assertForbidden();
    get('/procurement/invoices')->assertForbidden();
    get('/procurement/payments')->assertForbidden();
    get('/tax')->assertForbidden();
    get('/tax/declarations')->assertForbidden();
    get('/settings/tax')->assertForbidden();
});

it('gates the declarations register harder than the dashboard', function () {
    // tax.view opens /tax; the register needs tax.declare - the Bursar
    // reads the position but does not run the declaration cycle.
    actingAs(f5NavUser(Role::Bursar));

    get('/tax')->assertOk();
    get('/tax/declarations')->assertForbidden();
});

it('lets the accountant run the declaration cycle', function () {
    actingAs(f5NavUser(Role::Accountant));

    get('/tax')->assertOk();
    get('/tax/declarations')->assertOk();
    get('/settings/tax')->assertOk();
    get('/settings/fiscal-identity')->assertOk();
});

it('redirects /procurement to the supplier register', function () {
    actingAs(f5NavUser(Role::Bursar));

    get('/procurement')->assertRedirect('/procurement/suppliers');
});

it('serves every procurement screen to an administrator', function () {
    actingAs(f5NavUser(Role::Administrator));

    foreach ([
        '/procurement/suppliers',
        '/procurement/requisitions',
        '/procurement/orders',
        '/procurement/receipts',
        '/procurement/invoices',
        '/procurement/payments',
        '/procurement/payables',
        '/tax',
        '/tax/declarations',
    ] as $path) {
        get($path)->assertOk();
    }
});

it('redirects a guest away from the new routes', function () {
    get('/tax')->assertRedirect('/login');
    get('/procurement/suppliers')->assertRedirect('/login');
});

it('splits the SoD pairs across the money roles', function () {
    // §4: author and approver are two people BY ROLE BASELINE - the
    // bursar records, the accountant approves invoices and voids
    // payments, the principal approves orders and payments.
    $bursar = f5NavUser(Role::Bursar);
    $accountant = f5NavUser(Role::Accountant);
    $principal = f5NavUser(Role::Principal);

    expect($bursar->can('procurement.invoice_create'))->toBeTrue()
        ->and($bursar->can('procurement.invoice_approve'))->toBeFalse()
        ->and($bursar->can('procurement.payment_record'))->toBeTrue()
        ->and($bursar->can('procurement.payment_approve'))->toBeFalse();

    expect($accountant->can('procurement.invoice_approve'))->toBeTrue()
        ->and($accountant->can('procurement.invoice_create'))->toBeFalse()
        ->and($accountant->can('procurement.payment_void'))->toBeTrue()
        ->and($accountant->can('procurement.payment_record'))->toBeFalse();

    expect($principal->can('procurement.order_approve'))->toBeTrue()
        ->and($principal->can('procurement.order_manage'))->toBeFalse()
        ->and($principal->can('procurement.payment_approve'))->toBeTrue();
});
