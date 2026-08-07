<?php

declare(strict_types=1);

namespace App\Modules\Identity\Livewire\Auth;

use App\Modules\Identity\Actions\AuthenticateUser;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.guest')]
final class Login extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function authenticate(AuthenticateUser $authenticate): mixed
    {
        $this->validate();

        $key = $this->throttleKey();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => __('opes.auth.throttled', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        if (! $authenticate->handle($this->email, $this->password, $this->remember)) {
            RateLimiter::hit($key, 300);
            $this->reset('password');

            // ONE message for every failure mode. Distinguishing them would
            // tell an attacker which emails are real accounts.
            throw ValidationException::withMessages(['email' => __('opes.auth.failed')]);
        }

        RateLimiter::clear($key);

        return $this->redirect('/dashboard', navigate: true);
    }

    /**
     * Is the one-click demo sign-in available right now?
     *
     * Both guards must agree (config/opes.php explains why). This is the ONLY
     * place the question is answered - the view and the action below both ask
     * it, so the button can never appear on a page where the action would
     * refuse, and the action can never run because the button was replayed.
     */
    public function demoLoginAvailable(): bool
    {
        return config('opes.demo_login.enabled') === true
            && app()->environment('local');
    }

    /**
     * Sign in as the demo administrator with no credential.
     *
     * Deliberately NOT routed through AuthenticateUser: that action's contract
     * is "verify a password", and giving it a bypass path would put a hole in
     * the one place the rest of the system trusts to check credentials.
     */
    public function demoLogin(\App\Modules\Identity\Actions\WriteAuditEntry $audit): mixed
    {
        abort_unless($this->demoLoginAvailable(), 403);

        /** @var string $email */
        $email = config('opes.demo_login.email');
        /** @var string $name */
        $name = config('opes.demo_login.name');

        $user = DB::transaction(function () use ($email, $name): User {
            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                $user = User::query()->create([
                    'name' => $name,
                    'email' => $email,
                    // Long random secret nobody is told: the account is only
                    // ever reachable through this button, which exists only on
                    // a local demo box. It is not a shared known password.
                    'password' => bin2hex(random_bytes(32)),
                    'status' => 'active',
                ]);
            }

            if (! $user->hasRole(Role::Administrator->value)) {
                $user->assignRole(Role::Administrator->value);
            }

            return $user;
        });

        Auth::login($user);
        session()->regenerate();

        // Audited like any other sign-in, and marked as the demo route so the
        // log distinguishes it from someone who actually knew a password.
        $audit->handle(
            action: AuditAction::Login,
            module: 'Identity',
            auditableType: User::class,
            auditableId: (int) $user->getKey(),
            after: ['method' => 'demo_login'],
            actor: $user->toAuditActor(),
        );

        return $this->redirect('/dashboard', navigate: true);
    }

    private function throttleKey(): string
    {
        return 'login:'.strtolower(trim($this->email)).'|'.request()->ip();
    }

    public function render(): mixed
    {
        return view('livewire.auth.login');
    }
}
