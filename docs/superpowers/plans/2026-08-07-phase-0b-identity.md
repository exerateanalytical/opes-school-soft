# Phase 0B — Identity, Audit, Settings & i18n Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A school can log in with a real role, every state change is recorded in a tamper-evident audit log, configuration lives in a validated registry rather than scattered constants, and the whole UI is bilingual EN/FR — all proven by tests.

**Architecture:** Users, roles and permissions in an `Identity` module using `spatie/laravel-permission`. Audit is an append-only, **hash-chained** table written by a model observer, verified by a command. Settings are a typed registry with three behaviour classes, the strictest of which cannot silently alter an already-published number. Localisation uses Laravel's translation files with two independent language axes — the operator's UI language and the school's document language.

**Tech Stack:** PHP 8.3.30 (Laragon), Laravel 13.24.0, MySQL 8.4.3 (Laragon), Pest 4, PHPStan level 8, `spatie/laravel-permission`.

**Specs implemented:** `docs/specs/00-core.md` §9 (identity, roles, guardian boundary, recovery, secrets, encrypted fields), §10.5 (deletion matrix as it applies to users), §14 (audit), §18 (bilingual); `docs/specs/09-ui.md` §7.3 (settings registry).

**Depends on:** Phase 0A (`tag: phase-0a`). `Money`, `Rate`, `Score`, `BusinessDate` and the architecture tests must exist — the audit and settings code uses `BusinessDate`, and the numeric-policy test must keep passing.

---

## Scope

| Plan | Contents | Status |
|---|---|---|
| 0A | Skeleton · preflight · modules · `Money`/`Rate`/`Score`/`BusinessDate` · arch tests · CI | ✅ complete, tagged `phase-0a` |
| **0B (this)** | Users · roles & permissions · hash-chained audit · settings registry · i18n | this plan |
| 0C | Installer · TLS · backup + verified restore drill · health page · log rotation · queue supervision | later |
| 0D | 1,200-student reference fixture · performance-budget harness · demo data with manifest | later |

**Deliberately NOT in 0B:** the guardian authorization matrix (needs `Student`/`Enrollment` from Phase 2 — the `GuardianScopeMatrix` class is specified in `07-students` §7.5 and built there); 2FA (roadmap, not v1-blocking); the recovery **sheet** printing (needs the installer from 0C — the credential itself IS built here).

---

## Environment

Laragon binaries only. **Never MariaDB**, which Laragon also ships.

```powershell
$env:PATH = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64;C:\laragon\bin\composer;C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin;" + $env:PATH
cd C:\laragon\www\opeschool
php artisan opes:preflight   # must pass before starting
```

Branch from the 0A tag:

```powershell
git checkout -b phase-0b-identity phase-0a-foundation
```

---

## File structure

```
app/Modules/Identity/
├─ Domain/
│  ├─ Role.php                    enum of the 20 roles from 00-core 9.1
│  ├─ Permission.php              enum of permission strings
│  ├─ AuditAction.php             enum: created|updated|deleted|login|...
│  └─ RecoveryCode.php            value object: generation, formatting, verification
├─ Models/
│  ├─ User.php                    moved from app/Models/User.php
│  ├─ AuditLog.php
│  └─ RecoveryCredential.php
├─ Actions/
│  ├─ WriteAuditEntry.php         the ONLY writer of audit rows
│  ├─ VerifyAuditChain.php
│  ├─ SetUserPassword.php         admin-driven reset, no SMTP dependency
│  ├─ GenerateRecoveryCredential.php
│  └─ ConsumeRecoveryCredential.php
├─ Observers/
│  └─ Auditable.php               trait + observer wiring
├─ Console/
│  ├─ VerifyAuditChainCommand.php  opes:audit:verify
│  └─ PromoteAdminCommand.php      opes:promote-admin
└─ Database/migrations/

app/Modules/SchoolProfile/
├─ Domain/
│  ├─ SettingClass.php            cosmetic | operational | engine_behaviour
│  └─ SettingType.php             string | int | bool | json | money | rate | score
├─ Models/Setting.php
├─ Actions/
│  ├─ ReadSetting.php
│  └─ WriteSetting.php            validates, audits, blocks locked engine settings
└─ Database/migrations/

lang/
├─ en/{auth,validation,opes}.php
└─ fr/{auth,validation,opes}.php

tests/
├─ Unit/Identity/{RoleTest,RecoveryCodeTest}.php
├─ Feature/Identity/{AuditChainTest,AuthorizationMatrixTest,RecoveryCredentialTest,PasswordResetTest}.php
├─ Feature/SchoolProfile/SettingsRegistryTest.php
├─ Feature/LocalisationTest.php
└─ Architecture/AuditIntegrityTest.php
```

**Why `User` moves into `Identity/Models/`:** `00-core` §6.3 gives Identity ownership of users. Leaving it in `app/Models/` would put the single most-referenced model outside the module system on day one, and every later module would learn the wrong import path.

---

## Task 1: Branch and install spatie/laravel-permission

**Files:** modify `composer.json`, create `config/permission.php`, create the package migration.

- [ ] **Step 1: Branch from the 0A tag**

```powershell
$env:PATH = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64;C:\laragon\bin\composer;C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin;" + $env:PATH
cd C:\laragon\www\opeschool
git checkout -b phase-0b-identity phase-0a-foundation
git branch --show-current
```

Expected: `phase-0b-identity`

- [ ] **Step 2: Install the package**

```powershell
composer require spatie/laravel-permission --no-interaction
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
```

- [ ] **Step 3: Run the migration and confirm the tables land in MySQL**

```powershell
php artisan migrate
mysql -u root -D opeschool -e "SHOW TABLES LIKE '%permission%'; SHOW TABLES LIKE '%role%';"
```

Expected: `permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions`.

- [ ] **Step 4: Verify the gates still pass**

```powershell
composer analyse
php artisan test
```

Expected: PHPStan 0 errors, 78 tests pass.

If PHPStan reports errors from the published `config/permission.php` or the package migration, fix them properly — do NOT add an `ignoreErrors` entry.

- [ ] **Step 5: Commit**

```powershell
git add composer.json composer.lock config/permission.php database/migrations
git commit -m "chore: install spatie/laravel-permission"
```

---

## Task 2: The Role and Permission enums

The 20 roles from `00-core` §9.1 as a PHP enum, so a typo is a compile-time error rather than a silent no-match against a string.

**Files:**
- Create: `app/Modules/Identity/Domain/Role.php`
- Create: `app/Modules/Identity/Domain/Permission.php`
- Test: `tests/Unit/Identity/RoleTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Identity/RoleTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\Role;

it('has exactly the twenty roles the spec names', function () {
    expect(Role::cases())->toHaveCount(20);
});

it('exposes stable string values usable as database keys', function () {
    expect(Role::SuperAdmin->value)->toBe('super_admin');
    expect(Role::Bursar->value)->toBe('bursar');
    expect(Role::ClassMaster->value)->toBe('class_master');
    expect(Role::Guardian->value)->toBe('guardian');
});

it('gives every role a bilingual label', function () {
    foreach (Role::cases() as $role) {
        expect($role->label('en'))->not->toBe('');
        expect($role->label('fr'))->not->toBe('');
    }
});

it('uses the Cameroonian title where one exists', function () {
    expect(Role::Principal->label('fr'))->toBe('Proviseur');
    expect(Role::VicePrincipal->label('fr'))->toBe('Censeur');
});

it('marks the two portal roles as portal roles', function () {
    expect(Role::Guardian->isPortal())->toBeTrue();
    expect(Role::StaffPortal->isPortal())->toBeTrue();
    expect(Role::Bursar->isPortal())->toBeFalse();
});

it('grants every permission to super admin and nothing to portal roles by default', function () {
    expect(Role::SuperAdmin->defaultPermissions())->toBe(Permission::cases());
    expect(Role::Guardian->defaultPermissions())->toBe([]);
});

it('gives the bursar fee permissions but not ledger permissions', function () {
    $bursar = Role::Bursar->defaultPermissions();

    expect($bursar)->toContain(Permission::FeeCollect);
    expect($bursar)->not->toContain(Permission::LedgerPost);
});

it('names every permission with a module.action shape', function () {
    foreach (Permission::cases() as $permission) {
        expect($permission->value)->toMatch('/^[a-z_]+\.[a-z_]+$/');
    }
});
```

- [ ] **Step 2: Run it and verify it fails**

```powershell
php artisan test --filter=RoleTest
```
Expected: FAIL, class not found.

- [ ] **Step 3: Write `Permission`**

