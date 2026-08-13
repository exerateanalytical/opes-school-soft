<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\Review\ControlAccountChecks;
use App\Modules\Accounting\Actions\Review\SuspenseBalances;
use App\Modules\Accounting\Domain\ControlStatus;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Support\Clock\BusinessDate;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function controlMatrixUser(Role $role = Role::Accountant): User
{
    (new Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user->fresh() ?? $user;
}

it('returns a row for every control the spec names', function () {
    actingAs(controlMatrixUser());

    $keys = app(ControlAccountChecks::class)->handle()->pluck('key');

    foreach (ControlAccountChecks::PENDING as $expected) {
        expect($keys)->toContain($expected);
    }
});

it('never reports an unwired control as reconciled', function () {
    actingAs(controlMatrixUser());

    $checks = app(ControlAccountChecks::class)->handle();
    $pending = $checks->filter(fn ($c): bool => in_array($c->key, ControlAccountChecks::PENDING, true));

    expect($pending)->toHaveCount(count(ControlAccountChecks::PENDING));

    foreach ($pending as $check) {
        // A zero difference is a positive claim - it says the books agree.
        // An uncomputed control must never make it.
        expect($check->status)->toBe(ControlStatus::NotConfigured);
        expect($check->expected)->toBeNull();
        expect($check->actual)->toBeNull();
        expect($check->blockingGate)->not->toBeEmpty();
    }
});

it('carries the axis and as_of onto every row', function () {
    actingAs(controlMatrixUser());

    $checks = app(ControlAccountChecks::class)->handle(axis: 'academic_year');

    expect($checks)->not->toBeEmpty();

    foreach ($checks as $check) {
        expect($check->axis)->toBe('academic_year');
        expect($check->asOf)->toBe(BusinessDate::today());
    }
});

it('reports no suspense balance on a clean ledger', function () {
    actingAs(controlMatrixUser());

    // Zero suspense is the healthy case; the Action filters those out, so an
    // empty collection here means "nothing unexplained", not "nothing checked".
    expect(app(SuspenseBalances::class)->handle())->toBeEmpty();
});

it('refuses the matrix without ledger.view', function () {
    actingAs(controlMatrixUser(Role::Teacher));

    app(ControlAccountChecks::class)->handle();
})->throws(Illuminate\Auth\Access\AuthorizationException::class);

it('refuses suspense balances without ledger.view', function () {
    actingAs(controlMatrixUser(Role::Teacher));

    app(SuspenseBalances::class)->handle();
})->throws(Illuminate\Auth\Access\AuthorizationException::class);
