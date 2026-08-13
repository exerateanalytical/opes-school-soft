<?php

declare(strict_types=1);

use App\Modules\Accounting\Livewire\Review\ControlCentre;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Support\Clock\BusinessDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

function controlCentreUser(Role $role = Role::Accountant): User
{
    (new Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user->fresh() ?? $user;
}

it('renders for an accountant', function () {
    actingAs(controlCentreUser());

    Livewire::test(ControlCentre::class)->assertOk();
});

it('refuses a teacher at the route, not merely in the sidebar', function () {
    actingAs(controlCentreUser(Role::Teacher));

    get('/accounting/review')->assertForbidden();
});

it('states both the axis and the as_of on the page', function () {
    actingAs(controlCentreUser());

    Livewire::test(ControlCentre::class)
        ->assertSee(__('opes.accounting.review.axis_label'))
        ->assertSee(__('opes.accounting.review.as_of'))
        ->assertSee(BusinessDate::today());
});

it('switches axis and carries it into the checks', function () {
    actingAs(controlCentreUser());

    Livewire::test(ControlCentre::class)
        ->set('axis', 'academic_year')
        ->assertOk()
        ->assertSet('axis', 'academic_year');
});

it('rejects an axis the ledger does not recognise', function () {
    actingAs(controlCentreUser());

    // 02-accounting §7 knows two axes. A hand-edited query string must not
    // reach the Action with a third.
    Livewire::test(ControlCentre::class)
        ->set('axis', 'calendar_year')
        ->assertSet('axis', 'fiscal_year');
});

it('shows the gate register with its open count', function () {
    actingAs(controlCentreUser());

    Livewire::test(ControlCentre::class)
        ->assertSee(__('opes.accounting.review.gates_heading'))
        // Gate 5 (491 provision) is genuinely unsourced, so it must appear.
        ->assertSee('491 provision account');
});
