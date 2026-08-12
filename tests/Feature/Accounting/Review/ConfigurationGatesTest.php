<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\Review\ConfigurationGates;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/**
 * The §22 gate register,
 * docs/specs/2026-08-12-accounting-finance-architecture.md §4.4.
 */
function gatesUser(Role $role = Role::Accountant): User
{
    (new Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user->fresh() ?? $user;
}

it('lists every gate the accounting spec declares', function () {
    actingAs(gatesUser());

    expect(app(ConfigurationGates::class)->handle())->toHaveCount(19);
});

it('names the blocked feature and the item for every gate', function () {
    actingAs(gatesUser());

    foreach (app(ConfigurationGates::class)->handle() as $gate) {
        expect($gate['item'])->not->toBeEmpty();
        expect($gate['blocks'])->not->toBeEmpty();
        expect($gate['number'])->toBeGreaterThan(0);
    }
});

it('never reports a policy gate as configured', function () {
    actingAs(gatesUser());

    $policy = array_filter(
        app(ConfigurationGates::class)->handle(),
        fn (array $g): bool => $g['kind'] === ConfigurationGates::KIND_POLICY,
    );

    // Whether AUDCIF fixes a closure deadline is not a question the database
    // can answer. Inspecting accounts must never close one of these.
    expect($policy)->not->toBeEmpty();

    foreach ($policy as $gate) {
        expect($gate['configured'])->toBeFalse();
    }
});

it('reports an account gate as unconfigured while its accounts are absent', function () {
    actingAs(gatesUser());

    // Gate 5: the 491 doubtful-debt provision account is genuinely absent.
    $gate = collect(app(ConfigurationGates::class)->handle())->firstWhere('number', 5);

    expect($gate['kind'])->toBe(ConfigurationGates::KIND_ACCOUNT);
    expect($gate['configured'])->toBeFalse();
    expect($gate['missing'])->toContain('491');
});

it('reports an account gate as configured once its subdivisions are seeded', function () {
    actingAs(gatesUser());

    // Gate 1 asks for 707x subdivisions. The seeder ships 7073/7077/7078, so
    // this gate is ALREADY CLOSED even though 02-accounting.md §22 still lists
    // it as open - the register reads the chart rather than trusting the spec.
    $gate = collect(app(ConfigurationGates::class)->handle())->firstWhere('number', 1);

    expect($gate['configured'])->toBeTrue();
    expect($gate['missing'])->toBe([]);
});

it('does not count a parent account as its own subdivision', function () {
    actingAs(gatesUser());

    // Gate 2 wants 5-digit extensions UNDER 706. A postable `706` alone must
    // not satisfy it, or the gate would close itself the moment the base
    // account was seeded - which is the whole failure mode being guarded.
    $gate = collect(app(ConfigurationGates::class)->handle())->firstWhere('number', 2);

    expect($gate['configured'])->toBeFalse();
    expect($gate['missing'])->toContain('706');
});

it('stays in step with the spec table', function () {
    $spec = file_get_contents(base_path('docs/specs/02-accounting.md'));
    $table = substr($spec, (int) strpos($spec, '## 22. Open items requiring verification'));

    preg_match_all('/^\|\s*(\d+)\s*\|/m', $table, $matches);

    // If the spec grows a twentieth gate, this fails and the register must be
    // taught about it - rather than the gate silently going untracked.
    expect($matches[1])->toHaveCount(19);
});

it('refuses without ledger.view', function () {
    actingAs(gatesUser(Role::Teacher));

    app(ConfigurationGates::class)->handle();
})->throws(Illuminate\Auth\Access\AuthorizationException::class);
