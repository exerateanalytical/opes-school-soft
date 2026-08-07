# Phase 0D — Vertical Slice Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prove the architecture end to end and give the owner something to look at. A user logs in, lands on an application shell with the school's branding, sees a dashboard, opens User Management, creates a user, and finds them in a permission-filtered, paginated list — in English or French, on a phone or a desktop, with every change recorded in the audit log.

**Architecture:** Livewire 3 components are thin adapters over the Actions built in 0B, exactly as `00-core` §6.1 requires — the same Actions the future REST API will call. The application shell and the **list-screen contract** are built once as reusable Blade components, because `09-ui` §4 identifies that contract as the single highest-leverage piece of UI work: every later module screen composes it rather than reinventing it.

**Tech Stack:** PHP 8.3.30 (Laragon), Laravel 13.24.0, **Livewire 3**, Tailwind CSS 4 (already installed), Vite 8, Pest 4, PHPStan level 8.

**Specs implemented:** `docs/specs/09-ui.md` §2 (shell and sitemap), §3 (dashboard), §4 (list-screen contract), §7 (settings surface, read-only here), §8.10 (User Management), §10 (responsive); `00-core` §6.1 (Actions as the single source of truth), §9 (roles and permissions), §18 (bilingual).

**Depends on:** Phase 0C (`tag: phase-0c`). Uses `Identity`'s `User`, `Role`, `Permission`, `WriteAuditEntry`, `SetUserPassword`, and `Operations`' `CollectHealth`.

---

## Why this slice, and what it deliberately excludes

Three phases have produced a tested engine with no interface. This slice exists to prove the layers connect and to put something in front of the owner before ten more phases of the same.

**Entity choice.** The slice manages **users**, not students. Students require `AcademicYear`, `ClassLevel`, `ClassGroup` and `EnrollmentSegment`, none of which exist — a "thin" student slice would pull in most of Phases 1 and 2 and stop being thin. The `User` domain is complete and tested from 0B, and User Management is a real screen in the mockups (`09-ui` §8.10). That keeps this slice about the UI layers.

**No starter kit.** Laravel Breeze/Jetstream ship their own auth views and layout conventions that would fight the mockups' shell. Building the shell directly is less work than overriding one.

**Excluded on purpose:**

| Excluded | Why |
|---|---|
| Student, class and academic screens | Their domain does not exist yet — Phases 1–2 |
| The full 17-item sidebar | Only the items whose modules exist are enabled; the rest render disabled with a "coming in a later phase" title, so the shell is honest rather than a mock |
| Password reset by email | `00-core` §9.3: admin-driven reset only; most Cameroonian schools have no SMTP |
| 2FA, session timeout, idle lock | Roadmap in `00-core` §9.4; not needed to prove the slice |
| The health *page* | `CollectHealth` already exists; this slice surfaces it as a dashboard tile, not the full page from 0C-b |

---

## Environment

```powershell
$env:PATH = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64;C:\laragon\bin\composer;C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin;" + $env:PATH
cd C:\laragon\www\opeschool
php artisan opes:preflight
git checkout -b phase-0d-slice phase-0c
```

Node 24.16.0 and npm 11.13.0 are available. Tailwind 4 and Vite 8 are already in `package.json`; **Livewire is not installed.**

---

## File structure

```
resources/
├─ views/
│  ├─ layouts/
│  │  ├─ app.blade.php            the shell: top bar, sidebar, status strip
│  │  └─ guest.blade.php          login chrome
│  ├─ components/
│  │  ├─ list-screen.blade.php    THE reusable contract (09-ui §4)
│  │  ├─ kpi-card.blade.php
│  │  ├─ status-pill.blade.php
│  │  └─ empty-state.blade.php
│  └─ livewire/
│     ├─ auth/login.blade.php
│     ├─ dashboard.blade.php
│     └─ users/{index,form}.blade.php
├─ css/app.css                    Tailwind 4 + the OPES palette
└─ js/app.js

app/Modules/Identity/
├─ Livewire/
│  ├─ Auth/Login.php
│  └─ Users/{Index,Form}.php
├─ Actions/
│  ├─ AuthenticateUser.php        NEW — audits login and login_failed
│  └─ CreateUser.php              NEW — audited, permission-gated
└─ Http/Middleware/SetLocale.php  NEW

app/Modules/Operations/Livewire/Dashboard.php
```