Create `app/Modules/Identity/Domain/Permission.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * Granular permissions, named module.action.
 *
 * An enum rather than free strings so a typo fails at analysis time instead of
 * silently matching nothing and denying access for reasons nobody can find.
 * 00-core 9.1: every permission is individually grantable on top of the role
 * baseline.
 *
 * This is the Phase 0B set. Later phases ADD cases as their modules land; they
 * must not rename existing ones, because role seeds and granted permissions
 * reference the values.
 */
enum Permission: string
{
    // Identity
    case UserView = 'user.view';
    case UserManage = 'user.manage';
    case UserSetPassword = 'user.set_password';
    case RoleAssign = 'role.assign';
    case PermissionGrant = 'permission.grant';

    // Audit
    case AuditView = 'audit.view';
    case AuditExport = 'audit.export';

    // Settings
    case SettingView = 'setting.view';
    case SettingEdit = 'setting.edit';
    case SettingEditEngine = 'setting.edit_engine';

    // Fees — seeded now so role baselines are meaningful; enforced in Phase 6
    case FeeView = 'fee.view';
    case FeeCollect = 'fee.collect';
    case FeeVoid = 'fee.void';

    // Accounting — enforced in Phase 4
    case LedgerView = 'ledger.view';
    case LedgerPost = 'ledger.post';

    // Operations
    case BackupRun = 'backup.run';
    case BackupRestore = 'backup.restore';
    case LicenceManage = 'licence.manage';

    public function label(string $locale = 'en'): string
    {
        return __('opes.permissions.'.$this->value, [], $locale);
    }
}
```

- [ ] **Step 4: Write `Role`**

Create `app/Modules/Identity/Domain/Role.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * The fixed role set from docs/specs/00-core.md 9.1.
 *
 * Roles are a baseline, not a ceiling: every Permission is individually
 * grantable on top of a user's role. The French labels use the Cameroonian
 * titles (Proviseur, Censeur) rather than literal translations.
 */
enum Role: string
{
    case SuperAdmin = 'super_admin';
    case Administrator = 'administrator';
    case Principal = 'principal';
    case VicePrincipal = 'vice_principal';
    case Registrar = 'registrar';
    case Bursar = 'bursar';
    case Accountant = 'accountant';
    case HrOfficer = 'hr_officer';
    case PayrollOfficer = 'payroll_officer';
    case ExamsOfficer = 'exams_officer';
    case ClassMaster = 'class_master';
    case Teacher = 'teacher';
    case DisciplineMaster = 'discipline_master';
    case Librarian = 'librarian';
    case StoreKeeper = 'store_keeper';
    case Nurse = 'nurse';
    case WelfareOfficer = 'welfare_officer';
    case FrontDesk = 'front_desk';
    case Guardian = 'guardian';
    case StaffPortal = 'staff_portal';

    public function label(string $locale = 'en'): string
    {
        return __('opes.roles.'.$this->value, [], $locale);
    }

    /** Portal roles are self-service and hold no operational permissions. */
    public function isPortal(): bool
    {
        return $this === self::Guardian || $this === self::StaffPortal;
    }

    /**
     * The seeded baseline. Portal roles get nothing here: guardian access is
     * decided per-child by GuardianScopeMatrix (07-students 7.5, Phase 2), and
     * granting it through a role would create a second, contradictory source
     * of truth for the highest-risk boundary in the product.
     *
     * @return list<Permission>
     */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::SuperAdmin => Permission::cases(),

            self::Administrator => array_values(array_filter(
                Permission::cases(),
                static fn (Permission $p): bool => ! in_array(
                    $p,
                    [Permission::LicenceManage, Permission::BackupRestore],
                    true,
                ),
            )),

            self::Principal => [
                Permission::UserView, Permission::AuditView,
                Permission::SettingView, Permission::FeeView, Permission::LedgerView,
            ],

            self::VicePrincipal => [Permission::UserView, Permission::SettingView],

            self::Bursar => [Permission::FeeView, Permission::FeeCollect],

            self::Accountant => [
                Permission::FeeView, Permission::LedgerView, Permission::LedgerPost,
                Permission::FeeVoid,
            ],

            self::Registrar, self::HrOfficer, self::PayrollOfficer,
            self::ExamsOfficer, self::ClassMaster, self::Teacher,
            self::DisciplineMaster, self::Librarian, self::StoreKeeper,
            self::Nurse, self::WelfareOfficer, self::FrontDesk => [],

            self::Guardian, self::StaffPortal => [],
        };
    }
}
```

Note the roles returning `[]`: their permissions belong to modules that do not exist yet. Each later phase adds its own cases and extends this match. Returning an empty baseline now is honest; inventing permission names for unbuilt modules is not.

- [ ] **Step 5: Run the test**

```powershell
php artisan test --filter=RoleTest
```

The bilingual-label tests need the `lang/` files from Task 8. **Expected at this point: the two label tests FAIL**, the rest pass. That is correct ordering — do not add translation files here. Note the failure count and proceed; Task 8 turns them green.

If you prefer a fully green suite at every commit, run only `--filter="has exactly the twenty roles"` etc. to confirm the others pass, and commit. The plan re-runs the full suite in Task 8.

- [ ] **Step 6: Write the roles seeder**

The seeder lives here, not later, because Tasks 6 and 9 both call
`$this->seed(RolePermissionSeeder::class)` in their `beforeEach`. Its only
dependencies are the spatie models from Task 1 and the enums you just wrote.

Create `database/seeders/RolePermissionSeeder.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Domain\Permission as PermissionEnum;
use App\Modules\Identity\Domain\Role as RoleEnum;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionEnum::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        foreach (RoleEnum::cases() as $role) {
            $model = Role::findOrCreate($role->value, 'web');

            $model->syncPermissions(
                array_map(
                    static fn (PermissionEnum $p): string => $p->value,
                    $role->defaultPermissions(),
                )
            );
        }
    }
}
```

Verify it runs:

```powershell
php artisan db:seed --class=Database\\Seeders\\RolePermissionSeeder
mysql -u root -D opeschool -e "SELECT COUNT(*) AS roles FROM roles; SELECT COUNT(*) AS perms FROM permissions;"
```

Expected: 20 roles, 18 permissions.

- [ ] **Step 7: Commit**

```powershell
git add app/Modules/Identity/Domain database/seeders tests/Unit/Identity/RoleTest.php
git commit -m "feat: add Role and Permission enums and their seeder"
```

---

## Task 3: Move `User` into the Identity module

**Files:**
- Create: `app/Modules/Identity/Models/User.php`
- Delete: `app/Models/User.php`
- Modify: `config/auth.php`, `database/factories/UserFactory.php`

- [ ] **Step 1: Create the new model**

Create `app/Modules/Identity/Models/User.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $status
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use Notifiable;

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'password', 'status'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password_at' => 'datetime',
        ];
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }
}
```

- [ ] **Step 2: Point everything at the new location**

- `config/auth.php` → `'model' => App\Modules\Identity\Models\User::class`
- `database/factories/UserFactory.php` → `protected $model = \App\Modules\Identity\Models\User::class;`
- Delete `app/Models/User.php`
- Search the codebase for `App\Models\User` and update every hit:

```powershell
Select-String -Path app,tests,config,database,routes -Pattern "App\\Models\\User" -Recurse
```

Expected after fixing: no matches.

- [ ] **Step 3: Add the `status` and `must_change_password_at` columns**

```powershell
php artisan make:migration add_status_to_users_table
```

Migration `up()`:

```php
Schema::table('users', function (Blueprint $table): void {
    $table->string('status', 20)->default('active')->after('email');
    $table->timestamp('must_change_password_at')->nullable()->after('status');
    $table->index('status');
});
```

`down()`:

```php
Schema::table('users', function (Blueprint $table): void {
    $table->dropIndex(['status']);
    $table->dropColumn(['status', 'must_change_password_at']);
});
```

`00-core` §10.5: users are **never deleted**, only deactivated — `suspended` is a first-class status so actor foreign keys in the audit log stay resolvable forever.

- [ ] **Step 4: Set Argon2id as the hashing driver**

In `config/hashing.php` set `'driver' => 'argon2id'`. `00-core` §9.4 requires it, and Phase 0A's preflight already verified it is available.

- [ ] **Step 5: Verify**

```powershell
php artisan migrate
php artisan test
composer analyse
```

Both must pass. 78 tests plus whatever Task 2 added.

- [ ] **Step 6: Commit**

```powershell
git add -A
git commit -m "refactor: move User into the Identity module and add status column"
```

---

## Task 4: The hash-chained audit log

`00-core` §14: append-only **and enforced** — hash-chained, verified, because "immutable by convention" satisfies no auditor.

