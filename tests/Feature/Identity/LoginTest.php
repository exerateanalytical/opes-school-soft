<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Livewire\Auth\Login;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

// Pest's functional Laravel API rather than $this->get(): inside a Pest closure
// PHPStan resolves $this to Pest\PendingCalls\TestCall, which has no HTTP
// methods. Livewire::test() is a static call, so it is unaffected.
use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

function loginUser(string $password = 'Correct-Horse-1'): User
{
    (new \Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create(['email' => 'bursar@school.test', 'password' => $password]);
    $user->assignRole(Role::Bursar->value);

    return $user->fresh() ?? $user;
}

beforeEach(function () {
    RateLimiter::clear('login:bursar@school.test|127.0.0.1');
});

it('logs a user in with correct credentials', function () {
    loginUser();

    Livewire::test(Login::class)
        ->set('email', 'bursar@school.test')
        ->set('password', 'Correct-Horse-1')
        ->call('authenticate')
        ->assertRedirect('/dashboard');

    expect(auth()->check())->toBeTrue();
});

it('rejects a wrong password', function () {
    loginUser();

    Livewire::test(Login::class)
        ->set('email', 'bursar@school.test')
        ->set('password', 'wrong')
        ->call('authenticate')
        ->assertHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

it('gives the same message whether the email exists or not', function () {
    // Anything else turns the login form into an account-enumeration oracle.
    loginUser();

    $known = Livewire::test(Login::class)
        ->set('email', 'bursar@school.test')->set('password', 'wrong')->call('authenticate');

    $unknown = Livewire::test(Login::class)
        ->set('email', 'nobody@school.test')->set('password', 'wrong')->call('authenticate');

    expect($known->errors()->first('email'))->toBe($unknown->errors()->first('email'));
});

it('refuses a suspended user', function () {
    // 00-core 10.5: users are never deleted, only suspended. A suspended
    // account must not authenticate while its audit history stays intact.
    $user = loginUser();
    $user->update(['status' => 'suspended']);

    Livewire::test(Login::class)
        ->set('email', 'bursar@school.test')
        ->set('password', 'Correct-Horse-1')
        ->call('authenticate')
        ->assertHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

it('audits a successful login', function () {
    loginUser();

    Livewire::test(Login::class)
        ->set('email', 'bursar@school.test')
        ->set('password', 'Correct-Horse-1')
        ->call('authenticate');

    expect(AuditLog::query()->where('action', 'login')->count())->toBe(1);
});

it('audits a failed login', function () {
    loginUser();

    Livewire::test(Login::class)
        ->set('email', 'bursar@school.test')
        ->set('password', 'hunter2')
        ->call('authenticate');

    expect(AuditLog::query()->where('action', 'login_failed')->count())->toBe(1);
});

it('never writes the attempted password into the audit log', function () {
    loginUser();

    Livewire::test(Login::class)
        ->set('email', 'bursar@school.test')
        ->set('password', 'hunter2')
        ->call('authenticate');

    foreach (AuditLog::query()->get() as $entry) {
        expect(json_encode($entry->getAttributes(), JSON_THROW_ON_ERROR))->not->toContain('hunter2');
    }
});

it('throttles repeated failures', function () {
    loginUser();

    for ($i = 0; $i < 5; $i++) {
        Livewire::test(Login::class)
            ->set('email', 'bursar@school.test')->set('password', 'wrong')->call('authenticate');
    }

    // The sixth attempt is throttled even with the CORRECT password.
    Livewire::test(Login::class)
        ->set('email', 'bursar@school.test')
        ->set('password', 'Correct-Horse-1')
        ->call('authenticate')
        ->assertHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

it('clears the throttle after a successful login', function () {
    loginUser();

    Livewire::test(Login::class)
        ->set('email', 'bursar@school.test')->set('password', 'wrong')->call('authenticate');

    Livewire::test(Login::class)
        ->set('email', 'bursar@school.test')->set('password', 'Correct-Horse-1')->call('authenticate');

    expect(RateLimiter::attempts('login:bursar@school.test|127.0.0.1'))->toBe(0);
});

it('renders the login screen inside the guest layout', function () {
    // Added beyond the specified list: every other test here drives the
    // component through Livewire::test(), which never renders the layout, so a
    // broken guest.blade.php would leave the suite green and the product dark.
    $response = get('/login');

    $response->assertOk();
    $response->assertSee('OPES');
    $response->assertSee(__('opes.auth.forgot_help'));
    $response->assertSee('<label for="email"', false);
    $response->assertSee('<label for="password"', false);
    $response->assertSee('<label for="remember"', false);
});

it('redirects an authenticated user away from the login screen', function () {
    $user = loginUser();

    actingAs($user)->get('/login')->assertRedirect('/dashboard');
});