**The palette**, from `00-core` §8 and the mockups: chrome green `#0B4A36`, primary green `#0B7A4B`, heritage red `#CE1126` (destructive and money-owed only), heritage yellow `#FCD116` (accents only), ivory `#FBFAF6`, sand `#F2EEE2`, charcoal `#26211A`. Red and yellow are **accents, never large fills**, and red keeps a single meaning so it never loses urgency.

---

## Task 1: Install Livewire and set up the front end

**Files:** `composer.json`, `package.json`, `resources/css/app.css`, `vite.config.js`

- [ ] **Step 1: Branch and install Livewire**

```powershell
git checkout -b phase-0d-slice phase-0c
composer require livewire/livewire --no-interaction
php artisan livewire:publish --config
```

- [ ] **Step 2: Install node dependencies and confirm the build runs**

```powershell
npm install
npm run build
```

Expected: a `public/build/` directory appears. If the build fails, fix it before continuing — every later task depends on it.

- [ ] **Step 3: Define the palette in `resources/css/app.css`**

Tailwind 4 uses CSS-first configuration, so the palette is declared with `@theme` rather than in a JS config:

```css
@import "tailwindcss";

@theme {
    /* docs/specs/00-core.md §8. Red and yellow are ACCENTS only - never large
       fills, never adjacent as blocks. Red keeps one meaning (money owed,
       destructive, error) so it never loses urgency. */
    --color-chrome: #0B4A36;
    --color-chrome-light: #11614A;
    --color-primary: #0B7A4B;
    --color-heritage-red: #CE1126;
    --color-heritage-yellow: #FCD116;
    --color-ivory: #FBFAF6;
    --color-sand: #F2EEE2;
    --color-charcoal: #26211A;

    --font-sans: "Inter", ui-sans-serif, system-ui, sans-serif;
}

/* Visible focus on every control - 09-ui §10 requires full keyboard operation,
   and a marks grid used for hours is unusable without it. */
:focus-visible {
    outline: 2px solid var(--color-primary);
    outline-offset: 2px;
}
```

- [ ] **Step 4: Verify the gates still pass and commit**

```powershell
composer analyse
php artisan test
git add -A
git commit -m "chore: install Livewire 3 and define the OPES palette in Tailwind 4"
```

---

## Task 2: Authentication

Login is the first screen anyone sees, and `00-core` §14 requires both successful and failed attempts to be audited.