**Files:**
- Create: migration `create_audit_logs_table`
- Create: `app/Modules/Identity/Models/AuditLog.php`
- Create: `app/Modules/Identity/Domain/AuditAction.php`
- Create: `app/Modules/Identity/Actions/WriteAuditEntry.php`
- Create: `app/Modules/Identity/Actions/VerifyAuditChain.php`
- Test: `tests/Feature/Identity/AuditChainTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Identity/AuditChainTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Actions\VerifyAuditChain;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function writeEntry(?User $actor = null, string $action = 'updated'): AuditLog
{
    return app(WriteAuditEntry::class)->handle(
        action: AuditAction::from($action),
        module: 'Identity',
        auditableType: User::class,
        auditableId: 1,
        before: ['name' => 'Old'],
        after: ['name' => 'New'],
        actor: $actor,
    );
}

it('writes an entry with a genesis hash when the log is empty', function () {
    $entry = writeEntry();

    expect($entry->prev_hash)->toBe(str_repeat('0', 64));
    expect($entry->row_hash)->toHaveLength(64);
});

it('chains each entry to its predecessor', function () {
    $first = writeEntry();
    $second = writeEntry();

    expect($second->prev_hash)->toBe($first->row_hash);
});

it('verifies an intact chain', function () {
    writeEntry();
    writeEntry();
    writeEntry();

    $result = app(VerifyAuditChain::class)->handle();

    expect($result->isIntact())->toBeTrue();
    expect($result->checked)->toBe(3);
    expect($result->firstBrokenId)->toBeNull();
});

it('detects a tampered payload', function () {
    writeEntry();
    $target = writeEntry();
    writeEntry();

    // Tamper below the model layer, exactly as an attacker with DB access would.
    DB::table('audit_logs')->where('id', $target->id)->update(['after' => json_encode(['name' => 'Forged'])]);

    $result = app(VerifyAuditChain::class)->handle();

    expect($result->isIntact())->toBeFalse();
    expect($result->firstBrokenId)->toBe($target->id);
});

it('detects a deleted row', function () {
    writeEntry();
    $target = writeEntry();
    writeEntry();

    DB::table('audit_logs')->where('id', $target->id)->delete();

    expect(app(VerifyAuditChain::class)->handle()->isIntact())->toBeFalse();
});

it('records the actor name at the time so the entry survives a rename', function () {
    $user = User::factory()->create(['name' => 'Original Name']);

    $entry = writeEntry($user);
    expect($entry->actor_name_at_time)->toBe('Original Name');

    $user->update(['name' => 'Changed Name']);

    expect($entry->fresh()?->actor_name_at_time)->toBe('Original Name');
});

it('records a system actor when there is no authenticated user', function () {
    $entry = writeEntry(null);

    expect($entry->actor_id)->toBeNull();
    expect($entry->actor_name_at_time)->toBe('system');
});

it('refuses to update an existing entry', function () {
    $entry = writeEntry();
    $entry->action = 'deleted';
    $entry->save();
})->throws(RuntimeException::class, 'append-only');

it('refuses to delete an entry', function () {
    writeEntry()->delete();
})->throws(RuntimeException::class, 'append-only');
```

- [ ] **Step 2: Run it and verify it fails**

```powershell
php artisan test --filter=AuditChainTest
```

- [ ] **Step 3: Write the migration**

```powershell
php artisan make:migration create_audit_logs_table
```

`up()`:

```php
Schema::create('audit_logs', function (Blueprint $table): void {
    $table->bigIncrements('id');
    $table->string('action', 40);
    $table->string('module', 60);
    $table->string('auditable_type', 191)->nullable();
    $table->unsignedBigInteger('auditable_id')->nullable();
    $table->unsignedBigInteger('actor_id')->nullable();
    $table->string('actor_name_at_time', 191);
    $table->json('before')->nullable();
    $table->json('after')->nullable();
    $table->string('ip', 45)->nullable();
    $table->string('user_agent', 255)->nullable();
    $table->char('prev_hash', 64);
    $table->char('row_hash', 64);
    $table->timestamp('created_at')->useCurrent();

    // 00-core 14: indexed for the real question, "who changed this record".
    $table->index(['auditable_type', 'auditable_id', 'created_at'], 'audit_subject_idx');
    $table->index(['actor_id', 'created_at'], 'audit_actor_idx');
    $table->index(['module', 'created_at'], 'audit_module_idx');
    $table->unique('row_hash');

    // RESTRICT, never cascade: 00-core 10.5. Users are deactivated, not deleted,
    // so an audit entry can never be orphaned or silently removed with its actor.
    $table->foreign('actor_id')->references('id')->on('users')->restrictOnDelete();
});
```

`down()`: `Schema::dropIfExists('audit_logs');`

- [ ] **Step 4: Write `AuditAction`**

Create `app/Modules/Identity/Domain/AuditAction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

enum AuditAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Login = 'login';
    case LoginFailed = 'login_failed';
    case Logout = 'logout';
    case PasswordSet = 'password_set';
    case RecoveryGenerated = 'recovery_generated';
    case RecoveryUsed = 'recovery_used';
    case RoleAssigned = 'role_assigned';
    case PermissionGranted = 'permission_granted';
    case SettingChanged = 'setting_changed';
}
```

- [ ] **Step 5: Write the model**

Create `app/Modules/Identity/Models/AuditLog.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Append-only, hash-chained audit trail (docs/specs/00-core.md 14).
 *
 * The guards below stop the application from mutating history. They do not
 * stop someone with direct database access - that is what the hash chain is
 * for, and why VerifyAuditChain runs nightly.
 *
 * @property int $id
 * @property string $prev_hash
 * @property string $row_hash
 * @property string $actor_name_at_time
 */
class AuditLog extends Model
{
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new RuntimeException('AuditLog is append-only; entries cannot be updated.');
        });

        static::deleting(static function (): never {
            throw new RuntimeException('AuditLog is append-only; entries cannot be deleted.');
        });
    }

    /**
     * The canonical serialisation the hash covers.
     *
     * Field order is fixed and must never change, or every historical row
     * fails verification. Adding a field means a new chain segment, not a
     * silent reordering.
     *
     * @return array<string, mixed>
     */
    public function hashPayload(): array
    {
        return [
            'action' => $this->action,
            'module' => $this->module,
            'auditable_type' => $this->auditable_type,
            'auditable_id' => $this->auditable_id,
            'actor_id' => $this->actor_id,
            'actor_name_at_time' => $this->actor_name_at_time,
            'before' => $this->getRawOriginal('before'),
            'after' => $this->getRawOriginal('after'),
            'ip' => $this->ip,
            'user_agent' => $this->user_agent,
            'created_at' => $this->created_at?->toIso8601String(),
            'prev_hash' => $this->prev_hash,
        ];
    }

    public function computeRowHash(): string
    {
        return hash('sha256', json_encode($this->hashPayload(), JSON_THROW_ON_ERROR));
    }
}
```

- [ ] **Step 6: Write `WriteAuditEntry`**

Create `app/Modules/Identity/Actions/WriteAuditEntry.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * The ONLY writer of audit rows.
 *
 * Serialised on the tail of the chain: two concurrent writes reading the same
 * predecessor would produce two rows claiming the same prev_hash, which is a
 * fork, and a forked chain cannot be verified.
 */
final class WriteAuditEntry
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function handle(
        AuditAction $action,
        string $module,
        ?string $auditableType = null,
        ?int $auditableId = null,
        ?array $before = null,
        ?array $after = null,
        ?User $actor = null,
    ): AuditLog {
        return DB::transaction(function () use (
            $action, $module, $auditableType, $auditableId, $before, $after, $actor
        ): AuditLog {
            /** @var AuditLog|null $previous */
            $previous = AuditLog::query()
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $entry = new AuditLog([
                'action' => $action->value,
                'module' => $module,
                'auditable_type' => $auditableType,
                'auditable_id' => $auditableId,
                'actor_id' => $actor?->getKey(),
                'actor_name_at_time' => $actor?->name ?? 'system',
                'before' => $before,
                'after' => $after,
                'ip' => Request::ip(),
                'user_agent' => substr((string) Request::userAgent(), 0, 255),
                'prev_hash' => $previous?->row_hash ?? AuditLog::GENESIS_HASH,
            ]);

            $entry->created_at = now();
            $entry->row_hash = $entry->computeRowHash();
            $entry->save();

            return $entry;
        });
    }
}
```

- [ ] **Step 7: Write `VerifyAuditChain`**

Create `app/Modules/Identity/Actions/VerifyAuditChain.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\AuditLog;

final class AuditChainResult
{
    public function __construct(
        public readonly int $checked,
        public readonly ?int $firstBrokenId,
        public readonly ?string $reason,
    ) {
    }

    public function isIntact(): bool
    {
        return $this->firstBrokenId === null;
    }
}

/**
 * Walk the chain and confirm every row still hashes to what it claims, and
 * that each row's prev_hash matches its predecessor's row_hash.
 *
 * Catches both tampering (payload edited) and excision (row deleted).
 */
final class VerifyAuditChain
{
    public function handle(): AuditChainResult
    {
        $expectedPrevious = AuditLog::GENESIS_HASH;
        $checked = 0;
        $broken = null;
        $reason = null;

        AuditLog::query()
            ->orderBy('id')
            ->chunk(500, function ($entries) use (&$expectedPrevious, &$checked, &$broken, &$reason): bool {
                /** @var AuditLog $entry */
                foreach ($entries as $entry) {
                    $checked++;

                    if ($entry->prev_hash !== $expectedPrevious) {
                        $broken = $entry->id;
                        $reason = 'prev_hash does not match the previous row (row deleted or reordered)';

                        return false;
                    }

                    if ($entry->computeRowHash() !== $entry->row_hash) {
                        $broken = $entry->id;
                        $reason = 'row_hash does not match the payload (entry tampered with)';

                        return false;
                    }

                    $expectedPrevious = $entry->row_hash;
                }

                return true;
            });

        return new AuditChainResult($checked, $broken, $reason);
    }
}
```

- [ ] **Step 8: Run the test**

```powershell
php artisan test --filter=AuditChainTest
composer analyse
```

Expected: 9 tests pass, PHPStan clean. If PHPStan objects to the `chunk` callback types, add correct PHPDoc — do NOT suppress.

- [ ] **Step 9: Commit**

```powershell
git add app/Modules/Identity database/migrations tests/Feature/Identity/AuditChainTest.php
git commit -m "feat: add hash-chained append-only audit log with chain verification"
```

---

## Task 5: The audit verification command

