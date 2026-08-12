<?php

declare(strict_types=1);

namespace App\Modules\Identity\Livewire\Auth;

use App\Modules\Identity\Actions\AuthenticateUser;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

// The full-bleed shell, not `layouts.guest`: that one boxes its slot into a
// centred max-w-md card, which a two-column sign-in cannot live inside. The
// OTP and password-reset screens still render through `guest`.
#[Layout('layouts.auth-wide')]
final class Login extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    /**
     * Which demo identity the demonstration dropdown has selected.
     *
     * Never trusted as a credential. `demoLogin()` still matches whatever
     * arrives here against the CONFIGURED identities and aborts on anything
     * else, so binding this to a `<select>` gives a replayed Livewire call no
     * more reach than the button grid it replaces.
     */
    public string $demoRole = '';

    public function mount(): void
    {
        // Default to the first configured identity so the dropdown always
        // shows a real selection rather than an empty row that silently does
        // nothing when submitted.
        $identities = $this->demoIdentities();

        $this->demoRole = $identities === [] ? '' : (string) $identities[0]['key'];
    }

    /**
     * Sign in as whichever identity the dropdown is showing.
     *
     * A thin wrapper rather than a second implementation: `demoLogin()` keeps
     * the availability guard, the configured-identity match and the audit
     * entry, and this only supplies the argument the buttons used to pass
     * literally.
     */
    public function demoLoginSelected(\App\Modules\Identity\Actions\WriteAuditEntry $audit): mixed
    {
        if ($this->demoRole === '') {
            return null;
        }

        return $this->demoLogin($audit, $this->demoRole);
    }

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

        $signedIn = Auth::user();

        return $this->redirect(
            $this->landingFor($signedIn instanceof User ? $signedIn : null),
            navigate: true,
        );
    }

    /**
     * Where a freshly signed-in principal should land.
     *
     * The money offices spend their day in the finance dashboard, so send
     * them there rather than to a general landing screen whose tiles they are
     * mostly not permitted to see. Guarded three ways, because the finance
     * dashboard is being built alongside this and its route may not exist
     * yet: the route must be registered, and the user must actually hold the
     * permission behind it - never redirect anyone into a guaranteed 403.
     */
    private function landingFor(?User $user): string
    {
        if ($user === null) {
            return '/dashboard';
        }

        // A guardian has no /dashboard permission at all - EnsureGuardianPortal
        // is the only door open to that role - so sending one there the way
        // every other role defaults would land on a guaranteed 403 rather
        // than degrading to the generic screen. /portal is its own middleware
        // stack (auth then guardian.portal), unconditionally correct for any
        // user holding the role.
        if ($user->hasRole(Role::Guardian->value)) {
            return '/portal';
        }

        if (! Route::has('finance.dashboard')) {
            return '/dashboard';
        }

        $financeRoles = [Role::Accountant->value, Role::Bursar->value];

        if (! $user->hasAnyRole($financeRoles)) {
            return '/dashboard';
        }

        if (! $user->can(Permission::FeeView->value) && ! $user->can(Permission::LedgerView->value)) {
            return '/dashboard';
        }

        return route('finance.dashboard', absolute: false);
    }

    /**
     * The demo identities on offer, in the order the page shows them.
     *
     * Read from config rather than hardcoded here so a demo box can change
     * the cast without a code change, and so an identity naming a role this
     * build does not have is dropped rather than fataling on the click.
     *
     * @return list<array{key: string, role: Role, email: string, name: string, label: string}>
     */
    public function demoIdentities(): array
    {
        if (! $this->demoLoginAvailable()) {
            return [];
        }

        $configured = config('opes.demo_login.identities');

        if (! is_array($configured)) {
            return [];
        }

        $identities = [];

        foreach ($configured as $identity) {
            if (! is_array($identity)) {
                continue;
            }

            $roleValue = $identity['role'] ?? null;
            $email = $identity['email'] ?? null;
            $name = $identity['name'] ?? null;

            if (! is_string($roleValue) || ! is_string($email) || ! is_string($name)) {
                continue;
            }

            $role = Role::tryFrom($roleValue);

            if ($role === null) {
                continue;
            }

            $identities[] = [
                'key' => $role->value,
                'role' => $role,
                'email' => $email,
                'name' => $name,
                'label' => $role->label(),
            ];
        }

        return $identities;
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
     * Sign in as one of the demo identities with no credential.
     *
     * Deliberately NOT routed through AuthenticateUser: that action's contract
     * is "verify a password", and giving it a bypass path would put a hole in
     * the one place the rest of the system trusts to check credentials.
     *
     * The chosen role is matched against the CONFIGURED identities, never
     * taken from the request directly - otherwise a replayed Livewire call
     * could name `super_admin` and mint itself an account the demo page never
     * offered. The role is then granted through Spatie exactly as it would be
     * for a real user, so every permission check downstream is the real one.
     */
    public function demoLogin(\App\Modules\Identity\Actions\WriteAuditEntry $audit, string $roleKey = 'administrator'): mixed
    {
        abort_unless($this->demoLoginAvailable(), 403);

        $identity = null;

        foreach ($this->demoIdentities() as $candidate) {
            if ($candidate['key'] === $roleKey) {
                $identity = $candidate;
                break;
            }
        }

        if ($identity === null) {
            // A role the demo page never offered - a replayed or hand-crafted
            // call. Refuse rather than mint an account for it.
            abort(403);
        }

        $email = $identity['email'];
        $name = $identity['name'];
        $role = $identity['role'];

        $user = DB::transaction(function () use ($email, $name, $role): User {
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

            if (! $user->hasRole($role->value)) {
                $user->assignRole($role->value);
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
            after: ['method' => 'demo_login', 'role' => $role->value],
            actor: $user->toAuditActor(),
        );

        return $this->redirect($this->landingFor($user), navigate: true);
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