**Files:** `app/Modules/Identity/Actions/AuthenticateUser.php`, `app/Modules/Identity/Livewire/Auth/Login.php`, `resources/views/livewire/auth/login.blade.php`, `resources/views/layouts/guest.blade.php`, `tests/Feature/Identity/LoginTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Livewire\Auth\Login;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function loginUser(string $password = 'Correct-Horse-1'): User
{
    (new \Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create(['email' => 'bursar@school.test', 'password' => $password]);
    $user->assignRole(Role::Bursar->value);

    return $user->fresh() ?? $user;
}

it('logs a user in with correct credentials', function () {
    loginUser();

    Livewire::test(Login::class)
        ->set('email', 'bursar@school.test')
        ->set('password', 'Correct-Horse-1')
        ->call('authenticate')
        ->assertRedirect('/dashboard');

    expect(auth()->check())->toBeTrue();
});

it('rejects a wrong password without saying which field was wrong', function () {
    loginUser();

    Livewire::test(Login::class)
        ->set('email', 'bursar@school.test')
        ->set('password', 'wrong')
        ->call('authenticate')
        ->assertHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

it('refuses a suspended user', function () {
    // 00-core §10.5: users are never deleted, only suspended. A suspended
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

it('audits a failed login without recording the attempted password', function () {
    loginUser();

    Livewire::test(Login::class)
        ->set('email', 'bursar@school.test')
        ->set('password', 'hunter2')
        ->call('authenticate');

    $entry = AuditLog::query()->where('action', 'login_failed')->firstOrFail();

    $blob = json_encode($entry->getAttributes(), JSON_THROW_ON_ERROR);
    expect($blob)->not->toContain('hunter2');
});

it('throttles repeated failures', function () {
    loginUser();

    $component = Livewire::test(Login::class)->set('email', 'bursar@school.test')->set('password', 'wrong');

    for ($i = 0; $i < 6; $i++) {
        $component->call('authenticate');
    }

    $component->call('authenticate')->assertHasErrors('email');
    expect(session()->get('errors')?->first('email') ?? '')->toBeString();
});

it('redirects an authenticated user away from the login screen', function () {
    $user = loginUser();

    $this->actingAs($user)->get('/login')->assertRedirect('/dashboard');
});
```

- [ ] **Step 2: Run it, verify it fails**

- [ ] **Step 3: `AuthenticateUser` Action**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * The single place a session is established.
 *
 * Both outcomes are audited. A failed login is the more interesting of the two
 * for an auditor - a run of them is what an intrusion attempt looks like - and
 * neither may ever record the attempted password (00-core 14).
 */
final class AuthenticateUser
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(string $email, string $password, bool $remember = false): bool
    {
        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        // Hash::check on a dummy when the user is absent, so a missing account
        // and a wrong password take the same time and cannot be told apart.
        $hash = $user->password ?? '$argon2id$v=19$m=65536,t=4,p=1$aaaaaaaaaaaaaaaa$aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $passwordOk = Hash::check($password, $hash);

        if ($user === null || ! $passwordOk || $user->isSuspended()) {
            $this->audit->handle(
                action: AuditAction::LoginFailed,
                module: 'Identity',
                after: ['email' => $email, 'reason' => $this->reason($user, $passwordOk)],
                actor: $user?->toAuditActor(),
            );

            return false;
        }

        Auth::login($user, $remember);
        session()->regenerate();

        $this->audit->handle(
            action: AuditAction::Login,
            module: 'Identity',
            auditableType: User::class,
            auditableId: (int) $user->getKey(),
            actor: $user->toAuditActor(),
        );

        return true;
    }

    private function reason(?User $user, bool $passwordOk): string
    {
        if ($user === null) {
            return 'unknown_email';
        }

        if (! $passwordOk) {
            return 'wrong_password';
        }

        return 'suspended';
    }
}
```

Note the audit payload records the **email and a reason code**, never the password. The reason is deliberately recorded server-side while the *user-facing* error stays generic.

- [ ] **Step 4: The Livewire component**

`app/Modules/Identity/Livewire/Auth/Login.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Livewire\Auth;

use App\Modules\Identity\Actions\AuthenticateUser;
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

        $key = 'login:'.strtolower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => __('opes.auth.throttled', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        if (! $authenticate->handle($this->email, $this->password, $this->remember)) {
            RateLimiter::hit($key, 300);
            $this->reset('password');

            // One generic message for every failure mode. Saying "no such user"
            // would turn the login form into an account-enumeration oracle.
            throw ValidationException::withMessages(['email' => __('opes.auth.failed')]);
        }

        RateLimiter::clear($key);

        return $this->redirect('/dashboard', navigate: true);
    }

    public function render(): mixed
    {
        return view('livewire.auth.login');
    }
}
```

- [ ] **Step 5: The views**

`resources/views/layouts/guest.blade.php` — centred card on the ivory background, chrome-green header band, the school crest, and the language switcher. `resources/views/livewire/auth/login.blade.php` — email, password, remember, submit, and a "forgot your password?" note that says **to contact an administrator**, because there is no email reset by design.

- [ ] **Step 6: Routes and middleware**

In `routes/web.php`:

```php
Route::middleware('guest')->group(function (): void {
    Route::get('/login', \App\Modules\Identity\Livewire\Auth\Login::class)->name('login');
});