**Files:**
- Create: `app/Modules/Identity/Console/VerifyAuditChainCommand.php`
- Modify: `routes/console.php` (schedule it nightly)
- Test: `tests/Feature/Identity/AuditChainTest.php` (append)

- [ ] **Step 1: Append the failing tests**

Add to `tests/Feature/Identity/AuditChainTest.php`:

```php
it('exits zero when the chain is intact', function () {
    writeEntry();
    writeEntry();

    $this->artisan('opes:audit:verify')
        ->expectsOutputToContain('intact')
        ->assertSuccessful();
});

it('exits non-zero and names the broken row when the chain is broken', function () {
    writeEntry();
    $target = writeEntry();

    DB::table('audit_logs')->where('id', $target->id)->update(['module' => 'Forged']);

    $this->artisan('opes:audit:verify')
        ->expectsOutputToContain((string) $target->id)
        ->assertFailed();
});
```

- [ ] **Step 2: Run and verify failure**

```powershell
php artisan test --filter=AuditChainTest
```

- [ ] **Step 3: Write the command**

Create `app/Modules/Identity/Console/VerifyAuditChainCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Actions\VerifyAuditChain;
use Illuminate\Console\Command;

final class VerifyAuditChainCommand extends Command
{
    protected $signature = 'opes:audit:verify';

    protected $description = 'Verify the audit log hash chain is intact.';

    public function handle(VerifyAuditChain $verify): int
    {
        $result = $verify->handle();

        if ($result->isIntact()) {
            $this->info("Audit chain intact. {$result->checked} entries verified.");

            return self::SUCCESS;
        }

        $this->error("Audit chain BROKEN at entry {$result->firstBrokenId}: {$result->reason}");
        $this->line("Entries verified before the break: {$result->checked}");
        $this->line('This means the audit table was modified outside the application.');

        return self::FAILURE;
    }
}
```

- [ ] **Step 4: Register the command's location**

Laravel 13 auto-discovers `app/Console/Commands`, but this command lives in a module. Register it in `bootstrap/app.php`:

```php
->withCommands([
    __DIR__.'/../app/Modules/Identity/Console',
])
```

Verify with `php artisan list | Select-String opes`. Both `opes:preflight` and `opes:audit:verify` must appear.

- [ ] **Step 5: Schedule it nightly**

In `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('opes:audit:verify')->dailyAt('02:30');
```

- [ ] **Step 6: Verify and commit**

```powershell
php artisan test --filter=AuditChainTest
composer analyse
git add app/Modules/Identity/Console bootstrap/app.php routes/console.php tests/Feature/Identity/AuditChainTest.php
git commit -m "feat: add opes:audit:verify command, scheduled nightly"
```

---

## Task 6: The recovery credential

`00-core` §9.3. Break-glass access for the common case: the bursar is the only admin, they leave, and there is no SMTP server to reset a password through.

**Files:**
- Create: migration `create_recovery_credentials_table`
- Create: `app/Modules/Identity/Domain/RecoveryCode.php`
- Create: `app/Modules/Identity/Models/RecoveryCredential.php`
- Create: `app/Modules/Identity/Actions/GenerateRecoveryCredential.php`
- Create: `app/Modules/Identity/Actions/ConsumeRecoveryCredential.php`
- Test: `tests/Unit/Identity/RecoveryCodeTest.php`, `tests/Feature/Identity/RecoveryCredentialTest.php`

- [ ] **Step 1: Write the value-object test**

Create `tests/Unit/Identity/RecoveryCodeTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\RecoveryCode;

it('generates four groups of five characters', function () {
    expect(RecoveryCode::generate()->formatted())->toMatch('/^[0-9A-Z]{5}-[0-9A-Z]{5}-[0-9A-Z]{5}-[0-9A-Z]{5}$/');
});

it('excludes the ambiguous characters', function () {
    // 00-core 9.3: a Crockford-style alphabet without 0/O/1/I/L/U, because the
    // code is read off paper by a stressed person under time pressure.
    for ($i = 0; $i < 200; $i++) {
        expect(RecoveryCode::generate()->formatted())->not->toMatch('/[0O1ILU]/');
    }
});

it('carries at least 90 bits of entropy', function () {
    expect(RecoveryCode::entropyBits())->toBeGreaterThanOrEqual(90);
});

it('normalises user input, tolerating case and missing dashes', function () {
    $code = RecoveryCode::generate();
    $messy = strtolower(str_replace('-', ' ', $code->formatted()));

    expect(RecoveryCode::normalise($messy))->toBe($code->normalised());
});

it('produces different codes each time', function () {
    $codes = [];
    for ($i = 0; $i < 100; $i++) {
        $codes[] = RecoveryCode::generate()->normalised();
    }

    expect(array_unique($codes))->toHaveCount(100);
});
```

- [ ] **Step 2: Write the feature test**

Create `tests/Feature/Identity/RecoveryCredentialTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Actions\ConsumeRecoveryCredential;
use App\Modules\Identity\Actions\GenerateRecoveryCredential;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\RecoveryCredential;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Admin One']);
    $this->admin->assignRole(Role::Administrator->value);
});

it('stores only a hash, never the code itself', function () {
    $plain = app(GenerateRecoveryCredential::class)->handle($this->admin);

    $stored = RecoveryCredential::query()->firstOrFail();

    expect($stored->getAttributes())->not->toContain($plain);
    expect($stored->code_hash)->not->toBe($plain);
});

it('keeps exactly one active credential, revoking the previous', function () {
    app(GenerateRecoveryCredential::class)->handle($this->admin);
    app(GenerateRecoveryCredential::class)->handle($this->admin);

    expect(RecoveryCredential::query()->whereNull('revoked_at')->whereNull('used_at')->count())->toBe(1);
    expect(RecoveryCredential::query()->count())->toBe(2);
});

it('expires after twelve months', function () {
    app(GenerateRecoveryCredential::class)->handle($this->admin);

    $credential = RecoveryCredential::query()->firstOrFail();

    expect($credential->expires_at->diffInDays($credential->created_at))->toBeGreaterThan(360);
});

it('authenticates the earliest active administrator', function () {
    $plain = app(GenerateRecoveryCredential::class)->handle($this->admin);

    $user = app(ConsumeRecoveryCredential::class)->handle($plain);

    expect($user?->is($this->admin))->toBeTrue();
});

it('is single use', function () {
    $plain = app(GenerateRecoveryCredential::class)->handle($this->admin);

    expect(app(ConsumeRecoveryCredential::class)->handle($plain))->not->toBeNull();
    expect(app(ConsumeRecoveryCredential::class)->handle($plain))->toBeNull();
});

it('rejects an expired credential', function () {
    $plain = app(GenerateRecoveryCredential::class)->handle($this->admin);

    RecoveryCredential::query()->update(['expires_at' => now()->subDay()]);

    expect(app(ConsumeRecoveryCredential::class)->handle($plain))->toBeNull();
});

it('rejects a wrong code', function () {
    app(GenerateRecoveryCredential::class)->handle($this->admin);

    expect(app(ConsumeRecoveryCredential::class)->handle('AAAAA-BBBBB-CCCCC-DDDDD'))->toBeNull();
});

it('audits both generation and use', function () {
    $plain = app(GenerateRecoveryCredential::class)->handle($this->admin);
    app(ConsumeRecoveryCredential::class)->handle($plain);

    expect(AuditLog::query()->where('action', 'recovery_generated')->count())->toBe(1);
    expect(AuditLog::query()->where('action', 'recovery_used')->count())->toBe(1);
});

it('never writes the plaintext code into the audit log', function () {
    $plain = app(GenerateRecoveryCredential::class)->handle($this->admin);
    app(ConsumeRecoveryCredential::class)->handle($plain);

    $normalised = \App\Modules\Identity\Domain\RecoveryCode::normalise($plain);

    foreach (AuditLog::query()->get() as $entry) {
        $blob = json_encode($entry->getAttributes(), JSON_THROW_ON_ERROR);
        expect($blob)->not->toContain($normalised);
    }
});
```

The last test matters: a recovery code leaked into the audit log would be a permanent backdoor sitting in the table designed to be read by auditors.

- [ ] **Step 3: Run both and verify they fail**

```powershell
php artisan test --filter="RecoveryCode|RecoveryCredential"
```

- [ ] **Step 4: Write `RecoveryCode`**

Create `app/Modules/Identity/Domain/RecoveryCode.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use Random\RandomException;

/**
 * A break-glass recovery code (docs/specs/00-core.md 9.3).
 *
 * Crockford-style alphabet with 0, O, 1, I, L and U removed: the code is read
 * off a sheet of paper in a school office by someone who has just lost access,
 * and 0-versus-O is exactly the failure you do not want at that moment. U is
 * dropped as well so the alphabet cannot spell anything unfortunate.
 */
final readonly class RecoveryCode
{
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ'; // 30 characters

    public const GROUPS = 4;

    public const GROUP_LENGTH = 5;

    private function __construct(private string $normalised)
    {
    }

    /**
     * @throws RandomException
     */
    public static function generate(): self
    {
        $length = self::GROUPS * self::GROUP_LENGTH;
        $max = strlen(self::ALPHABET) - 1;
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }

        return new self($out);
    }

    public static function fromNormalised(string $normalised): self
    {
        return new self($normalised);
    }

    /** Tolerant of case, dashes, spaces — however it was written down. */
    public static function normalise(string $input): string
    {
        return strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $input));
    }

    public static function entropyBits(): int
    {
        return (int) floor(
            self::GROUPS * self::GROUP_LENGTH * log(strlen(self::ALPHABET), 2)
        );
    }

    public function normalised(): string
    {
        return $this->normalised;
    }

    public function formatted(): string
    {
        return implode('-', str_split($this->normalised, self::GROUP_LENGTH));
    }
}
```

