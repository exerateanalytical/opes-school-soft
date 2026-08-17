<?php

declare(strict_types=1);

use App\Modules\Accounting\Livewire\ChartOfAccounts\Index;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/*
 * Shared with JournalEntryScreenTest and TrialBalanceScreenTest. Guarded
 * because Pest loads every test file into one process and a second
 * declaration is a fatal error, not a failure - same idiom as
 * studentsUiUserAs()/academicsUiUserAs().
 */
if (! function_exists('ledgerUiUserAs')) {
    function ledgerUiUserAs(Role $role): User
    {
        (new \Database\Seeders\RolePermissionSeeder())->run();
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user->fresh() ?? $user;
    }
}

it('renders through the real route inside the shell', function () {
    actingAs(ledgerUiUserAs(Role::Accountant));

    get('/ledger/chart-of-accounts')->assertOk()->assertSee('OPES');
});

it('403s on the route for a role without ledger.view', function () {
    // Bursar reads fees, not the ledger (07-students 7.5 / Role::Bursar).
    actingAs(ledgerUiUserAs(Role::Bursar));

    get('/ledger/chart-of-accounts')->assertForbidden();
});

it('forbids reaching the component directly without permission', function () {
    actingAs(ledgerUiUserAs(Role::Bursar));

    Livewire::test(Index::class)->assertForbidden();
});

it('is reachable with ledger.view alone, no ledger.post required', function () {
    // Principal holds ledger.view but not ledger.post.
    actingAs(ledgerUiUserAs(Role::Principal));

    get('/ledger/chart-of-accounts')->assertOk();
});

it('lists a created account with its code and name', function () {
    actingAs(ledgerUiUserAs(Role::Accountant));

    // The seeded SYSCOHADA skeleton (230002) is dozens of rows deep before
    // this factory account - sorted ascending by code, a factory leaf under
    // class 9 lands well past page 1 of 25. Search for it rather than assume
    // it is visible unfiltered, the same way a real operator would find it.
    $account = ChartOfAccount::factory()->create();

    Livewire::test(Index::class)
        ->set('search', $account->code)
        ->assertSee($account->code)
        ->assertSee($account->name);
});

it('filters by postable only', function () {
    actingAs(ledgerUiUserAs(Role::Accountant));

    $postable = ChartOfAccount::factory()->create();
    $header = ChartOfAccount::query()->where('code', '9')->firstOrFail();

    // Same pagination note as the test above: narrow to class 9 (where the
    // factory builds its scaffold) so both $postable and $header are within
    // reach of the paginator's current page, rather than relying on
    // unfiltered page-1 luck against the full seeded skeleton.
    Livewire::test(Index::class)
        ->set('accountClass', '9')
        ->set('postableOnly', true)
        ->assertViewHas('accounts', function ($accounts) use ($postable, $header): bool {
            $ids = $accounts->getCollection()->pluck('id');

            return $ids->contains($postable->id) && ! $ids->contains($header->id);
        });
});

it('filters by account class', function () {
    actingAs(ledgerUiUserAs(Role::Accountant));

    $account = ChartOfAccount::factory()->create();

    Livewire::test(Index::class)
        ->set('accountClass', '9')
        ->assertSee($account->code)
        ->set('accountClass', '6')
        ->assertDontSee($account->code);
});

it('searches by code', function () {
    actingAs(ledgerUiUserAs(Role::Accountant));

    $account = ChartOfAccount::factory()->create();

    Livewire::test(Index::class)
        ->set('search', $account->code)
        ->assertSee($account->code)
        ->set('search', 'ZZZZZNOMATCH')
        ->assertDontSee($account->code);
});

it('persists a DSF line code entered on the edit form', function () {
    // SuperAdmin, not Accountant: CreateAccount::PERMISSION/UpdateAccount::
    // PERMISSION is the literal string 'accounting.manage', which no role's
    // grant list in Role.php actually carries (confirmed by grep - zero
    // hits). The entire account editor is unreachable by any seeded role
    // today; SuperAdmin's unconditional Gate::before bypass is the only way
    // to exercise it until that's fixed, which is a separate, pre-existing
    // bug outside this feature's scope. Flagged, not fixed, here.
    actingAs(ledgerUiUserAs(Role::SuperAdmin));

    $account = ChartOfAccount::factory()->create();

    Livewire::test(Index::class)
        ->call('startEdit', $account->id)
        ->set('editDsfLineCode', 'BL-401')
        ->call('saveEditAccount');

    expect($account->fresh()->dsf_line_code)->toBe('BL-401');
});

it('persists a blank DSF line code as null, not an empty string', function () {
    // SuperAdmin - see the comment on the previous test.
    actingAs(ledgerUiUserAs(Role::SuperAdmin));

    $account = ChartOfAccount::factory()->create();

    Livewire::test(Index::class)
        ->call('startEdit', $account->id)
        ->set('editDsfLineCode', '')
        ->call('saveEditAccount');

    expect($account->fresh()->dsf_line_code)->toBeNull();
});

it('counts real KPI totals, never fabricated', function () {
    actingAs(ledgerUiUserAs(Role::Accountant));

    ChartOfAccount::factory()->count(3)->create();

    $component = Livewire::test(Index::class);

    $totalAccounts = ChartOfAccount::query()->count();
    $postableAccounts = ChartOfAccount::query()->where('is_postable', true)->count();

    $component
        ->assertViewHas('totalAccounts', $totalAccounts)
        ->assertViewHas('postableAccounts', $postableAccounts);
});