Route::post('/logout', function () {
    auth()->logout();
    session()->invalidate();
    session()->regenerateToken();

    return redirect('/login');
})->middleware('auth')->name('logout');
```

Point `/` at `/dashboard` for authenticated users and `/login` otherwise.

- [ ] **Step 7: Verify and commit**

```powershell
php artisan test --filter=LoginTest
composer analyse
git commit -am "feat: add audited login with throttling and generic failure messages"
```

---

## Task 3: The application shell

Built once, composed by every screen thereafter. Get this right and the rest of the product is cheap.

**Files:** `resources/views/layouts/app.blade.php`, `app/Modules/Identity/Http/Middleware/SetLocale.php`, `resources/views/components/*.blade.php`, `tests/Feature/Ui/ShellTest.php`

- [ ] **Step 1: The test**

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function shellUser(Role $role = Role::Administrator): User
{
    (new \Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user->fresh() ?? $user;
}

it('requires authentication for the dashboard', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('shows the school name and the signed-in user', function () {
    $user = shellUser();

    $this->actingAs($user)->get('/dashboard')
        ->assertOk()
        ->assertSee('OPES SCHOOL')
        ->assertSee($user->name);
});

it('shows a nav item the user has permission for', function () {
    $this->actingAs(shellUser())->get('/dashboard')->assertSee('Users');
});

it('hides a nav item the user has no permission for', function () {
    // Hiding is not the control - the route is gated too (see the next test) -
    // but a bursar should not be shown doors that will not open.
    $this->actingAs(shellUser(Role::Bursar))->get('/dashboard')->assertDontSee('User Management');
});

it('blocks the route as well as hiding the link', function () {
    // 00-core §6.2 rule 3: authorisation lives in the Action and the route.
    // Hiding a menu item is presentation, never protection.
    $this->actingAs(shellUser(Role::Bursar))->get('/users')->assertForbidden();
});

it('renders in French when the locale is set', function () {
    $user = shellUser();
    session(['locale' => 'fr']);

    $this->actingAs($user)->get('/dashboard')->assertSee('Tableau de bord');
});

it('switches locale and keeps it for the next request', function () {
    $user = shellUser();

    $this->actingAs($user)->post('/locale', ['locale' => 'fr'])->assertRedirect();
    $this->actingAs($user)->get('/dashboard')->assertSee('Tableau de bord');
});

it('rejects a locale that is not supported', function () {
    $this->actingAs(shellUser())->post('/locale', ['locale' => 'xx'])->assertSessionHasErrors();
});
```

- [ ] **Step 2: `SetLocale` middleware**

Reads `session('locale')`, falls back to `config('app.locale')`, and only accepts `en` or `fr`. `00-core` §18 keeps the **operator's UI language** separate from the **school's document language** — this middleware governs only the former.

- [ ] **Step 3: The shell layout**

`resources/views/layouts/app.blade.php`, per `09-ui` §2.1:

- **Top bar** — logo lockup (yellow star + "OPES" + "SCHOOL" letter-spaced), global search **placeholder** (disabled, titled "coming in a later phase" — an honest stub, not a fake), notification bell, EN/FR switcher, user menu with sign-out.
- **Sidebar** — chrome green, continuous with the top bar. Items whose modules exist (Dashboard, Users, Settings) are live; the rest render **disabled with a title explaining why**. Active item takes a yellow left-edge accent.
- **Status strip** — `User: {name} | Role: {role} | v{version} | {connection}`.
- **Responsive** — sidebar becomes a drawer below `md`; the top bar collapses to a hamburger.

Nav items are declared in one array with `label`, `route`, `permission` and `enabled`, so §2's sitemap table is expressed once in code rather than scattered through markup.

- [ ] **Step 4: The shared components**

`x-kpi-card` (label, value, delta, sparkline slot, drill-through), `x-status-pill` (ok/amber/red with **a letter or word as well as colour** — `09-ui` §10 forbids colour-only meaning, which also fixes greyscale printing), `x-empty-state` (icon, message, primary action).

- [ ] **Step 5: Verify and commit**

```powershell
php artisan test --filter=ShellTest
npm run build
composer analyse
git commit -am "feat: add the application shell with permission-aware navigation and EN/FR switching"
```

---

## Task 4: The dashboard

**Files:** `app/Modules/Operations/Livewire/Dashboard.php`, `resources/views/livewire/dashboard.blade.php`, `tests/Feature/Ui/DashboardTest.php`

Per `09-ui` §3, but only the tiles whose data exists.

- [ ] **Step 1: The test**

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Operations\Livewire\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function dashUser(): User
{
    (new \Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole(Role::Administrator->value);

    return $user->fresh() ?? $user;
}

it('shows the number of active users', function () {
    $user = dashUser();
    User::factory()->count(4)->create();

    Livewire::actingAs($user)->test(Dashboard::class)->assertSee('5');
});

it('surfaces a red health check as an alert the operator can act on', function () {
    // No backup has been taken in a fresh install, so backup.recency is red.
    // The dashboard must surface that rather than leaving it in a CLI command
    // nobody runs (09-ui §3.4).
    Livewire::actingAs(dashUser())->test(Dashboard::class)
        ->assertSee('backup')
        ->assertSee('opes:backup:run');
});

it('renders a dash rather than zero for a metric with no data', function () {
    // 09-ui §3.3: zero and "not yet recorded" are different facts, and
    // conflating them is how a dashboard starts lying.
    Livewire::actingAs(dashUser())->test(Dashboard::class)->assertSee('—');
});

it('only shows alerts the user can act on', function () {
    (new \Database\Seeders\RolePermissionSeeder())->run();
    $bursar = User::factory()->create();
    $bursar->assignRole(Role::Bursar->value);

    Livewire::actingAs($bursar->fresh() ?? $bursar)->test(Dashboard::class)
        ->assertDontSee('opes:backup:run');
});
```

- [ ] **Step 2: The component**

Tiles: **Total Users** (active count), **Roles configured**, **System health** (worst status from `CollectHealth`), **Last backup** (age or `—`). Alerts come from `CollectHealth`, filtered to non-ok, and each renders its plain-language **remedy**. Alerts requiring `backup.run` or `setting.edit` are filtered by permission, per `09-ui` §3.4.

Quick Actions are permission-filtered: Add User, Take a Backup, Check Health.

- [ ] **Step 3: Verify and commit**

`feat: add the dashboard with health alerts filtered by permission`

---

## Task 5: The list-screen contract

**The highest-leverage task in this plan.** `09-ui` §4: every module screen uses one identical pattern, and writing it once saves roughly twenty screens of drift.

**Files:** `resources/views/components/list-screen.blade.php`, `tests/Feature/Ui/ListScreenTest.php`

- [ ] **Step 1: The test**

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders every slot of the contract', function () {
    $view = view('components.list-screen', [
        'breadcrumb' => ['Dashboard', 'Users'],
        'title' => 'User Management',
        'kpis' => view('test-stubs.kpis'),
        'filters' => view('test-stubs.filters'),
        'table' => view('test-stubs.table'),
        'rail' => view('test-stubs.rail'),
        'actions' => view('test-stubs.actions'),
    ])->render();

    expect($view)->toContain('User Management')
        ->toContain('Dashboard');
});

it('shows the empty state instead of an empty table', function () {
    // An empty table with headers and no rows tells the operator nothing about
    // whether the filter is wrong or the data is genuinely absent.
    $view = view('components.list-screen', [
        'title' => 'Users',
        'isEmpty' => true,
        'emptyMessage' => 'No users match these filters.',
    ])->render();

    expect($view)->toContain('No users match these filters.');
});
```

Create the stub views under `resources/views/test-stubs/`.

- [ ] **Step 2: The component**

```
breadcrumb · title · header actions (primary is a green split-button)
KPI strip (2-up scroll < md, 3-up md, 5-up lg)
filter bar, always ending Filter | Reset
optional status tabs with counts
table  |  right rail
"Showing X to Y of Z" · pagination · page-size selector
```

Binding rules from `09-ui` §4, enforced in the component itself:

1. The table is **always** server-paginated; the component takes a paginator, never a bare collection.
2. Filters are **URL state**, so a filtered list is linkable and survives a refresh.
3. `Reset` clears filters **and** returns to page 1.
4. The rail collapses first at narrow widths; **the page body never scrolls horizontally** — wide tables scroll inside their own container.
5. Below `md` the table renders as **cards, one per row**, not a shrunken grid.
6. An empty result renders `x-empty-state`, naming the reason and offering the primary action.

- [ ] **Step 3: Verify and commit**

`feat: add the reusable list-screen contract every module screen composes`

---

## Task 6: User Management

**Files:** `app/Modules/Identity/Actions/CreateUser.php`, `app/Modules/Identity/Livewire/Users/{Index,Form}.php`, views, `tests/Feature/Identity/UserManagementTest.php`

- [ ] **Step 1: The test**

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Livewire\Users\Form;
use App\Modules\Identity\Livewire\Users\Index;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function adminFor(): User
{
    (new \Database\Seeders\RolePermissionSeeder())->run();
    $admin = User::factory()->create(['name' => 'Admin One']);
    $admin->assignRole(Role::Administrator->value);

    return $admin->fresh() ?? $admin;
}

it('lists users', function () {
    $admin = adminFor();
    User::factory()->create(['name' => 'Ngwa Bertrand']);

    Livewire::actingAs($admin)->test(Index::class)->assertSee('Ngwa Bertrand');
});

it('paginates rather than loading every user', function () {
    // 00-core §6.2 rule 8: no unbounded collection query reaches a view.
    $admin = adminFor();
    User::factory()->count(30)->create();

    Livewire::actingAs($admin)->test(Index::class)
        ->assertViewHas('users', fn ($users) => $users->perPage() <= 25);
});

it('filters by search term', function () {
    $admin = adminFor();
    User::factory()->create(['name' => 'Ngwa Bertrand']);
    User::factory()->create(['name' => 'Mballa Chantal']);

    Livewire::actingAs($admin)->test(Index::class)
        ->set('search', 'Ngwa')
        ->assertSee('Ngwa Bertrand')
        ->assertDontSee('Mballa Chantal');
});

it('filters by role', function () {
    $admin = adminFor();
    $bursar = User::factory()->create(['name' => 'Mballa Chantal']);
    $bursar->assignRole(Role::Bursar->value);

    Livewire::actingAs($admin)->test(Index::class)
        ->set('role', Role::Bursar->value)
        ->assertSee('Mballa Chantal')
        ->assertDontSee('Admin One');
});

it('creates a user and audits it', function () {
    $admin = adminFor();

    Livewire::actingAs($admin)->test(Form::class)
        ->set('name', 'Njoya Paul')
        ->set('email', 'njoya@school.test')
        ->set('role', Role::Teacher->value)
        ->set('password', 'Str0ng-Passw0rd')
        ->call('save')
        ->assertRedirect('/users');

    $created = User::query()->where('email', 'njoya@school.test')->firstOrFail();

    expect($created->hasRole(Role::Teacher->value))->toBeTrue();
    expect(AuditLog::query()->where('action', 'created')->count())->toBeGreaterThan(0);
});

it('never writes the new password into the audit log', function () {
    $admin = adminFor();

    Livewire::actingAs($admin)->test(Form::class)
        ->set('name', 'Njoya Paul')
        ->set('email', 'njoya@school.test')
        ->set('role', Role::Teacher->value)
        ->set('password', 'Str0ng-Passw0rd')
        ->call('save');

    foreach (AuditLog::query()->get() as $entry) {
        expect(json_encode($entry->getAttributes(), JSON_THROW_ON_ERROR))
            ->not->toContain('Str0ng-Passw0rd');
    }
});

it('rejects a duplicate email', function () {
    $admin = adminFor();
    User::factory()->create(['email' => 'taken@school.test']);

    Livewire::actingAs($admin)->test(Form::class)
        ->set('name', 'Someone')
        ->set('email', 'taken@school.test')
        ->set('role', Role::Teacher->value)
        ->set('password', 'Str0ng-Passw0rd')
        ->call('save')
        ->assertHasErrors('email');
});

it('forbids a user without the manage permission', function () {
    (new \Database\Seeders\RolePermissionSeeder())->run();
    $bursar = User::factory()->create();
    $bursar->assignRole(Role::Bursar->value);

    Livewire::actingAs($bursar->fresh() ?? $bursar)
        ->test(Form::class)
        ->set('name', 'Someone')
        ->set('email', 'someone@school.test')
        ->set('role', Role::Teacher->value)
        ->set('password', 'Str0ng-Passw0rd')
        ->call('save')
        ->assertForbidden();
});

it('shows suspended users distinctly', function () {
    $admin = adminFor();
    User::factory()->create(['name' => 'Gone Away', 'status' => 'suspended']);

    Livewire::actingAs($admin)->test(Index::class)->assertSee('Suspended');
});
```

- [ ] **Step 2: `CreateUser` Action**

Permission-gated on `Permission::UserManage`, wrapped in a transaction, assigns the role, and audits with `before: null` and an `after` payload that contains **name, email and role — never the password**.

- [ ] **Step 3: The Livewire components**

`Index` — search, role filter, status filter, pagination, all as URL query state via `#[Url]`. Columns per `09-ui` §8.10: User · Username/Email · Role · Status · Last Login · Actions.

`Form` — create and edit. Authorises in `mount()` **and** in `save()`, because a component can be reached directly.

- [ ] **Step 4: Route with permission middleware**

```php
Route::middleware(['auth', 'can:user.view'])->group(function (): void {
    Route::get('/users', Index::class)->name('users.index');
    Route::get('/users/create', Form::class)->middleware('can:user.manage')->name('users.create');
});
```

- [ ] **Step 5: Verify and commit**

`feat: add User Management composing the list-screen contract`

---

## Task 7: Responsive, accessibility and the seeded first admin

**Files:** `database/seeders/FirstAdminSeeder.php`, `tests/Feature/Ui/ResponsiveTest.php`, `docs/DEVELOPMENT.md`, `README.md`

- [ ] **Step 1: A seeder so the app can be opened at all**

A fresh install has no users, so nobody can log in. Add `opes:create-admin` (interactive) **and** a `FirstAdminSeeder` for development, which creates a Super Admin with a **randomly generated password printed once**. It must refuse to run if any user already exists.

- [ ] **Step 2: Accessibility and responsive assertions**

```php
it('pairs every status colour with a word', function () {
    // 09-ui §10: colour never carries meaning alone - it fails colour-blind
    // users and greyscale printing on the mono lasers schools actually own.
    $admin = adminFor();
    User::factory()->create(['status' => 'suspended']);

    $html = $this->actingAs($admin)->get('/users')->getContent();

    expect($html)->toContain('Suspended');
});

it('gives every form control a label', function () {
    $html = $this->actingAs(adminFor())->get('/users/create')->getContent();

    preg_match_all('/<input[^>]*id="([^"]+)"/', (string) $html, $inputs);

    foreach ($inputs[1] as $id) {
        expect($html)->toContain('for="'.$id.'"');
    }
});

it('sets a lang attribute matching the locale', function () {
    session(['locale' => 'fr']);

    expect($this->actingAs(adminFor())->get('/dashboard')->getContent())->toContain('lang="fr"');
});
```

- [ ] **Step 3: Manual verification at four widths**

Run `npm run dev`, log in, and check **360 / 768 / 1024 / 1440**:

- the page body never scrolls horizontally at any width
- below `md` the sidebar is a drawer and the users table renders as cards
- every control is reachable by keyboard, with visible focus
- the language switcher works and the layout survives longer French strings

Record what you saw. Take a screenshot at 360 and at 1440 into `docs/screenshots/`.

- [ ] **Step 4: Document and tag**

Update `README.md`:

```markdown
Phase 0D — vertical slice. Complete.

You can now run the application: `php artisan serve`, then sign in and manage
users. This proves the full path — route → Livewire → Action → Model → MySQL →
audit log — and delivers the application shell and the list-screen contract
that every later module screen composes.
```

```powershell
composer analyse
php artisan test
npm run build
git commit -am "docs: document the vertical slice and how to run it"
git tag -a phase-0d -m "Phase 0D: vertical slice - login, shell, dashboard, user management"
```

---

## Definition of done

- [ ] `php artisan serve` → sign in → dashboard → users → create a user → see them listed
- [ ] `composer analyse` clean at level 8, still zero suppressions
- [ ] Full suite green
- [ ] A failed login is audited; the attempted password appears nowhere
- [ ] A suspended user cannot authenticate
- [ ] A bursar sees no Users link **and** gets 403 on `/users`
- [ ] The user list is paginated, never a full table load
- [ ] Creating a user is audited without the password
- [ ] EN/FR switching works and persists
- [ ] No horizontal body scroll at 360, 768, 1024 or 1440
- [ ] Every status colour is paired with a word
- [ ] Screenshots at 360 and 1440 committed
- [ ] Tagged `phase-0d`

---

## Self-review notes

**Spec coverage.** Implements `09-ui` §2 (shell, sitemap, status strip), §3 (dashboard tiles and alert rules, for the checks that exist), §4 (the list-screen contract in full), §8.10 (User Management), §10 (responsive and accessibility); `00-core` §6.1 (Livewire as a thin adapter over Actions), §9 (roles and permissions, enforced at route **and** Action), §14 (login and creation audited), §18 (bilingual).

**Deliberately excluded, and why**, in the Scope table: student and academic screens (domain absent), the full sidebar (disabled items render honestly rather than as mockups), email password reset (no SMTP in the target market), 2FA and idle lock (roadmap), the full health page (0C-b).

**Known limitation.** The dashboard shows four tiles because four are all the data supports. `09-ui` §3.1 specifies Students, Staff, Classes, Attendance and Fee Collection — those arrive with their modules. The component reads its tile list from an array so adding one later is a line, not a rewrite.

**Security notes worth keeping.** Login failure returns **one generic message** for unknown email, wrong password and suspended account — anything else turns the form into an account-enumeration oracle. `Hash::check` runs against a dummy hash when the user is absent so timing cannot distinguish the cases either. Throttling is per email **and** IP.

**Type consistency check.** `AuthenticateUser::handle(string, string, bool): bool` matches its single call site in `Login::authenticate()`; `CreateUser` is gated on `Permission::UserManage`, the same constant the route middleware uses (`can:user.manage`); `CollectHealth::handle()` returns `list<HealthCheckResult>` and the dashboard maps over `->status`, `->detail` and `->remedy`, which is the shape 0C built.