20 characters from a 30-character alphabet is ~98 bits, comfortably over the 90-bit assertion.

- [ ] **Step 5: Write the migration**

```powershell
php artisan make:migration create_recovery_credentials_table
```

`up()`:

```php
Schema::create('recovery_credentials', function (Blueprint $table): void {
    $table->bigIncrements('id');
    $table->string('code_hash', 255);
    $table->unsignedBigInteger('generated_by');
    $table->timestamp('expires_at');
    $table->timestamp('used_at')->nullable();
    $table->timestamp('revoked_at')->nullable();
    $table->timestamps();

    $table->foreign('generated_by')->references('id')->on('users')->restrictOnDelete();
    $table->index(['used_at', 'revoked_at', 'expires_at'], 'recovery_active_idx');
});
```

- [ ] **Step 6: Write the model**

Create `app/Modules/Identity/Models/RecoveryCredential.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code_hash
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property Carbon|null $revoked_at
 */
class RecoveryCredential extends Model
{
    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['code_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<RecoveryCredential>  $query
     * @return Builder<RecoveryCredential>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('used_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }
}
```

- [ ] **Step 7: Write the two Actions**

Create `app/Modules/Identity/Actions/GenerateRecoveryCredential.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\RecoveryCode;
use App\Modules\Identity\Models\RecoveryCredential;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class GenerateRecoveryCredential
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * Returns the PLAINTEXT code. It is never stored and never returned again -
     * the caller must show it to the operator once, for the recovery sheet.
     */
    public function handle(User $generatedBy): string
    {
        $code = RecoveryCode::generate();

        DB::transaction(function () use ($code, $generatedBy): void {
            // Single-active: generating a new credential revokes the old one,
            // so a code written down last year cannot still open the door.
            RecoveryCredential::query()
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            RecoveryCredential::query()->create([
                'code_hash' => Hash::make($code->normalised()),
                'generated_by' => $generatedBy->getKey(),
                'expires_at' => now()->addMonths(12),
            ]);

            $this->audit->handle(
                action: AuditAction::RecoveryGenerated,
                module: 'Identity',
                actor: $generatedBy,
            );
        });

        return $code->formatted();
    }
}
```

Create `app/Modules/Identity/Actions/ConsumeRecoveryCredential.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\RecoveryCode;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\RecoveryCredential;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class ConsumeRecoveryCredential
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /** Returns the user to log in as, or null if the code is not usable. */
    public function handle(string $plainCode): ?User
    {
        $normalised = RecoveryCode::normalise($plainCode);

        return DB::transaction(function () use ($normalised): ?User {
            /** @var RecoveryCredential|null $credential */
            $credential = RecoveryCredential::query()
                ->active()
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($credential === null || ! Hash::check($normalised, $credential->code_hash)) {
                return null;
            }

            /** @var User|null $admin */
            $admin = User::query()
                ->where('status', 'active')
                ->whereHas('roles', static function ($q): void {
                    $q->whereIn('name', [Role::SuperAdmin->value, Role::Administrator->value]);
                })
                ->orderBy('id')
                ->first();

            if ($admin === null) {
                return null;
            }

            $credential->used_at = now();
            $credential->save();

            $this->audit->handle(
                action: AuditAction::RecoveryUsed,
                module: 'Identity',
                auditableType: User::class,
                auditableId: (int) $admin->getKey(),
                actor: $admin,
            );

            return $admin;
        });
    }
}
```

- [ ] **Step 8: Verify and commit**

```powershell
php artisan test --filter="RecoveryCode|RecoveryCredential"
composer analyse
git add app/Modules/Identity database/migrations tests/Unit/Identity/RecoveryCodeTest.php tests/Feature/Identity/RecoveryCredentialTest.php
git commit -m "feat: add single-use expiring break-glass recovery credential"
```

---

## Task 7: The settings registry

`09-ui` §7.3. Three behaviour classes, and the strictest cannot silently alter an already-published number.

**Files:**
- Create: migration `create_settings_table`
- Create: `app/Modules/SchoolProfile/Domain/{SettingClass,SettingType}.php`
- Create: `app/Modules/SchoolProfile/Models/Setting.php`
- Create: `app/Modules/SchoolProfile/Actions/{ReadSetting,WriteSetting}.php`
- Test: `tests/Feature/SchoolProfile/SettingsRegistryTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SchoolProfile/SettingsRegistryTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\SchoolProfile\Actions\ReadSetting;
use App\Modules\SchoolProfile\Actions\WriteSetting;
use App\Modules\SchoolProfile\Domain\SettingClass;
use App\Modules\SchoolProfile\Domain\SettingType;
use App\Modules\SchoolProfile\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actor = User::factory()->create();
});

function defineSetting(string $key, SettingType $type, SettingClass $class, mixed $default, ?string $rule = null): Setting
{
    return Setting::query()->create([
        'key' => $key,
        'value' => json_encode($default, JSON_THROW_ON_ERROR),
        'default_value' => json_encode($default, JSON_THROW_ON_ERROR),
        'value_type' => $type->value,
        'setting_class' => $class->value,
        'scope' => 'global',
        'validation_rule' => $rule,
    ]);
}

it('reads a typed value back as its PHP type, not a string', function () {
    defineSetting('academic.pass_mark', SettingType::Int, SettingClass::EngineBehaviour, 50);
    defineSetting('ui.items_per_page', SettingType::Int, SettingClass::Cosmetic, 25);
    defineSetting('security.two_factor', SettingType::Bool, SettingClass::Operational, false);

    expect(app(ReadSetting::class)->handle('academic.pass_mark'))->toBe(50);
    expect(app(ReadSetting::class)->handle('security.two_factor'))->toBeFalse();
});

it('returns the default for an unknown key rather than throwing', function () {
    expect(app(ReadSetting::class)->handle('does.not.exist', 'fallback'))->toBe('fallback');
});

it('writes and audits a cosmetic setting', function () {
    defineSetting('ui.theme', SettingType::String, SettingClass::Cosmetic, 'green');

    app(WriteSetting::class)->handle('ui.theme', 'blue', $this->actor);

    expect(app(ReadSetting::class)->handle('ui.theme'))->toBe('blue');
    expect(AuditLog::query()->where('action', 'setting_changed')->count())->toBe(1);
});

it('records the old and new value in the audit entry', function () {
    defineSetting('ui.theme', SettingType::String, SettingClass::Cosmetic, 'green');

    app(WriteSetting::class)->handle('ui.theme', 'blue', $this->actor);

    $entry = AuditLog::query()->where('action', 'setting_changed')->firstOrFail();

    expect($entry->before)->toBe(['ui.theme' => 'green']);
    expect($entry->after)->toBe(['ui.theme' => 'blue']);
});

it('enforces the validation rule', function () {
    defineSetting('academic.pass_mark', SettingType::Int, SettingClass::EngineBehaviour, 50, 'integer|min:0|max:100');

    app(WriteSetting::class)->handle('academic.pass_mark', 150, $this->actor);
})->throws(\Illuminate\Validation\ValidationException::class);

it('rejects a value of the wrong type', function () {
    defineSetting('academic.pass_mark', SettingType::Int, SettingClass::EngineBehaviour, 50);

    app(WriteSetting::class)->handle('academic.pass_mark', 'fifty', $this->actor);
})->throws(\Illuminate\Validation\ValidationException::class);

it('refuses to change a locked engine-behaviour setting', function () {
    // The lock is what stops changing pass_mark from 50 to 45 in March from
    // silently reclassifying every mark already published (09-ui 7.3).
    $setting = defineSetting('academic.pass_mark', SettingType::Int, SettingClass::EngineBehaviour, 50);
    $setting->update(['locked_at' => now(), 'locked_reason' => 'Term 1 published']);

    app(WriteSetting::class)->handle('academic.pass_mark', 45, $this->actor);
})->throws(RuntimeException::class, 'locked');

it('still allows cosmetic changes while engine settings are locked', function () {
    defineSetting('ui.theme', SettingType::String, SettingClass::Cosmetic, 'green');
    $engine = defineSetting('academic.pass_mark', SettingType::Int, SettingClass::EngineBehaviour, 50);
    $engine->update(['locked_at' => now(), 'locked_reason' => 'Term 1 published']);

    app(WriteSetting::class)->handle('ui.theme', 'blue', $this->actor);

    expect(app(ReadSetting::class)->handle('ui.theme'))->toBe('blue');
});

it('caches reads and invalidates on write', function () {
    defineSetting('ui.theme', SettingType::String, SettingClass::Cosmetic, 'green');

    expect(app(ReadSetting::class)->handle('ui.theme'))->toBe('green');

    app(WriteSetting::class)->handle('ui.theme', 'blue', $this->actor);

    expect(app(ReadSetting::class)->handle('ui.theme'))->toBe('blue');
});
```

- [ ] **Step 2: Run and verify it fails**

