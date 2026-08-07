<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Livewire\Auth\Login;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\get;

uses(RefreshDatabase::class);

function enableDemoLogin(): void
{
    (new \Database\Seeders\RolePermissionSeeder())->run();
    config()->set('opes.demo_login.enabled', true);
    config()->set('opes.demo_login.email', 'demo@opeschool.test');
    config()->set('opes.demo_login.name', 'Demo Administrator');
    app()->detectEnvironment(fn (): string => 'local');
}

it('signs in as an administrator with one click when demo login is enabled', function () {
    enableDemoLogin();

    Livewire::test(Login::class)
        ->call('demoLogin')
        ->assertRedirect('/dashboard');

    $demo = User::query()->where('email', 'demo@opeschool.test')->firstOrFail();

    expect($demo->hasRole(Role::Administrator->value))->toBeTrue();
    expect(auth()->id())->toBe($demo->getKey());
});

it('reuses the demo account rather than creating a second one', function () {
    enableDemoLogin();

    Livewire::test(Login::class)->call('demoLogin');
    auth()->logout();
    Livewire::test(Login::class)->call('demoLogin');

    expect(User::query()->where('email', 'demo@opeschool.test')->count())->toBe(1);
});

it('records the demo sign-in in the audit log and marks how it happened', function () {
    // A sign-in that skipped the password must still be traceable, and must be
    // distinguishable from one where somebody actually knew a credential.
    enableDemoLogin();

    Livewire::test(Login::class)->call('demoLogin');

    $entry = AuditLog::query()->where('action', 'login')->latest('id')->firstOrFail();

    expect(json_encode($entry->getAttributes(), JSON_THROW_ON_ERROR))->toContain('demo_login');
});

it('never stores a guessable password on the demo account', function () {
    // The account is reachable only through the button. If the button were
    // ever exposed, a known password would also hand over the account itself.
    enableDemoLogin();

    Livewire::test(Login::class)->call('demoLogin');

    $demo = User::query()->where('email', 'demo@opeschool.test')->firstOrFail();
    $hash = $demo->password;

    // An account with no password at all would defeat the point of the check
    // below, so pin that down before relying on it.
    expect($hash)->toBeString();
    expect(strlen((string) $hash))->toBeGreaterThan(0);

    foreach (['password', 'demo', 'Demo Administrator', 'demo@opeschool.test', 'secret'] as $guess) {
        expect(Hash::check($guess, (string) $hash))->toBeFalse();
    }
});

it('refuses the demo sign-in when the flag is off', function () {
    (new \Database\Seeders\RolePermissionSeeder())->run();
    config()->set('opes.demo_login.enabled', false);
    app()->detectEnvironment(fn (): string => 'local');

    Livewire::test(Login::class)->call('demoLogin')->assertForbidden();

    expect(User::query()->where('email', 'demo@opeschool.test')->exists())->toBeFalse();
});

it('refuses the demo sign-in in production even when the flag is on', function () {
    // The guard that actually matters. A stray OPES_DEMO_LOGIN=true copied
    // into a school's .env must not open an unauthenticated door to student
    // records, guardian contact details and payroll.
    (new \Database\Seeders\RolePermissionSeeder())->run();
    config()->set('opes.demo_login.enabled', true);
    app()->detectEnvironment(fn (): string => 'production');

    Livewire::test(Login::class)->call('demoLogin')->assertForbidden();

    expect(User::query()->where('email', 'demo@opeschool.test')->exists())->toBeFalse();
});

it('does not render the demo button in production', function () {
    // Not merely hidden with CSS - absent from the HTML entirely.
    config()->set('opes.demo_login.enabled', true);
    app()->detectEnvironment(fn (): string => 'production');

    $html = (string) get('/login')->getContent();

    expect($html)->not->toContain('demoLogin');
});

it('does not render the demo button when the flag is off', function () {
    config()->set('opes.demo_login.enabled', false);
    app()->detectEnvironment(fn (): string => 'local');

    $html = (string) get('/login')->getContent();

    expect($html)->not->toContain('demoLogin');
});

it('ships the flag off in the env file a deployer copies', function () {
    // NOT config('opes.demo_login.enabled') - that reads THIS machine's .env,
    // which is deliberately on for the local demo box. What has to be safe is
    // the file a school's installer copies to make its own .env.
    $example = file_get_contents(base_path('.env.example'));

    expect($example)->toBeString();
    expect((string) $example)->toContain('OPES_DEMO_LOGIN=false');
    expect((string) $example)->not->toContain('OPES_DEMO_LOGIN=true');
});
