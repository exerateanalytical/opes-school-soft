<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Operations\Actions\EvaluateSetupReadiness;
use App\Modules\Operations\Domain\SetupCheckStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * 00-core §16 - the blocking gates.
 *
 * The property under test is that these checks read LIVE STATE. A readiness
 * console backed by stored flags would let a school tick a row green without
 * configuring anything, which is precisely the failure §16 exists to prevent.
 */

function setupActor(): User
{
    (new \Database\Seeders\RolePermissionSeeder())->run();

    $user = User::factory()->create();
    $user->assignRole(Role::SuperAdmin->value);
    Auth::setUser($user);

    return $user;
}

it('blocks go-live when no fiscal year covers today', function (): void {
    setupActor();

    $checks = collect(app(EvaluateSetupReadiness::class)->handle())->keyBy('key');

    expect($checks['fiscal_year_today']['status'])->toBe(SetupCheckStatus::Blocked);
});

it('blocks go-live when only one person holds accounting rights', function (): void {
    setupActor();

    $checks = collect(app(EvaluateSetupReadiness::class)->handle())->keyBy('key');

    // The actor is a single super_admin, so maker-checker is impossible.
    expect($checks['two_accounting_users']['status'])->toBe(SetupCheckStatus::Blocked);
});

it('passes the two-person check once a second accounting user exists', function (): void {
    setupActor();

    $second = User::factory()->create();
    $second->assignRole(Role::Accountant->value);

    $checks = collect(app(EvaluateSetupReadiness::class)->handle())->keyBy('key');

    expect($checks['two_accounting_users']['status'])->toBe(SetupCheckStatus::Pass);
});

it('reads live state rather than a stored flag', function (): void {
    setupActor();

    $before = collect(app(EvaluateSetupReadiness::class)->handle())->keyBy('key');
    expect($before['fiscal_year_today']['status'])->toBe(SetupCheckStatus::Blocked);

    DB::table('fiscal_years')->insert([
        'code' => 'RDY-'.now()->format('Y'),
        'starts_on' => now()->startOfYear()->toDateString(),
        'ends_on' => now()->endOfYear()->toDateString(),
        'status' => 'open',
        'is_first_exercice' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $after = collect(app(EvaluateSetupReadiness::class)->handle())->keyBy('key');

    expect($after['fiscal_year_today']['status'])->toBe(SetupCheckStatus::Pass)
        ->and($after['fiscal_year_calendar']['status'])->toBe(SetupCheckStatus::Pass);
});

it('blocks a non-calendar fiscal year and names it', function (): void {
    setupActor();

    DB::table('fiscal_years')->insert([
        'code' => 'BAD-SEPT',
        'starts_on' => '2026-09-01',
        'ends_on' => '2027-08-31',
        'status' => 'open',
        'is_first_exercice' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $checks = collect(app(EvaluateSetupReadiness::class)->handle())->keyBy('key');

    expect($checks['fiscal_year_calendar']['status'])->toBe(SetupCheckStatus::Blocked)
        ->and($checks['fiscal_year_calendar']['detail'])->toContain('BAD-SEPT');
});

it('every check names who has to answer it', function (): void {
    setupActor();

    foreach (app(EvaluateSetupReadiness::class)->handle() as $check) {
        expect($check['owner'])->not->toBe('')
            ->and($check['remedy'])->not->toBe('')
            ->and($check['title'])->not->toBe('');
    }
});