```powershell
php artisan test --filter=SettingsRegistryTest
```

- [ ] **Step 3: Write the enums**

Create `app/Modules/SchoolProfile/Domain/SettingClass.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\SchoolProfile\Domain;

/**
 * How strictly a setting is governed (docs/specs/09-ui.md 7.3).
 */
enum SettingClass: string
{
    /** Theme, page size, date format. Free edit, audited. */
    case Cosmetic = 'cosmetic';

    /** Session timeout, upload limit, thresholds. Validated, audited, immediate. */
    case Operational = 'operational';

    /**
     * Pass mark, coefficients, promotion thresholds. Validated, audited, AND
     * lockable — changing one after a period is published would retroactively
     * alter numbers already printed and handed to parents.
     */
    case EngineBehaviour = 'engine_behaviour';

    public function isLockable(): bool
    {
        return $this === self::EngineBehaviour;
    }
}
```

Create `app/Modules/SchoolProfile/Domain/SettingType.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\SchoolProfile\Domain;

enum SettingType: string
{
    case String = 'string';
    case Int = 'int';
    case Bool = 'bool';
    case Json = 'json';

    /** Laravel validation rule fragment enforcing the storage type. */
    public function baseRule(): string
    {
        return match ($this) {
            self::String => 'string',
            self::Int => 'integer',
            self::Bool => 'boolean',
            self::Json => 'array',
        };
    }

    public function cast(mixed $decoded): mixed
    {
        return match ($this) {
            self::String => (string) $decoded,
            self::Int => (int) $decoded,
            self::Bool => (bool) $decoded,
            self::Json => $decoded,
        };
    }
}
```

- [ ] **Step 4: Write the migration**

```powershell
php artisan make:migration create_settings_table
```

`up()`:

```php
Schema::create('settings', function (Blueprint $table): void {
    $table->bigIncrements('id');
    $table->string('key', 120)->collation('utf8mb4_0900_as_cs');
    $table->json('value')->nullable();
    $table->json('default_value')->nullable();
    $table->string('value_type', 20);
    $table->string('setting_class', 30);
    $table->string('scope', 30)->default('global');
    $table->unsignedBigInteger('scope_id')->nullable();
    $table->string('validation_rule', 255)->nullable();
    $table->timestamp('locked_at')->nullable();
    $table->string('locked_reason', 255)->nullable();
    $table->unsignedBigInteger('updated_by')->nullable();
    $table->timestamps();

    // Sentinel 0 rather than NULL: MySQL UNIQUE ignores NULLs, so a nullable
    // scope_id would permit unlimited duplicate global rows for one key.
    // Same trap 04-fees documents on FeeStructure.
    $table->unsignedBigInteger('scope_key')->storedAs('COALESCE(scope_id, 0)');
    $table->unique(['key', 'scope', 'scope_key'], 'settings_key_scope_unique');

    $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
});
```

The `as_cs` collation on `key` is per `00-core` §4: identifier columns are case- and accent-sensitive, so `Academic.PassMark` and `academic.pass_mark` cannot collide.

- [ ] **Step 5: Write the model**

Create `app/Modules/SchoolProfile/Models/Setting.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\SchoolProfile\Models;

use App\Modules\SchoolProfile\Domain\SettingClass;
use App\Modules\SchoolProfile\Domain\SettingType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $key
 * @property string $value_type
 * @property string $setting_class
 * @property Carbon|null $locked_at
 */
class Setting extends Model
{
    /** @var list<string> */
    protected $guarded = [];

    public function type(): SettingType
    {
        return SettingType::from($this->value_type);
    }

    public function class(): SettingClass
    {
        return SettingClass::from($this->setting_class);
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function typedValue(): mixed
    {
        /** @var mixed $decoded */
        $decoded = json_decode((string) $this->getRawOriginal('value'), true, 512, JSON_THROW_ON_ERROR);

        return $this->type()->cast($decoded);
    }
}
```

- [ ] **Step 6: Write the Actions**

Create `app/Modules/SchoolProfile/Actions/ReadSetting.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\SchoolProfile\Actions;

use App\Modules\SchoolProfile\Models\Setting;
use Illuminate\Support\Facades\Cache;

final class ReadSetting
{
    public const CACHE_PREFIX = 'opes.setting.';

    public function handle(string $key, mixed $fallback = null, string $scope = 'global', ?int $scopeId = null): mixed
    {
        $cacheKey = self::cacheKey($key, $scope, $scopeId);

        /** @var array{hit: bool, value: mixed} $cached */
        $cached = Cache::rememberForever($cacheKey, static function () use ($key, $scope, $scopeId): array {
            /** @var Setting|null $setting */
            $setting = Setting::query()
                ->where('key', $key)
                ->where('scope', $scope)
                ->when($scopeId === null, static fn ($q) => $q->whereNull('scope_id'))
                ->when($scopeId !== null, static fn ($q) => $q->where('scope_id', $scopeId))
                ->first();

            return $setting === null
                ? ['hit' => false, 'value' => null]
                : ['hit' => true, 'value' => $setting->typedValue()];
        });

        return $cached['hit'] ? $cached['value'] : $fallback;
    }

    public static function cacheKey(string $key, string $scope, ?int $scopeId): string
    {
        return self::CACHE_PREFIX.$scope.'.'.($scopeId ?? 0).'.'.$key;
    }
}
```

Create `app/Modules/SchoolProfile/Actions/WriteSetting.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\SchoolProfile\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Models\User;
use App\Modules\SchoolProfile\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

final class WriteSetting
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(
        string $key,
        mixed $value,
        User $actor,
        string $scope = 'global',
        ?int $scopeId = null,
    ): Setting {
        return DB::transaction(function () use ($key, $value, $actor, $scope, $scopeId): Setting {
            /** @var Setting $setting */
            $setting = Setting::query()
                ->where('key', $key)
                ->where('scope', $scope)
                ->when($scopeId === null, static fn ($q) => $q->whereNull('scope_id'))
                ->when($scopeId !== null, static fn ($q) => $q->where('scope_id', $scopeId))
                ->lockForUpdate()
                ->firstOrFail();

            if ($setting->isLocked()) {
                throw new RuntimeException(
                    "Setting [{$key}] is locked: {$setting->locked_reason}. "
                    .'Engine-behaviour settings cannot change once a period using them is published.'
                );
            }

            $rules = $setting->type()->baseRule();

            if ($setting->validation_rule !== null && $setting->validation_rule !== '') {
                $rules = $setting->validation_rule;
            }

            Validator::validate(['value' => $value], ['value' => $rules]);

            $previous = $setting->typedValue();

            $setting->value = json_encode($value, JSON_THROW_ON_ERROR);
            $setting->updated_by = $actor->getKey();
            $setting->save();

            Cache::forget(ReadSetting::cacheKey($key, $scope, $scopeId));

            $this->audit->handle(
                action: AuditAction::SettingChanged,
                module: 'SchoolProfile',
                auditableType: Setting::class,
                auditableId: (int) $setting->getKey(),
                before: [$key => $previous],
                after: [$key => $value],
                actor: $actor,
            );

            return $setting;
        });
    }
}
```

- [ ] **Step 7: Verify and commit**

```powershell
php artisan test --filter=SettingsRegistryTest
composer analyse
git add app/Modules/SchoolProfile database/migrations tests/Feature/SchoolProfile
git commit -m "feat: add typed settings registry with lockable engine-behaviour class"
```

---

## Task 8: Authorization tests and bilingual strings

The seeder was written in Task 2 because Tasks 6 and 9 depend on it.

**Files:**
- Create: `lang/en/opes.php`, `lang/fr/opes.php`
- Create: `tests/Feature/Identity/AuthorizationMatrixTest.php`
- Create: `tests/Feature/LocalisationTest.php`

- [ ] **Step 1: Write the authorization test**

Create `tests/Feature/Identity/AuthorizationMatrixTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);
});

it('seeds every role and every permission', function () {
    expect(\Spatie\Permission\Models\Role::query()->count())->toBe(count(Role::cases()));
    expect(\Spatie\Permission\Models\Permission::query()->count())->toBe(count(Permission::cases()));
});

it('gives super admin every permission', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::SuperAdmin->value);

    foreach (Permission::cases() as $permission) {
        expect($user->can($permission->value))->toBeTrue("super admin lacks {$permission->value}");
    }
});

it('withholds licence and restore from a plain administrator', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::Administrator->value);

    expect($user->can(Permission::LicenceManage->value))->toBeFalse();
    expect($user->can(Permission::BackupRestore->value))->toBeFalse();
    expect($user->can(Permission::UserManage->value))->toBeTrue();
});

it('lets a bursar collect fees but not post to the ledger', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::Bursar->value);

    expect($user->can(Permission::FeeCollect->value))->toBeTrue();
    expect($user->can(Permission::LedgerPost->value))->toBeFalse();
});

it('gives portal roles no operational permission at all', function () {
    foreach ([Role::Guardian, Role::StaffPortal] as $role) {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        foreach (Permission::cases() as $permission) {
            expect($user->can($permission->value))->toBeFalse(
                "{$role->value} should not hold {$permission->value}"
            );
        }
    }
});

it('supports granting a single permission on top of a role baseline', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::Bursar->value);

    expect($user->can(Permission::LedgerView->value))->toBeFalse();

    $user->givePermissionTo(Permission::LedgerView->value);

    expect($user->can(Permission::LedgerView->value))->toBeTrue();
    expect($user->can(Permission::LedgerPost->value))->toBeFalse();
});
```

