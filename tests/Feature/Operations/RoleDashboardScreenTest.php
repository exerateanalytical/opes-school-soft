<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\get;

require_once __DIR__.'/../Reporting/P13CoreHelpers.php';

uses(RefreshDatabase::class);

it('gives an accountant a dashboard with cards on it', function (): void {
    // The defect: an Accountant used to land on a page with ZERO KPI cards,
    // because every tile was gated on an identity or operations permission
    // they do not hold.
    p13coreUserAs(Role::Accountant);

    get('/dashboard')
        ->assertOk()
        ->assertSee(__('opes.dashboard.panel_cash_position'))
        ->assertSee(__('opes.dashboard.panel_unposted_entries'));
});

it('gives a teacher a teaching dashboard and no ledger error', function (): void {
    // The other half: a Teacher used to get one card reading "—" plus a raw
    // LedgerIntegrityCheck authorization exception rendered on the page.
    p13coreUserAs(Role::Teacher);

    get('/dashboard')
        ->assertOk()
        ->assertSee(__('opes.dashboard.panel_my_classes'))
        ->assertDontSee('LedgerIntegrityCheck')
        ->assertDontSee('This action is unauthorized');
});

it('gives a nurse a welfare dashboard', function (): void {
    p13coreUserAs(Role::Nurse);

    get('/dashboard')->assertOk()->assertSee(__('opes.dashboard.panel_todays_consultations'));
});

it('gives a librarian a library dashboard', function (): void {
    p13coreUserAs(Role::Librarian);

    get('/dashboard')->assertOk()->assertSee(__('opes.dashboard.panel_books_on_loan'));
});

it('gives a front desk clerk the one card its single permission earns', function (): void {
    // front_desk holds visitor.manage and nothing else. One honest card beats
    // five that are filtered away to a blank grid.
    p13coreUserAs(Role::FrontDesk);

    get('/dashboard')->assertOk()->assertSee(__('opes.dashboard.panel_visitors_today'));
});

it('never renders a card the role cannot open', function (): void {
    p13coreUserAs(Role::Librarian);

    get('/dashboard')
        ->assertOk()
        ->assertDontSee(__('opes.dashboard.panel_unposted_entries'))
        ->assertDontSee(__('opes.dashboard.panel_active_users'));
});

it('offers every role at least one quick action it can actually reach', function (): void {
    p13coreUserAs(Role::StoreKeeper);

    get('/dashboard')
        ->assertOk()
        ->assertSee(__('opes.dashboard.action_stock_levels'))
        ->assertSee(__('opes.dashboard.action_asset_register'));
});

it('names the signed-in role on the screen', function (): void {
    p13coreUserAs(Role::Teacher);

    get('/dashboard')->assertOk()->assertSee(Role::Teacher->label());
});

it('still refuses a portal principal', function (): void {
    p13coreUserAs(Role::Guardian);

    get('/dashboard')->assertForbidden();
});