The portal-roles test is the important one: it is the first expression of the deny-by-default principle that `00-core` §9.2 requires for the guardian boundary.

- [ ] **Step 2: Run the authorization test — it should already pass**

```powershell
php artisan test --filter=AuthorizationMatrixTest
```

Expected: PASS. Unlike the other tasks this one is not red-first: the enums and
seeder from Task 2 already implement the behaviour, so this test *characterises*
what exists rather than driving new code. If it fails, the seeder or a
`defaultPermissions()` arm is wrong — fix that, not the test.

- [ ] **Step 3: Write the language files**

Create `lang/en/opes.php`:

```php
<?php

declare(strict_types=1);

return [
    'roles' => [
        'super_admin' => 'Super Administrator',
        'administrator' => 'Administrator',
        'principal' => 'Principal',
        'vice_principal' => 'Vice-Principal',
        'registrar' => 'Registrar',
        'bursar' => 'Bursar',
        'accountant' => 'Accountant',
        'hr_officer' => 'HR Officer',
        'payroll_officer' => 'Payroll Officer',
        'exams_officer' => 'Examinations Officer',
        'class_master' => 'Class Master',
        'teacher' => 'Teacher',
        'discipline_master' => 'Discipline Master',
        'librarian' => 'Librarian',
        'store_keeper' => 'Store Keeper',
        'nurse' => 'Nurse',
        'welfare_officer' => 'Welfare Officer',
        'front_desk' => 'Front Desk',
        'guardian' => 'Guardian',
        'staff_portal' => 'Staff',
    ],
    'permissions' => [
        'user.view' => 'View users',
        'user.manage' => 'Manage users',
        'user.set_password' => 'Set another user\'s password',
        'role.assign' => 'Assign roles',
        'permission.grant' => 'Grant individual permissions',
        'audit.view' => 'View the audit log',
        'audit.export' => 'Export the audit log',
        'setting.view' => 'View settings',
        'setting.edit' => 'Edit settings',
        'setting.edit_engine' => 'Edit engine-behaviour settings',
        'fee.view' => 'View fees',
        'fee.collect' => 'Collect payments',
        'fee.void' => 'Void payments',
        'ledger.view' => 'View the ledger',
        'ledger.post' => 'Post to the ledger',
        'backup.run' => 'Run a backup',
        'backup.restore' => 'Restore from a backup',
        'licence.manage' => 'Manage the licence',
    ],
];
```

Create `lang/fr/opes.php` with the same keys and French values. Use the **Cameroonian titles**, not literal translations:

```php
<?php

declare(strict_types=1);

return [
    'roles' => [
        'super_admin' => 'Super Administrateur',
        'administrator' => 'Administrateur',
        'principal' => 'Proviseur',
        'vice_principal' => 'Censeur',
        'registrar' => 'Secrétaire Général',
        'bursar' => 'Économe',
        'accountant' => 'Comptable',
        'hr_officer' => 'Responsable des Ressources Humaines',
        'payroll_officer' => 'Responsable de la Paie',
        'exams_officer' => 'Chef du Service des Examens',
        'class_master' => 'Professeur Principal',
        'teacher' => 'Enseignant',
        'discipline_master' => 'Surveillant Général',
        'librarian' => 'Bibliothécaire',
        'store_keeper' => 'Magasinier',
        'nurse' => 'Infirmier / Infirmière',
        'welfare_officer' => 'Responsable Internat et Transport',
        'front_desk' => 'Accueil',
        'guardian' => 'Parent / Tuteur',
        'staff_portal' => 'Personnel',
    ],
    'permissions' => [
        'user.view' => 'Consulter les utilisateurs',
        'user.manage' => 'Gérer les utilisateurs',
        'user.set_password' => 'Définir le mot de passe d\'un utilisateur',
        'role.assign' => 'Attribuer les rôles',
        'permission.grant' => 'Accorder des permissions individuelles',
        'audit.view' => 'Consulter le journal d\'audit',
        'audit.export' => 'Exporter le journal d\'audit',
        'setting.view' => 'Consulter les paramètres',
        'setting.edit' => 'Modifier les paramètres',
        'setting.edit_engine' => 'Modifier les paramètres de calcul',
        'fee.view' => 'Consulter les frais',
        'fee.collect' => 'Encaisser les paiements',
        'fee.void' => 'Annuler les paiements',
        'ledger.view' => 'Consulter le grand livre',
        'ledger.post' => 'Enregistrer une écriture',
        'backup.run' => 'Lancer une sauvegarde',
        'backup.restore' => 'Restaurer une sauvegarde',
        'licence.manage' => 'Gérer la licence',
    ],
];
```

**NEEDS VERIFICATION:** these French role titles follow common Cameroonian secondary-school usage, but *Secrétaire Général*, *Économe* and *Responsable Internat et Transport* should be confirmed against a real school's organigram before the pilot. Flag them; do not treat them as settled.

- [ ] **Step 4: Write the localisation test**

Create `tests/Feature/LocalisationTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\Role;

it('has an English and a French label for every role', function () {
    foreach (Role::cases() as $role) {
        expect($role->label('en'))->not->toContain('opes.roles.');
        expect($role->label('fr'))->not->toContain('opes.roles.');
    }
});

it('has an English and a French label for every permission', function () {
    foreach (Permission::cases() as $permission) {
        expect($permission->label('en'))->not->toContain('opes.permissions.');
        expect($permission->label('fr'))->not->toContain('opes.permissions.');
    }
});

it('uses the Cameroonian titles rather than literal translations', function () {
    expect(Role::Principal->label('fr'))->toBe('Proviseur');
    expect(Role::VicePrincipal->label('fr'))->toBe('Censeur');
    expect(Role::ClassMaster->label('fr'))->toBe('Professeur Principal');
    expect(Role::DisciplineMaster->label('fr'))->toBe('Surveillant Général');
});

it('keeps the two language files structurally identical', function () {
    $en = require lang_path('en/opes.php');
    $fr = require lang_path('fr/opes.php');

    // A key present in one file and missing from the other surfaces in the UI
    // as a raw translation key, which is how half-translated products ship.
    expect(array_keys($en['roles']))->toBe(array_keys($fr['roles']));
    expect(array_keys($en['permissions']))->toBe(array_keys($fr['permissions']));
});
```

- [ ] **Step 5: Run the whole suite**

```powershell
php artisan test
composer analyse
```

Every test from Task 2 onward must now be green, including the two role-label tests that were failing.

- [ ] **Step 6: Commit**

```powershell
git add database/seeders lang tests/Feature/Identity/AuthorizationMatrixTest.php tests/Feature/LocalisationTest.php
git commit -m "feat: seed roles and permissions, add EN/FR strings with Cameroonian titles"
```

---

## Task 9: Admin-driven password reset and the promote-admin escape hatch

`00-core` §9.3: no admin recovery path may depend on a channel the school does not have.

**Files:**
- Create: `app/Modules/Identity/Actions/SetUserPassword.php`
- Create: `app/Modules/Identity/Console/PromoteAdminCommand.php`
- Test: `tests/Feature/Identity/PasswordResetTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Identity/PasswordResetTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Actions\SetUserPassword;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(Role::Administrator->value);

    $this->target = User::factory()->create(['name' => 'Target User']);
});

it('sets a password without needing an email server', function () {
    app(SetUserPassword::class)->handle($this->target, 'NewPassw0rd!', $this->admin);

    expect(Hash::check('NewPassw0rd!', $this->target->fresh()?->password ?? ''))->toBeTrue();
});

it('hashes with argon2id', function () {
    app(SetUserPassword::class)->handle($this->target, 'NewPassw0rd!', $this->admin);

    expect($this->target->fresh()?->password)->toStartWith('$argon2id$');
});

it('forces a change on next login', function () {
    app(SetUserPassword::class)->handle($this->target, 'NewPassw0rd!', $this->admin);

    expect($this->target->fresh()?->must_change_password_at)->not->toBeNull();
});

it('audits the reset without recording either password', function () {
    app(SetUserPassword::class)->handle($this->target, 'NewPassw0rd!', $this->admin);

    $entry = AuditLog::query()->where('action', 'password_set')->firstOrFail();

    expect($entry->actor_name_at_time)->toBe($this->admin->name);

    $blob = json_encode($entry->getAttributes(), JSON_THROW_ON_ERROR);
    expect($blob)->not->toContain('NewPassw0rd!');
});

it('refuses when the actor lacks the permission', function () {
    $nobody = User::factory()->create();
    $nobody->assignRole(Role::Teacher->value);

    app(SetUserPassword::class)->handle($this->target, 'NewPassw0rd!', $nobody);
})->throws(\Illuminate\Auth\Access\AuthorizationException::class);

it('promotes a user to administrator from the command line', function () {
    $this->artisan('opes:promote-admin', ['email' => $this->target->email])
        ->assertSuccessful();

    expect($this->target->fresh()?->hasRole(Role::Administrator->value))->toBeTrue();
});

it('fails clearly when the email is unknown', function () {
    $this->artisan('opes:promote-admin', ['email' => 'nobody@example.test'])
        ->assertFailed();
});
```

- [ ] **Step 2: Run and verify it fails**

```powershell
php artisan test --filter=PasswordResetTest
```

- [ ] **Step 3: Write `SetUserPassword`**

Create `app/Modules/Identity/Actions/SetUserPassword.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Admin-driven password reset (docs/specs/00-core.md 9.3).
 *
 * The PRIMARY reset path, not a fallback: most Cameroonian schools have no
 * SMTP server, so a token-emailed-to-the-user flow would strand them.
 */
final class SetUserPassword
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(User $target, string $plainPassword, User $actor): void
    {
        if (! $actor->can(Permission::UserSetPassword->value)) {
            throw new AuthorizationException(
                'You do not have permission to set another user\'s password.'
            );
        }

        DB::transaction(function () use ($target, $plainPassword, $actor): void {
            $target->password = $plainPassword; // 'hashed' cast applies argon2id
            $target->must_change_password_at = now();
            $target->save();

            // Neither the old nor the new password appears in before/after.
            // An audit log that records credentials is a credential store.
            $this->audit->handle(
                action: AuditAction::PasswordSet,
                module: 'Identity',
                auditableType: User::class,
                auditableId: (int) $target->getKey(),
                before: null,
                after: ['forced_change' => true],
                actor: $actor,
            );
        });
    }
}
```

- [ ] **Step 4: Write `PromoteAdminCommand`**

Create `app/Modules/Identity/Console/PromoteAdminCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Console\Command;

/**
 * Vendor escape hatch (docs/specs/00-core.md 9.3). Requires server access,
 * which is the point: it is the last resort when even the recovery credential
 * is gone.
 */
final class PromoteAdminCommand extends Command
{
    protected $signature = 'opes:promote-admin {email}';

    protected $description = 'Grant the Administrator role to a user by email. Requires server access.';

    public function handle(WriteAuditEntry $audit): int
    {
        $email = (string) $this->argument('email');

        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No user found with email [{$email}].");

            return self::FAILURE;
        }

        $user->assignRole(Role::Administrator->value);

        $audit->handle(
            action: AuditAction::RoleAssigned,
            module: 'Identity',
            auditableType: User::class,
            auditableId: (int) $user->getKey(),
            after: ['role' => Role::Administrator->value, 'via' => 'opes:promote-admin'],
            actor: $user,
        );

        $this->info("Granted Administrator to [{$email}].");
        $this->line('This action was audited.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Verify and commit**

```powershell
php artisan test
composer analyse
git add app/Modules/Identity tests/Feature/Identity/PasswordResetTest.php
git commit -m "feat: add admin-driven password reset and opes:promote-admin escape hatch"
```

---

## Task 10: Architecture test for audit integrity, and documentation

**Files:**
- Create: `tests/Architecture/AuditIntegrityTest.php`
- Modify: `docs/DEVELOPMENT.md`, `README.md`

- [ ] **Step 1: Write the architecture test**

Create `tests/Architecture/AuditIntegrityTest.php`:

```php
<?php

declare(strict_types=1);

// 00-core 14: audit rows are written by exactly one Action. If any other class
// can insert into audit_logs, the hash chain has an unserialised writer and can
// fork - at which point verification is meaningless.

it('has exactly one writer of audit rows', function () {
    $root = dirname(__DIR__, 2);
    $appDir = $root.DIRECTORY_SEPARATOR.'app';

    $offenders = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appDir));

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();

        // The Action itself and the model are allowed to touch the table.
        if (str_contains($path, 'WriteAuditEntry.php') || str_contains($path, 'AuditLog.php')) {
            continue;
        }

        $source = (string) file_get_contents($path);

        if (preg_match('/AuditLog::query\(\)\s*->\s*(create|insert)/', $source) === 1) {
            $offenders[] = $path;
        }

        if (preg_match('/new\s+AuditLog\s*\(/', $source) === 1) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([], 'Only WriteAuditEntry may create audit rows: '.implode(', ', $offenders));
});

it('never lets a migration cascade a delete into the audit log', function () {
    $migrations = glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [];

    foreach ($migrations as $migration) {
        $source = (string) file_get_contents($migration);

        if (! str_contains($source, 'audit_logs')) {
            continue;
        }

        expect($source)->not->toContain('cascadeOnDelete');
        expect($source)->toContain('restrictOnDelete');
    }
});
```

- [ ] **Step 2: Run it**

```powershell
php artisan test --testsuite=Architecture
```

- [ ] **Step 3: Extend `docs/DEVELOPMENT.md`**

Append:

```markdown
## Identity, audit and settings

- **Roles and permissions are enums**, not strings: `App\Modules\Identity\Domain\Role`
  and `Permission`. A typo is an analysis error, not a silent access denial.
  Later phases ADD permission cases; they must never rename existing ones,
  because seeds and granted permissions reference the values.
- **Audit rows have exactly one writer**, `WriteAuditEntry`. It serialises on
  the tail of the chain under a row lock — two concurrent writers reading the
  same predecessor would fork the chain and make verification meaningless. An
  architecture test enforces the single-writer rule.
- **The audit log is hash-chained.** `opes:audit:verify` runs nightly and
  detects both tampering and excision. If it fails, the table was modified
  outside the application.
- **Never log a credential.** Passwords and recovery codes must not appear in
  `before`/`after`. Tests assert this explicitly.
- **Settings have three classes.** Engine-behaviour settings are lockable:
  once a period using them is published, they cannot change, because doing so
  would retroactively alter numbers already printed and handed to parents.
- **Password reset is admin-driven.** Most Cameroonian schools have no SMTP
  server, so no admin recovery path may depend on email.
```

- [ ] **Step 4: Update `README.md` status section**

```markdown
## Status

Phase 0A — foundation and kernel. Complete.
Phase 0B — identity, audit, settings and i18n. Complete.

Delivered: modular skeleton, `opes:preflight`, the `Money` / `Rate` / `Score` /
`BusinessDate` value objects, users with 20 seeded roles and granular
permissions, a hash-chained append-only audit log with nightly verification, a
break-glass recovery credential, a typed settings registry with lockable
engine-behaviour settings, and bilingual EN/FR strings.

Next: Phase 0C — installer, TLS, backup and verified restore drill, health
page, log rotation.
```

- [ ] **Step 5: Final verification and tag**

```powershell
php artisan opes:preflight
php artisan opes:audit:verify
composer check
git add tests/Architecture/AuditIntegrityTest.php docs/DEVELOPMENT.md README.md
git commit -m "test: enforce single-writer audit rule; document 0B"
git tag -a phase-0b -m "Phase 0B: identity, audit, settings and i18n complete"
```

---

## Definition of done

- [ ] `composer check` green: PHPStan level 8 clean, all tests pass
- [ ] `php artisan opes:preflight` passes
- [ ] `php artisan opes:audit:verify` reports an intact chain
- [ ] Tampering with a row via raw SQL makes verification fail **and names the row**
- [ ] Deleting a row via raw SQL makes verification fail
- [ ] `AuditLog` refuses `update()` and `delete()` from the application
- [ ] A recovery code never appears in the audit log; neither does a password
- [ ] Generating a second recovery credential revokes the first
- [ ] A used recovery credential cannot be reused
- [ ] Portal roles hold **zero** operational permissions
- [ ] A locked engine-behaviour setting cannot be changed; a cosmetic one still can
- [ ] `lang/en/opes.php` and `lang/fr/opes.php` have identical key structures
- [ ] Architecture test rejects a second writer of audit rows
- [ ] Tagged `phase-0b`

---

## Self-review notes

**Spec coverage.** Implements `00-core` §9.1 (roles and permissions), §9.3 (recovery credential, admin-driven reset, promote-admin), §9.4 (Argon2id), §10.5 (users never deleted, `suspended` status, RESTRICT actor FKs), §14 (hash-chained audit, indexes, single writer), §18 (EN/FR); `09-ui` §7.3 (settings registry, three classes, engine-behaviour lock).

**Deferred with reasons, not silently.** The `GuardianScopeMatrix` needs `Student`/`Enrollment` and belongs in Phase 2 where `07-students` §7.5 specifies it — building a guardian permission model here would create a second, contradictory source of truth for the highest-risk boundary in the product. TOTP 2FA is roadmap. The recovery *sheet* needs the 0C installer; the credential itself is built here. `APP_KEY` canary decrypt and blind indexes need encrypted columns, which arrive with the first module that has them (Phase 2 students) — noted so they are not forgotten.

**Ordering note.** Task 2's two bilingual-label tests fail until Task 8 adds `lang/`. This is called out in the task rather than hidden, and is the honest consequence of writing the enum before its translations. The alternative — stubbing translations in Task 2 — would mean writing the same strings twice.

**Type consistency check.** `Role::defaultPermissions(): list<Permission>` is consumed by `RolePermissionSeeder` via `->value`; `AuditAction` cases match the strings asserted in tests (`recovery_generated`, `recovery_used`, `setting_changed`, `password_set`); `WriteAuditEntry::handle()`'s named arguments match every call site; `ReadSetting::cacheKey()` is used by both Actions with the same argument order.

**NEEDS VERIFICATION carried forward:** the French role titles (*Secrétaire Général*, *Économe*, *Responsable Internat et Transport*) follow common Cameroonian usage but should be confirmed against a real school organigram before the pilot.
