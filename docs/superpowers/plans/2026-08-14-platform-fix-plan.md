# OPES Platform Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the seven confirmed crashes, stop issued report cards becoming permanently unprintable, repaint every KPI screen from one component change, give the school its missing identity/settings/account screens, and link the 17 built-but-unreachable screens — in that order of value.

**Architecture:** Fix by **root cause, not by symptom**. Six of the seven crashes collapse into two one-line-class fixes (`whereKey()` on a `DB::table` builder; `PdfExport::download()` not sanitising). The report-card bug is the same bug class already fixed for receipts in `6f02f00`, one layer up: `RenderDocument` freezes the *payload* but re-derives the *render envelope* (school chrome + subject label) live on every reprint, and both land in the hashed bytes. The visual work stays at the component layer — `kpi-card.blade.php` alone repaints 42 screens — and only descends to screens where no component can reach.

**Tech Stack:** Laravel 13, Livewire 4, Pest + PHPUnit on real MySQL with `RefreshDatabase`, PHPStan level 8 (larastan), Tailwind v4, Blade components, DomPDF.

---

## Why this order

| # | Phase | Why it outranks the next one |
|---|---|---|
| 0 | Baseline + two red architecture tests | `tests/Architecture/ModuleBoundaryTest.php` is **failing on `main` right now** — 259 of 261 Unit+Architecture tests pass, and both failures are real and deterministic. One is a **regression** introduced by `09f9ee4` (HR importing `Identity\Models\User`), the other older code exposed when `b671525` made the rule absolute. A red architecture test makes every later "the suite is green" claim in this plan unreadable, and it costs one session to clear. |
| 1 | Seven crashes | A 500 is the only defect class where the operator cannot proceed at all. Three procurement flows and three Export-PDF buttons fail for **every record, always** — not on an edge case. Two fixes close six of them, and the `PdfExport` fix additionally closes six *latent* call sites that will start crashing the day `supplier_invoices` gets a row or a staff name contains a slash. |
| 2 | Report-card reprint | The only defect that causes **permanent, irreversible loss** of a statutory record. Two real documents are already stranded at 422 forever, and a second vector (editing the school profile) arms the same trap for every report card in the database on the first settings edit — which is exactly what Phase 4 asks an administrator to do. Phase 2 must land **before** Phase 4 or Phase 4 detonates it. |
| 3 | KPI `icon-bg` → `tone` | Highest visual leverage in the product per line changed: 42 screens, 39 of them still on the legacy escape hatch, all repainted from one `match()` arm. Nothing else in the plan has that ratio. |
| 4 | `school_document_profiles` | 0 rows, no UI writes it, and `RenderDocument` reads it for **every** printed document — which is why every document renders bare. Blocked behind Phase 2 (see above). |
| 5 | Account screen + settings hub | A teacher cannot change their own password. `/settings` links to none of the settings screens that exist and the header gear is inert. This is where an evaluator goes first. |
| 6 | 17 orphans | Built, gated correctly, linked from nowhere — including `/marks`, described in `routes/web.php:305` as the highest-traffic academic screen, and the entire procure-to-pay chain. Cheap to fix, large surface recovered. |
| 7 | Component visual | The "empty *and* cramped at the same time" complaint, in one component: a rail that is 83–91% blank squeezing the table to 862px. Plus the 375px KPI clip. |
| 8 | Screen long tail | Real, but each fix helps exactly one screen. |

---

## Environment and constraints — read before Task 1

**PHP is not on PATH.** Every command in this plan uses:

```bash
PHP='C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe'
```

Commands are written for the **Bash tool** (Git Bash). The PowerShell equivalent of an env-prefixed command is:

```powershell
$env:DB_DATABASE='opeschool_scratch_p1'; & 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' artisan test tests/Feature/...
```

**Scratch database per phase.** `phpunit.xml` sets `DB_DATABASE=opeschool_test` without `force="true"`, so an env var set on the command line wins. **Never run two suites against the same database at once** — a second `migrate:fresh` mid-run drops the first run's schema. Each phase below names its own scratch DB (`opeschool_scratch_p1` … `opeschool_scratch_p8`). Create it once:

```bash
"C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe" -uroot -e "CREATE DATABASE IF NOT EXISTS opeschool_scratch_p1"
```

**PHPStan level 8, ZERO `ignoreErrors`, ZERO baseline, ever.** `phpstan.neon` excludes only `tests/Architecture` (Pest's magic-method DSL) and that exclusion is not a precedent. If a change cannot pass level 8, the change is wrong.

**`git add` exact paths only.** Never `git add -A` and never `git add .` — `mobile/` holds ~50 untracked screenshots and CSVs that must not be committed. Every commit step below lists its paths explicitly.

**Verify UI changes by looking.** Measurements and computed styles pass while a page looks wrong (the login-icon bug was invisible to CSS inspection and obvious in a screenshot). Always `resize_window` to 1440×900 **and** 375×812 before screenshotting, and look at the image.

**Hard-won gotchas from the restyle pass (`docs/superpowers/plans/2026-08-13-ui-restyle-brief.md`):**
- The **root font-size is 17px**. Tailwind's spacing names lie: `w-72` = 306px, `w-56` = 238px. Measure, never read the name.
- **A treatment that must beat per-screen utilities goes UNLAYERED in `resources/css/app.css`.** Tailwind v4 compiles utilities into `@layer utilities`, and unlayered CSS outranks every layered rule regardless of specificity. A `@layer components` version ships as a no-op that measures fine. Scope to `.opes-app`; put exclusions inside `:where()` so source order decides.
- **Tailwind v4 scans Blade literally.** Build class strings as complete literals in `match()` arms, never by concatenation.
- Keep `icon-bg`-style escape hatches working so existing callers never break.

---

## File structure

**Created**

| Path | Responsibility |
|---|---|
| `database/migrations/2026_08_14_330001_add_render_envelope_to_issued_documents.php` | The `render_envelope` JSON column: the chrome + subject label as at issue. |
| `app/Console/Commands/RepairDocumentEnvelope.php` | One-shot recovery of documents already stranded at 422. |
| `app/Modules/Identity/Actions/FindUserIdByEmail.php` | Identity's door for "which user has this address", returning an ID so no module has to hold a `User`. Closes two boundary breaches. |
| `app/Modules/SchoolProfile/Actions/SaveDocumentProfile.php` | The only writer of `school_document_profiles`. Validation + audit. |
| `app/Modules/SchoolProfile/Livewire/DocumentProfile.php` | `/settings/school-identity` — logo, crest, address, ministry headers, signatures. |
| `resources/views/livewire/schoolprofile/document-profile.blade.php` | Its view. |
| `resources/views/livewire/schoolprofile/settings-hub.blade.php` | `/settings` becomes a hub that links the settings screens that exist. |
| `app/Modules/Identity/Livewire/Account.php` | `/account` — own profile + own password, staff shell. |
| `resources/views/livewire/identity/account.blade.php` | Its view. |
| `resources/views/components/module-subnav.blade.php` | One sub-nav component; Procurement and Ledger both mount it. |
| `resources/views/components/btn.blade.php` | One button, four variants. Kills the amber/red/pale-outline one-offs. |
| `tests/Feature/Assessment/AssessmentTestHelpers.php` | `assessmentFixture()` / `assessmentPublisher()` / `assessmentTruncateAll()`, extracted so more than one file can build a published report card. |
| `tests/Feature/Procurement/LivewireUpdatedHandlerTest.php` | The three `whereKey` crashes. |
| `tests/Feature/Reporting/ExportFilenameTest.php` | The filename family, all nine call sites. |
| `tests/Feature/Assessment/ReportCardReprintTest.php` | The rename-strands-the-bulletin bug. |
| `tests/Feature/Ui/KpiToneTest.php` | The `icon-bg` → `tone` map. |
| `tests/Feature/SchoolProfile/DocumentProfileTest.php` | The document-profile screen. |
| `tests/Feature/Identity/AccountScreenTest.php` | `/account`. |
| `tests/Feature/Shell/ReachabilityTest.php` | Every previously-orphaned screen now has an inbound link. |

**Modified**

| Path | Change |
|---|---|
| `app/Modules/Identity/Actions/ProvisionPortalUser.php` | Optional authorising `Actor` (enforces `user.manage` on Identity's side) and a `mustChangePassword` flag. |
| `app/Modules/HR/Actions/GrantStaffPortalAccess.php` · `app/Modules/HR/Livewire/Index.php` | Takes `Actor`, returns `user_id`; no reference to Identity's model. |
| `app/Modules/Communication/Livewire/Messages/Index.php` · `Outbox/Index.php` · `Templates/Index.php` | Reach Identity through Actions; drop the `/** @var User */` casts. |
| `app/Modules/Procurement/Livewire/PurchaseOrders/Index.php:91` · `GoodsReceipts/Index.php:91` · `SupplierInvoices/Index.php:148` | `whereKey()` → `where('id', …)`. |
| `app/Modules/Reporting/Support/PdfExport.php:22-37` | Sanitise `$filename` through `DocumentFileName::sanitize()`. |
| `app/Modules/Accounting/Livewire/JournalEntries/Form.php:187` | Guard the out-of-range line index. |
| `app/Modules/Accounting/Livewire/Budgets/Index.php` | `saveLine()` refuses instead of 500-ing. |
| `app/Modules/Reporting/Actions/RenderDocument.php` | Freeze and read back the render envelope. |
| `app/Modules/Reporting/Models/IssuedDocument.php` | `render_envelope` column: fillable, cast, one-way-backfill guard. |
| `resources/views/components/kpi-card.blade.php` | Derive `tone` from legacy `icon-bg`; fixed label box; strip guards. |
| `resources/views/components/list-screen.blade.php` | Sticky rail, mobile KPI strip, wrapping tabs. |
| `resources/views/components/status-pill.blade.php` | `whitespace-nowrap`. |
| `resources/views/layouts/app.blade.php:255-315` | Account link in the avatar menu; the gear becomes a real link. |
| `app/Modules/Identity/Support/Navigation.php` | `marks`, `year_end`, `discipline`, `verify` items. |
| `routes/web.php` | `/account`, `/settings/school-identity`. |
| `lang/en/opes.php` · `lang/fr/opes.php` | Every new string, both files. |

---

# Phase 0 — Baseline, and the two red architecture tests

### Task 1: Prove the toolchain runs before changing anything

The audit could not finish the suite (see the appendix — this is **recorded, not scheduled**). Before trusting any failure this plan produces, prove that a small, known-good slice runs green on a quiet machine and a private database.

**Files:**
- Read only: `phpunit.xml`, `phpstan.neon`

- [ ] **Step 1: Create the scratch database**

```bash
"C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe" -uroot -e "CREATE DATABASE IF NOT EXISTS opeschool_scratch_p1"
```

Expected: no output, exit 0.

- [ ] **Step 2: Run one small existing suite against it**

```bash
PHP='C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe'
DB_DATABASE=opeschool_scratch_p1 "$PHP" artisan test tests/Feature/Reporting/DocumentPayloadSnapshotTest.php
```

Expected: `Tests:  4 passed`. If it hangs past ~10 minutes, stop — nothing else in this plan is trustworthy until that is understood, and the appendix says where to look.

- [ ] **Step 3: Run the static analyser and record the number**

```bash
"$PHP" vendor/bin/phpstan analyse --memory-limit=1G
```

Expected: `[OK] No errors`. Any pre-existing error must be recorded here before Task 4, so that a new error is attributable.

- [ ] **Step 4: No commit**

Nothing changed. Do not commit.

---

### Task 2: HR imports Identity's `User` model — a red architecture test on `main`

**This is a regression, not legacy debt.** `tests/Architecture/ModuleBoundaryTest.php` (00-core §6.2 rule 2: *cross-module access goes only through the owning module's Actions or published Events, never its Models*) was green; commit `09f9ee4` introduced `use App\Modules\Identity\Models\User;` at `app/Modules/HR/Actions/GrantStaffPortalAccess.php:10` and turned it red. Every sibling HR Action — `ApproveLeave`, `ValidateTimesheet`, `ComputeTerminationSettlement`, `RequestLeave`, `AccrueMonthlyLeave`, `SeedTeachingHoursFromTimetable` — takes `App\Support\Audit\Actor $actor`, and three carry the explicit comment *"No textual reference to the Identity User model crosses this module."* The pattern was established; this missed it. It is placed here, in Phase 0, because a red architecture test makes every other "the suite is green" claim in this plan unreadable.

**The design, argued.** `GrantStaffPortalAccess` needs a `User` for three reasons, and each one has a different right answer:

1. **It hands `$actor` to `CreateUser::handle(…, User $actor)`.** Three options were weighed:
   - *Change `CreateUser` to take an `Actor`.* `CreateUser` calls `$actor->can(Permission::UserManage->value)` and `$actor->toAuditActor()`; `Support\Audit\Actor` is a value object with neither, so Identity would have to re-resolve the model from the actor's id anyway — and `CreateUser` has other callers (`/users/create`, seeders, tests) that would all move for one caller's benefit. **Rejected: largest blast radius, no gain.**
   - *Write a new bespoke Identity door.* **Rejected: one already exists.**
   - **Chosen: route through `Identity\Actions\ProvisionPortalUser`,** which was built for precisely this and says so — *"Guardians\Actions\ActivatePortalAccount calls this instead of touching the User model, which tests/Architecture/ModuleBoundaryTest.php forbids it to import."* `Role::StaffPortal` satisfies its `isPortal()` ceiling. The one gap is that `ProvisionPortalUser` takes **no permission gate** (its authority is a verified invitation code), whereas `CreateUser` enforced `user.manage` on the actor. **Dropping an authorisation check to satisfy an architecture rule would be the wrong trade**, so add an *optional* `?Actor $authorisedBy = null` and have Identity resolve the user and enforce `user.manage` **on its own side of the boundary**. Guardians' existing call passes nothing and keeps today's behaviour exactly.
2. **It pre-checks `User::query()->where('email', …)->exists()`.** Add one narrow Identity door, `FindUserIdByEmail` — which also closes one of the three Communication violations in Task 3. One class, two boundary breaches.
3. **It `forceFill(['must_change_password_at' => now()])` after creation.** Fold into `ProvisionPortalUser` as a `bool $mustChangePassword = false` parameter, so HR never touches the row.

`ProvisionPortalUser` keeps returning `User` — Identity returning its own model is correct. HR reads `(int) $user->getKey()` and **never names the type**, so no textual reference crosses. HR's own `@return` docblock changes to `array{user_id: int, temporary_password: string}` for the same reason.

**Files:**
- Modify: `app/Modules/Identity/Actions/ProvisionPortalUser.php`
- Create: `app/Modules/Identity/Actions/FindUserIdByEmail.php`
- Modify: `app/Modules/HR/Actions/GrantStaffPortalAccess.php`
- Modify: `app/Modules/HR/Livewire/Index.php:377-412`
- Modify: `tests/Feature/HR/GrantStaffPortalAccessTest.php`

- [ ] **Step 1: Watch the architecture test fail**

```bash
PHP='C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe'
DB_DATABASE=opeschool_scratch_p1 "$PHP" artisan test --testsuite=Architecture
```

Expected: FAIL, naming `App\Modules\HR` → `App\Modules\Identity\Models` and `App\Modules\Communication` → `App\Modules\Identity\Models`. Record the exact message; Task 3 clears the second.

- [ ] **Step 2: Add the `FindUserIdByEmail` door**

Create `app/Modules/Identity/Actions/FindUserIdByEmail.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;

/**
 * Identity's door for "is there a user with this address, and which one".
 *
 * It returns an ID, not a model, on purpose: 00-core §6.2 rule 2 forbids
 * another module importing `Identity\Models\User`, and handing one back
 * across the boundary would smuggle the same dependency out through the
 * return type. Every caller outside Identity works in user IDs already -
 * Communication's StartThread takes `list<int> $participantUserIds`, HR
 * stores `staff_members.portal_user_id` - so an ID is not a downgrade, it is
 * what they wanted.
 */
final class FindUserIdByEmail
{
    public function handle(string $email): ?int
    {
        $id = User::query()->where('email', trim($email))->value('id');

        return $id === null ? null : (int) $id;
    }
}
```

- [ ] **Step 3: Let `ProvisionPortalUser` carry the authority and the forced change**

In `app/Modules/Identity/Actions/ProvisionPortalUser.php`, replace the `handle()` signature and add the gate. Keep every existing line of the transaction body unchanged except the `forceFill`:

```php
    /**
     * @param  Actor|null  $authorisedBy  When supplied, this provisioning is
     *   an ADMIN-MEDIATED grant rather than a self-activation, and the actor
     *   must hold `user.manage` - the same authority CreateUser demands. The
     *   check lives here, on Identity's side of the module boundary, so a
     *   calling module never has to hold a User to prove it may create one
     *   (00-core §6.2 rule 2). Null keeps the invitation-code path exactly as
     *   it was: its authority is the code it already verified, and no session
     *   exists yet to gate on.
     */
    public function handle(
        string $name,
        string $email,
        Role $role,
        string $plainPassword,
        ?Actor $authorisedBy = null,
        bool $mustChangePassword = false,
    ): User {
        if (! $role->isPortal()) {
            throw new InvalidArgumentException(
                'ProvisionPortalUser only provisions portal roles; use CreateUser for operational accounts.',
            );
        }

        if ($authorisedBy !== null) {
            $authoriser = $authorisedBy->id === null
                ? null
                : User::query()->find($authorisedBy->id);

            if ($authoriser === null || ! $authoriser->can(Permission::UserManage->value)) {
                throw new AuthorizationException('You do not have permission to create users.');
            }
        }
```

and inside the transaction, after `$user->assignRole($spatieRole);`:

```php
            if ($mustChangePassword) {
                // The admin hands over a temporary password face to face
                // (this platform sends no email), so the account must force a
                // change at first sign-in. HR used to reach in and forceFill
                // this itself, which is the row-level access the boundary
                // rule exists to stop.
                $user->forceFill(['must_change_password_at' => now()])->save();
            }
```

Add `use Illuminate\Auth\Access\AuthorizationException;` to the imports. Update the closure's `use (...)` list to include `$mustChangePassword`.

- [ ] **Step 4: Move `GrantStaffPortalAccess` onto the `Actor` contract**

In `app/Modules/HR/Actions/GrantStaffPortalAccess.php`: delete `use App\Modules\Identity\Actions\CreateUser;` and `use App\Modules\Identity\Models\User;`; add `use App\Modules\Identity\Actions\FindUserIdByEmail;`, `use App\Modules\Identity\Actions\ProvisionPortalUser;` and `use App\Support\Audit\Actor;`. Then:

```php
    public function __construct(
        private readonly ProvisionPortalUser $provisionPortalUser,
        private readonly FindUserIdByEmail $findUserIdByEmail,
    ) {}

    /**
     * No textual reference to the Identity User model crosses this module -
     * the same rule every sibling HR Action states, and the one this file
     * broke. The actor is an `App\Support\Audit\Actor`, exactly as
     * ApproveLeave, ValidateTimesheet and ComputeTerminationSettlement take
     * one, and the return carries an ID rather than a model for the same
     * reason (00-core §6.2 rule 2).
     *
     * @return array{user_id: int, temporary_password: string}
     */
    public function handle(int $staffMemberId, ?string $email, Actor $actor): array
    {
```

Replace the duplicate-email pre-check:

```php
        if ($this->findUserIdByEmail->handle($resolvedEmail) !== null) {
            throw ValidationException::withMessages([
                'email' => "A user with email {$resolvedEmail} already exists.",
            ]);
        }
```

And the transaction body:

```php
        return DB::transaction(function () use ($staff, $resolvedEmail, $password, $actor): array {
            $user = $this->provisionPortalUser->handle(
                trim($staff->first_name.' '.$staff->last_name),
                $resolvedEmail,
                Role::StaffPortal,
                $password,
                $actor,
                mustChangePassword: true,
            );

            $userId = (int) $user->getKey();

            DB::table('staff_members')->where('id', $staff->id)->update([
                'portal_user_id' => $userId,
                'updated_at' => now(),
            ]);

            return ['user_id' => $userId, 'temporary_password' => $password];
        });
```

- [ ] **Step 5: Update the one caller**

In `app/Modules/HR/Livewire/Index.php`, replace lines 385-389:

```php
        /** @var \App\Modules\Identity\Models\User $actorUser */
        $actorUser = auth()->user();

        try {
            $result = $grant->handle($this->portalAccessStaffId, $this->portalAccessEmail === '' ? null : $this->portalAccessEmail, $actorUser);
```

with:

```php
        // Also an Identity Models reference, fully-qualified rather than
        // imported - which the arch test's `use`-statement check does not
        // catch but 00-core §6.2 rule 2 forbids just the same.
        $actor = auth()->user()?->toAuditActor();

        if ($actor === null) {
            abort(403);
        }

        try {
            $result = $grant->handle($this->portalAccessStaffId, $this->portalAccessEmail === '' ? null : $this->portalAccessEmail, $actor);
```

Then follow `$result` through the rest of `grantPortalAccess()` and replace any `$result['user']->…` with `$result['user_id']`:

```bash
grep -n "result\[" app/Modules/HR/Livewire/Index.php
```

- [ ] **Step 6: Update the four existing feature tests to the new contract**

In `tests/Feature/HR/GrantStaffPortalAccessTest.php`, change each `->handle((int) $staffMember->id, …, $admin)` to pass `$admin->toAuditActor()`, and change any assertion reading `$result['user']` to read `$result['user_id']`. **Do not weaken an assertion** — if one asserted on the created user's attributes, re-read it with `DB::table('users')->where('id', $result['user_id'])`.

- [ ] **Step 7: Run the tests**

```bash
DB_DATABASE=opeschool_scratch_p1 "$PHP" artisan test tests/Feature/HR/GrantStaffPortalAccessTest.php tests/Feature/Guardians
```

Expected: the 4 HR tests pass, and the Guardians portal-activation tests still pass — `ProvisionPortalUser`'s new parameters are both optional and defaulted to today's behaviour.

- [ ] **Step 8: Static analysis**

```bash
"$PHP" vendor/bin/phpstan analyse --memory-limit=1G
```

Expected: `[OK] No errors`. **No `ignoreErrors`, no baseline** — if `$authoriser->can()` or `$user->getKey()` trips level 8, narrow the type properly.

- [ ] **Step 9: Commit**

```bash
git add app/Modules/Identity/Actions/ProvisionPortalUser.php app/Modules/Identity/Actions/FindUserIdByEmail.php app/Modules/HR/Actions/GrantStaffPortalAccess.php app/Modules/HR/Livewire/Index.php tests/Feature/HR/GrantStaffPortalAccessTest.php
git commit -m "fix(hr): stop HR importing Identity's User model, keeping the user.manage check on Identity's side"
```

---

### Task 3: Communication imports Identity's `User` model in three screens

Older than Task 2's regression, and exposed when `b671525` removed the last standing exception and made the boundary rule absolute. Three files, but only **two** distinct problems:

- `app/Modules/Communication/Livewire/Messages/Index.php:13,86` — a real query, `User::query()->where('email', …)->first()`, whose only use is `$recipient->getKey()`. `StartThread::handle()` already takes `list<int> $participantUserIds`, so `FindUserIdByEmail` (built in Task 2) is a drop-in and the model never needs to exist here.
- `app/Modules/Communication/Livewire/Outbox/Index.php:14,298` and `Templates/Index.php:12,289` — the import exists **only** to satisfy a `/** @var User $user */` docblock so PHPStan can see `toAuditActor()` on `auth()->user()`. `Reporting\Actions\RenderDocument::currentActor()` already solves this without any import (`auth()->user()?->toAuditActor()`), because Larastan resolves the configured auth model itself. Copy the in-repo pattern rather than inventing one.

**Files:**
- Modify: `app/Modules/Communication/Livewire/Messages/Index.php`
- Modify: `app/Modules/Communication/Livewire/Outbox/Index.php`
- Modify: `app/Modules/Communication/Livewire/Templates/Index.php`

- [ ] **Step 1: Confirm the test still names Communication**

```bash
DB_DATABASE=opeschool_scratch_p1 "$PHP" artisan test --testsuite=Architecture
```

Expected: 1 remaining failure, `App\Modules\Communication` → `App\Modules\Identity\Models` (Task 2 cleared the HR one).

- [ ] **Step 2: Replace the recipient lookup**

In `app/Modules/Communication/Livewire/Messages/Index.php`, delete `use App\Modules\Identity\Models\User;` and add `use App\Modules\Identity\Actions\FindUserIdByEmail;`. Replace:

```php
        $recipient = User::query()->where('email', trim($this->newRecipient))->first();

        if ($recipient === null) {
```

with:

```php
        // Identity's door, not Identity's model: StartThread already speaks
        // in user IDs, so nothing here ever needed the record (00-core §6.2
        // rule 2).
        $recipientId = app(FindUserIdByEmail::class)->handle($this->newRecipient);

        if ($recipientId === null) {
```

and in the `StartThread` call replace `[(int) $recipient->getKey()]` with `[$recipientId]`.

- [ ] **Step 3: Replace both `actor()` helpers**

In `app/Modules/Communication/Livewire/Outbox/Index.php` and `app/Modules/Communication/Livewire/Templates/Index.php`, delete `use App\Modules\Identity\Models\User;` and replace the method body:

```php
    private function actor(): Actor
    {
        // No import and no /** @var User */ cast: Larastan resolves
        // auth()->user() to the configured auth model on its own, which is
        // how Reporting\Actions\RenderDocument::currentActor() has always
        // done this. The docblock that used to sit here was the ONLY reason
        // this module named Identity's model at all.
        $actor = auth()->user()?->toAuditActor();

        if ($actor === null) {
            throw new AuthorizationException('This action requires an authenticated user.');
        }

        return $actor;
    }
```

Add `use Illuminate\Auth\Access\AuthorizationException;` to both.

- [ ] **Step 4: Run the architecture suite**

```bash
DB_DATABASE=opeschool_scratch_p1 "$PHP" artisan test --testsuite=Architecture
```

Expected: `Tests: … passed` with **zero** failures — the whole `tests/Architecture` suite green.

- [ ] **Step 5: Run the Unit suite and the Communication feature tests**

```bash
DB_DATABASE=opeschool_scratch_p1 "$PHP" artisan test --testsuite=Unit
DB_DATABASE=opeschool_scratch_p1 "$PHP" artisan test tests/Feature/Communication
```

Expected: `261 tests, 261 passed` across Unit + Architecture (up from 259/261), and Communication green.

- [ ] **Step 6: Static analysis**

```bash
"$PHP" vendor/bin/phpstan analyse --memory-limit=1G
```

Expected: `[OK] No errors`.

- [ ] **Step 7: Commit**

```bash
git add app/Modules/Communication/Livewire/Messages/Index.php app/Modules/Communication/Livewire/Outbox/Index.php app/Modules/Communication/Livewire/Templates/Index.php
git commit -m "fix(communication): reach Identity through its Actions instead of importing its User model"
```

---

# Phase 1 — The confirmed crashes

Scratch DB: `opeschool_scratch_p1`.

### Task 4: `whereKey()` on a `DB::table` builder — three crashes, one cause

`DB::table()` returns a **Query** builder, which has no `whereKey()`. Laravel's dynamic `where{Column}` magic silently rewrites the call to `where 'key' = ?`, so all three flows 500 with `Unknown column 'key' in 'where clause'`. A scan confirmed these three are the *complete* set — no other Eloquent-only builder method is called on a `DB::table` chain anywhere in `app/`.

**Files:**
- Modify: `app/Modules/Procurement/Livewire/PurchaseOrders/Index.php:91`
- Modify: `app/Modules/Procurement/Livewire/GoodsReceipts/Index.php:91`
- Modify: `app/Modules/Procurement/Livewire/SupplierInvoices/Index.php:148`
- Create: `tests/Feature/Procurement/LivewireUpdatedHandlerTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Procurement/LivewireUpdatedHandlerTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Procurement\Livewire\GoodsReceipts\Index as GoodsReceiptsIndex;
use App\Modules\Procurement\Livewire\PurchaseOrders\Index as PurchaseOrdersIndex;
use App\Modules\Procurement\Livewire\SupplierInvoices\Index as SupplierInvoicesIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Reporting/P13MoneyHelpers.php';

uses(RefreshDatabase::class);

/**
 * The three crashes in the 2026-08-13 bugs audit share ONE cause:
 * DB::table() returns a Query builder, which has no whereKey(); Laravel's
 * dynamic where{Column} magic rewrites it to `where 'key' = ?`. These tests
 * exercise the presentation layer the 279-file Action-layer suite never
 * touches, which is the band every one of the seven crashes lives in.
 */
function procurementSupplierId(): int
{
    return (int) DB::table('suppliers')->insertGetId([
        'code' => 'SUP001',
        'name' => 'Test Supplier',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function procurementPurchaseOrderId(int $supplierId): int
{
    return (int) DB::table('purchase_orders')->insertGetId([
        'po_no' => 'BC/2026/000001',
        'supplier_id' => $supplierId,
        'status' => 'approved',
        'version' => 1,
        'ordered_on' => '2026-03-01',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('opens the amend form on a purchase order instead of 500-ing', function (): void {
    $user = p13moneyUserAs(Role::Bursar, Role::Accountant);
    $poId = procurementPurchaseOrderId(procurementSupplierId());

    Livewire::actingAs($user)
        ->test(PurchaseOrdersIndex::class)
        ->call('startAmend', $poId)
        ->assertHasNoErrors();
});

it('fills the supplier when a purchase order is picked on a goods receipt', function (): void {
    $user = p13moneyUserAs(Role::Bursar, Role::Accountant);
    $supplierId = procurementSupplierId();
    $poId = procurementPurchaseOrderId($supplierId);

    Livewire::actingAs($user)
        ->test(GoodsReceiptsIndex::class)
        ->set('formPurchaseOrderId', $poId)
        ->assertSet('formSupplierId', $supplierId);
});

it('fills the supplier when an invoice is picked for a credit note', function (): void {
    $user = p13moneyUserAs(Role::Bursar, Role::Accountant);
    $supplierId = procurementSupplierId();

    $invoiceId = (int) DB::table('supplier_invoices')->insertGetId([
        'internal_no' => 'FF/2026/000001',
        'supplier_id' => $supplierId,
        'supplier_invoice_no' => 'INV-1',
        'invoice_date' => '2026-03-01',
        'status' => 'posted',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(SupplierInvoicesIndex::class)
        ->set('creditNoteInvoiceId', $invoiceId)
        ->assertSet('creditNoteSupplierId', $supplierId);
});
```

If a column above does not exist, read the table's migration under `database/migrations/` and add the missing NOT NULL columns to the insert — do not change the assertions.

- [ ] **Step 2: Run it and watch all three fail the same way**

```bash
PHP='C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe'
DB_DATABASE=opeschool_scratch_p1 "$PHP" artisan test tests/Feature/Procurement/LivewireUpdatedHandlerTest.php
```

Expected: 3 failures, each `SQLSTATE[42S22]: Column not found: 1054 Unknown column 'key' in 'where clause'`.

- [ ] **Step 3: Fix all three call sites**

In `app/Modules/Procurement/Livewire/PurchaseOrders/Index.php`, replace:

```php
        $po = DB::table('purchase_orders')->whereKey($purchaseOrderId)->first(['version']);
```

with:

```php
        // NOT whereKey(): DB::table() returns a Query builder, which has no
        // such method, so Laravel's dynamic where{Column} magic rewrites it to
        // `where 'key' = ?` and the click 500s. Eloquent builders have
        // whereKey(); query builders never do.
        $po = DB::table('purchase_orders')->where('id', $purchaseOrderId)->first(['version']);
```

In `app/Modules/Procurement/Livewire/GoodsReceipts/Index.php`, replace:

```php
        $po = DB::table('purchase_orders')->whereKey($this->formPurchaseOrderId)->first(['supplier_id']);
```

with:

```php
        $po = DB::table('purchase_orders')->where('id', $this->formPurchaseOrderId)->first(['supplier_id']);
```

In `app/Modules/Procurement/Livewire/SupplierInvoices/Index.php`, replace:

```php
        $invoice = DB::table('supplier_invoices')->whereKey($this->creditNoteInvoiceId)->first(['supplier_id']);
```

with:

```php
        $invoice = DB::table('supplier_invoices')->where('id', $this->creditNoteInvoiceId)->first(['supplier_id']);
```

- [ ] **Step 4: Run the test again**

```bash
DB_DATABASE=opeschool_scratch_p1 "$PHP" artisan test tests/Feature/Procurement/LivewireUpdatedHandlerTest.php
```

Expected: `Tests:  3 passed`.

- [ ] **Step 5: Prove the class is closed**

```bash
grep -rn "DB::table" app/ | grep -E "whereKey|whereHas|withCount|whereBelongsTo|withTrashed"
```

Expected: **no output**. Any hit is the same bug in a screen the audit did not reach; fix it in this task.

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Procurement/Livewire/PurchaseOrders/Index.php app/Modules/Procurement/Livewire/GoodsReceipts/Index.php app/Modules/Procurement/Livewire/SupplierInvoices/Index.php tests/Feature/Procurement/LivewireUpdatedHandlerTest.php
git commit -m "fix(procurement): stop three flows 500-ing on whereKey() against a query builder"
```

---

### Task 5: `PdfExport::download()` never sanitises — three crashes now, six latent

`PdfExport::download()` hands `$filename` straight to `Pdf::download()`, which builds a `Content-Disposition` header; Symfony refuses any filename containing `/`. Every house serial contains one **by design** — `AST/000001`, `LM/2026/00001`, `BC/2026/000001` — so those three buttons 500 for **every** record. The codebase already owns the fix: `DocumentFileName::sanitize()`, whose own docblock says *"the storage filename is derived from the serial … SANITISED, because serials contain `/` by design"*. Three call sites remembered to `str_replace('/', '-', …)` by hand; nine did not. **Sanitising inside `PdfExport` closes all nine at once**, including six that are latent only because their tables are empty or their identifiers happen to have no slash yet.

**Files:**
- Modify: `app/Modules/Reporting/Support/PdfExport.php:22-37`
- Create: `tests/Feature/Reporting/ExportFilenameTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Reporting/ExportFilenameTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Reporting\Support\PdfExport;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The unsanitised-filename family (bugs audit A.1). Every house serial
 * contains '/' by design, and Symfony's HeaderUtils refuses to put one in a
 * Content-Disposition header. Nine call sites interpolate an identifier
 * straight into $filename; three of them crash for every record today and
 * six are latent. Sanitising HERE closes all nine, and this test is what
 * stops the tenth caller reintroducing it.
 */
it('builds a downloadable response from a filename containing house slashes', function (): void {
    $response = PdfExport::download(
        'Asset card',
        ['Tag', 'Description'],
        [['AST/000001', 'Projector']],
        'asset-card-AST/000001.pdf',
    );

    expect($response->getStatusCode())->toBe(200);
    expect($response->headers->get('content-disposition'))
        ->toContain('asset-card-AST-000001.pdf');
});

it('keeps a filename that needed no sanitising byte-for-byte', function (): void {
    $response = PdfExport::download('Report', ['A'], [['1']], 'students-2026.pdf');

    expect($response->headers->get('content-disposition'))->toContain('students-2026.pdf');
});

it('never produces an empty filename', function (): void {
    $response = PdfExport::download('Report', ['A'], [['1']], '///.pdf');

    expect($response->headers->get('content-disposition'))->toContain('document');
});
```

- [ ] **Step 2: Run it and watch the first test throw**

```bash
DB_DATABASE=opeschool_scratch_p1 "$PHP" artisan test tests/Feature/Reporting/ExportFilenameTest.php
```

Expected: FAIL — `InvalidArgumentException: The filename and the fallback cannot contain the "/" and "\" characters.`

- [ ] **Step 3: Sanitise inside `PdfExport`**

Replace the body of `app/Modules/Reporting/Support/PdfExport.php` from the `use` block down:

```php
use App\Modules\Reporting\Domain\DocumentFileName;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared PDF export helper for every report screen. Every report's PDF
 * shares one Blade shell (resources/views/reports/pdf-shell.blade.php) so
 * headers, page numbers, and print margins look the same everywhere;
 * callers supply just the title and a table's headers/rows.
 */
final class PdfExport
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<int, list<mixed>>  $rows
     */
    public static function download(
        string $title,
        array $headers,
        iterable $rows,
        string $filename,
        string $orientation = 'portrait',
    ): Response {
        $pdf = Pdf::loadView('reports.pdf-shell', [
            'title' => $title,
            'headers' => $headers,
            'rows' => $rows,
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->setPaper('a4', $orientation);

        // Sanitised HERE, not at each of the 32 call sites. Every house
        // identifier carries '/' BY DESIGN - AST/000001, LM/2026/00001,
        // BC/2026/000001, HA/2026/RCPT/000123 - and Symfony's HeaderUtils
        // refuses to build a Content-Disposition header containing one, so
        // the export button 500s for every record. Three callers remembered
        // to str_replace it by hand and nine did not; a helper that leaves
        // this to the caller is a helper that will be got wrong again.
        // DocumentFileName::sanitize() is the codebase's existing answer,
        // and its own docblock names this exact hazard.
        return $pdf->download(DocumentFileName::sanitize($filename));
    }
}
```

- [ ] **Step 4: Run the test again**

```bash
DB_DATABASE=opeschool_scratch_p1 "$PHP" artisan test tests/Feature/Reporting/ExportFilenameTest.php
```

Expected: `Tests:  3 passed`. Note `sanitize()` also folds the extension dot correctly — `asset-card-AST-000001.pdf` keeps its `.pdf` because `.` is in the allowed set.

- [ ] **Step 5: Remove the three now-redundant hand-rolled sanitisations**

These three did the job by hand and now do it twice; leaving them in teaches the next author that the helper does not sanitise.

In `app/Modules/Accounting/Livewire/Expenses/Show.php:67`, `app/Modules/Fees/Livewire/CashDesk/Show.php:85` and `app/Modules/Accounting/Livewire/YearEnd/Console.php:247`, replace each `str_replace('/', '-', $x)` inside the `PdfExport::download(...)` filename argument with plain `$x`. Verify none of the three is used for anything other than the filename first:

```bash
grep -n "str_replace('/', '-'" app/Modules/Accounting/Livewire/Expenses/Show.php app/Modules/Fees/Livewire/CashDesk/Show.php app/Modules/Accounting/Livewire/YearEnd/Console.php
```

- [ ] **Step 6: Static analysis**

```bash
"$PHP" vendor/bin/phpstan analyse --memory-limit=1G
```

Expected: `[OK] No errors`.

- [ ] **Step 7: Commit**

```bash
git add app/Modules/Reporting/Support/PdfExport.php app/Modules/Accounting/Livewire/Expenses/Show.php app/Modules/Fees/Livewire/CashDesk/Show.php app/Modules/Accounting/Livewire/YearEnd/Console.php tests/Feature/Reporting/ExportFilenameTest.php
git commit -m "fix(reporting): sanitise the download filename inside PdfExport, closing nine call sites"
```

---

### Task 6: `pickAccount()` writes an out-of-range line index

`pickAccount($index, $accountId)` writes `$this->lines[$index][...]` without checking the index exists. An out-of-range index creates a partial row with no `debit`/`credit` key, and the next render 500s in `runningTotals()` with `Undefined array key "debit"`. Reachable in the UI (removing a line while a later line's picker is open) and trivially reachable from the client.

**Files:**
- Modify: `app/Modules/Accounting/Livewire/JournalEntries/Form.php:187`
- Create: `tests/Feature/Accounting/JournalEntryFormTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Accounting/JournalEntryFormTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Accounting\Livewire\JournalEntries\Form;
use App\Modules\Identity\Domain\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../Reporting/P13MoneyHelpers.php';

uses(RefreshDatabase::class);

it('ignores an account pick for a line index that does not exist', function (): void {
    $user = p13moneyUserAs(Role::Accountant);

    Livewire::actingAs($user)
        ->test(Form::class)
        ->call('pickAccount', 99, 1)
        ->assertHasNoErrors()
        ->assertOk();
});
```

- [ ] **Step 2: Run it and watch it 500**

```bash
DB_DATABASE=opeschool_scratch_p1 "$PHP" artisan test tests/Feature/Accounting/JournalEntryFormTest.php
```

Expected: FAIL — `ErrorException: Undefined array key "debit"`.

- [ ] **Step 3: Guard the write**

In `app/Modules/Accounting/Livewire/JournalEntries/Form.php`, at the top of `pickAccount()`:

```php
    public function pickAccount(int $index, int $accountId): void
    {
        // $index arrives from a wire:click argument, so it is attacker-
        // controlled and it also goes stale legitimately: removeLine() is
        // wired on the same rows, so removing a line while a later line's
        // picker is open lands here with an index that no longer exists.
        // Writing it would mint a partial row with no debit/credit key and
        // the NEXT render dies in runningTotals(), which is a 500 the
        // operator cannot connect to anything they did.
        if (! isset($this->lines[$index])) {
            return;
        }
```

- [ ] **Step 4: Run the test again**

```bash
DB_DATABASE=opeschool_scratch_p1 "$PHP" artisan test tests/Feature/Accounting/JournalEntryFormTest.php
```

Expected: `Tests:  1 passed`.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Accounting/Livewire/JournalEntries/Form.php tests/Feature/Accounting/JournalEntryFormTest.php
git commit -m "fix(accounting): ignore an account pick for a journal line index that no longer exists"
```

---

### Task 7: `saveLine()` 500s instead of refusing when no budget is selected

`Accounting\Livewire\Budgets\Index::saveLine()` throws an unhandled `ModelNotFoundException` when `budgetId` points at nothing. A form that cannot be satisfied must refuse with a message, never with a stack trace.

**Files:**
- Modify: `app/Modules/Accounting/Livewire/Budgets/Index.php` (`saveLine()`)
- Modify: `lang/en/opes.php`, `lang/fr/opes.php`
- Create: `tests/Feature/Accounting/BudgetLineRefusalTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Accounting/BudgetLineRefusalTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Accounting\Livewire\Budgets\Index;
use App\Modules\Identity\Domain\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../Reporting/P13MoneyHelpers.php';

uses(RefreshDatabase::class);

it('refuses a budget line with a validation message when no budget is selected', function (): void {
    $user = p13moneyUserAs(Role::Accountant);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('budgetId', null)
        ->call('saveLine')
        ->assertHasErrors('budgetId');
});
```

- [ ] **Step 2: Run it and watch the exception escape**

```bash
DB_DATABASE=opeschool_scratch_p1 "$PHP" artisan test tests/Feature/Accounting/BudgetLineRefusalTest.php
```

Expected: FAIL — `Illuminate\Database\Eloquent\ModelNotFoundException: No query results for model [App\Modules\Accounting\Models\Budget]`.

- [ ] **Step 3: Refuse before the action call**

At the top of `saveLine()` in `app/Modules/Accounting/Livewire/Budgets/Index.php`:

```php
        // A missing selection is an operator state, not an exceptional one:
        // it must produce the same refusal every other form produces, not a
        // framework stack trace on a screen the operator cannot back out of.
        if ($this->budgetId === null || ! Budget::query()->whereKey($this->budgetId)->exists()) {
            throw ValidationException::withMessages([
                'budgetId' => __('opes.budgets.select_a_budget_first'),
            ]);
        }
```

Add `use Illuminate\Validation\ValidationException;` if it is not already imported, and `use App\Modules\Accounting\Models\Budget;` likewise.

Add to `lang/en/opes.php` under the `budgets` key (create it if absent):

```php
        'select_a_budget_first' => 'Choose a budget before adding a line to it.',
```

and to `lang/fr/opes.php`:

```php
        'select_a_budget_first' => 'Choisissez un budget avant d’y ajouter une ligne.',
```

- [ ] **Step 4: Run the test again**

```bash
DB_DATABASE=opeschool_scratch_p1 "$PHP" artisan test tests/Feature/Accounting/BudgetLineRefusalTest.php
```

Expected: `Tests:  1 passed`.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Accounting/Livewire/Budgets/Index.php lang/en/opes.php lang/fr/opes.php tests/Feature/Accounting/BudgetLineRefusalTest.php
git commit -m "fix(accounting): refuse a budget line with no budget instead of 500-ing"
```

---

### Task 8: Hide the Print button on a cancelled payslip

All 18 payroll items are `cancelled`, so `/payroll/payslips/{id}/print` answers 422 for every one of them: the Print button on the payroll run screen can never succeed against current data. The 422 is *correct* — a cancelled line has no payslip — so the fix is not to weaken it but to stop offering a control that cannot work.

**Files:**
- Modify: `resources/views/livewire/payroll/show.blade.php`
- Modify: `tests/Feature/Payroll/` — add to the existing run-screen test file if one exists; otherwise create `tests/Feature/Payroll/PayslipButtonTest.php`

- [ ] **Step 1: Find the Print control**

```bash
grep -n "payslip" resources/views/livewire/payroll/show.blade.php
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Payroll/PayslipButtonTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Payroll\Livewire\Show;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Reporting/P13MoneyHelpers.php';

uses(RefreshDatabase::class);

it('does not offer a payslip print control on a cancelled payroll item', function (): void {
    $user = p13moneyUserAs(Role::PayrollOfficer);

    $runId = (int) DB::table('payroll_runs')->insertGetId([
        'period_month' => 3, 'period_year' => 2026, 'status' => 'approved',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('payroll_items')->insert([
        'payroll_run_id' => $runId, 'status' => 'cancelled',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(Show::class, ['run' => $runId])
        ->assertDontSeeHtml('payslips/');
});
```

Fill in any additional NOT NULL columns from `database/migrations/*payroll*`.

- [ ] **Step 3: Run it and watch it fail**

```bash
DB_DATABASE=opeschool_scratch_p1 "$PHP" artisan test tests/Feature/Payroll/PayslipButtonTest.php
```

Expected: FAIL — the link is rendered.

- [ ] **Step 4: Wrap the control in the state it needs**

In `resources/views/livewire/payroll/show.blade.php`, wrap the payslip print anchor:

```blade
                    @if ($item->status !== 'cancelled')
                        {{-- A cancelled line has no payslip to issue, and the
                             print route correctly answers 422. Offering a
                             control that can only fail is worse than offering
                             none: the operator reads the refusal as a bug. --}}
                        <a href="{{ url('/payroll/payslips/'.$item->id.'/print') }}"
                           class="text-sm font-medium text-primary hover:underline">
                            {{ __('opes.payroll.print_payslip') }}
                        </a>
                    @endif
```

Keep whatever attributes the existing anchor already carries; only the `@if` wrapper is new.

- [ ] **Step 5: Run the test again**

```bash
DB_DATABASE=opeschool_scratch_p1 "$PHP" artisan test tests/Feature/Payroll/PayslipButtonTest.php
```

Expected: `Tests:  1 passed`.

- [ ] **Step 6: Commit**

```bash
git add resources/views/livewire/payroll/show.blade.php tests/Feature/Payroll/PayslipButtonTest.php
git commit -m "fix(payroll): stop offering a payslip print control on a cancelled item"
```

---

# Phase 2 — Report cards become permanently unprintable

Scratch DB: `opeschool_scratch_p2`.

## The design decision, argued

**The bug.** `RenderDocument` renders three things and hashes the bytes: the **payload**, the **school chrome**, and the **subject label**. `6f02f00` froze the payload — but only for "receipt pattern" templates, i.e. templates with **no** registered `SnapshotSourceMap` source. `issueOriginal()` writes:

```php
'payload_snapshot' => ($template->snapshot_source !== null && SnapshotSourceMap::has($template->snapshot_source))
    ? null
    : $snapshot['payload'],
```

`RPT-CARD` **is** registered (`SnapshotSourceMap::SOURCES` has exactly one entry, `ReportCardSnapshot`), so report cards store `payload_snapshot = NULL`. That is correct reasoning *for the payload*: `report_card_snapshots.payload` is immutable by construction and carries a `BEFORE UPDATE` trigger, so freezing a second copy would be redundant. But the reasoning does not extend to the other two inputs:

- **Subject label.** `PrintReportCard::label()` re-derives it live from `students` + `assessment_periods` on every call, `RenderDocument::renderHtml()` passes it as `subject.label`, and `resources/views/documents/assessment/report-card.blade.php:60` prints it — the `?:` fallback always fires, because the registered payload's top-level keys are `rank, totals, mention, subjects, general_average` and there is **no `student` key**. Rename the period or the student and the reprint's bytes change, so `hash_equals` fails and `DocumentReproducibilityViolation` refuses the document **forever**. Reproduced on two real documents (`SCH/2026/RPT/000001` and its sibling, both 422).
- **School chrome.** `schoolChrome()` returns `$payload['school']` when present and otherwise calls `captureSchoolChrome()` live. The report-card payload has no `school` key, so the letterhead is re-derived on every reprint. Editing the school profile, branding or fiscal identity therefore strands every report card at once — and **Phase 4 of this plan asks an administrator to do exactly that**. Phase 2 must land first.

**Option B — drop `$subject['label']` from hashed output for mapped templates — is rejected.** The hash is `hash('sha256', $bytes)` of the rendered PDF. There is no way to remove a value from the hash without removing it from the page. Removing it means the bulletin prints no student name, because the registered payload has nothing to print in its place. "Exclude from the hash" collapses into "delete the pupil's name from a statutory document". It is also not fixable by adding `student` to the snapshot payload: that changes what publication writes and does nothing for the ~90 cards already issued.

**Option A — freeze the render envelope — is chosen.** Add one nullable JSON column, `issued_documents.render_envelope`, holding `{"subject_label": …, "school": {…}}` — the two render inputs that are *not* already immutable. One column rather than two because they are written and read together and share one append-only guard clause. The intent is already written into the codebase twice: `payload_snapshot`'s migration docblock states the principle, and `PrintReportCard::label()`'s own docblock says *"Denormalised onto the print log, so it must survive a later rename"* — the freeze was designed, it just never reached the render path.

Write the envelope for **every** snapshot-backed issue, mapped or not. Receipt-pattern documents already carry `school` inside their payload, so for them the `school` half is belt-and-braces, but the `subject_label` half is a real fix there too, and one unbranched write is less to get wrong than two.

**Recovery for the two documents already stranded.** `document_print_logs.subject_label_at_time` records the label *as at issue*. A repair command re-renders each envelope-less document with that recorded label; if the bytes reproduce the recorded `content_hash`, the envelope is frozen. If they do not, it reports and changes nothing. That is evidence-based recovery from the audit trail, not hash-shopping — exactly one candidate is tried, and it is the one written down at issue time.

---

### Task 9: Add the `render_envelope` column

**Files:**
- Create: `database/migrations/2026_08_14_330001_add_render_envelope_to_issued_documents.php`
- Modify: `app/Modules/Reporting/Models/IssuedDocument.php`

- [ ] **Step 1: Write the migration**

Create `database/migrations/2026_08_14_330001_add_render_envelope_to_issued_documents.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/10-documents.md 4.2/4.5.
 *
 * `payload_snapshot` (2026_08_13_320001) froze the PAYLOAD, but only for
 * receipt-pattern templates - those with no registered SnapshotSourceMap
 * entry. For a REGISTERED source (today: RPT-CARD -> report_card_snapshots)
 * the payload is immutable by construction, so it is deliberately left NULL.
 *
 * That leaves the other two render inputs unfrozen for exactly those
 * templates: the school CHROME (letterhead, crest, ministry headers, fiscal
 * line) and the SUBJECT LABEL (the pupil's name and the period's name, which
 * PrintReportCard re-derives live). Both are rendered into the hashed bytes,
 * so renaming an assessment period or a student made every report card
 * issued under the old name permanently unprintable - a 422 with no way back.
 * Confirmed on two live documents.
 *
 * This column freezes that envelope at issue and the reprint path reads it
 * back, exactly as payload_snapshot already does for the payload. Nullable
 * because documents issued before it existed have none; those are backfilled
 * on their next successful reprint, and recovered by
 * `php artisan opes:documents:repair-envelope` where they are already stuck.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issued_documents', function (Blueprint $table): void {
            $table->json('render_envelope')->nullable()->after('payload_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('issued_documents', function (Blueprint $table): void {
            $table->dropColumn('render_envelope');
        });
    }
};
```

- [ ] **Step 2: Teach the model about the column**

In `app/Modules/Reporting/Models/IssuedDocument.php`, add to the class docblock after the `payload_snapshot` line:

```php
 * @property array{subject_label?: string, school?: array<string, mixed>}|null $render_envelope
```

Add `'render_envelope'` to `$fillable`, immediately after `'payload_snapshot'`:

```php
        'snapshot_type', 'snapshot_id', 'payload_snapshot', 'render_envelope',
```

Add the cast in `casts()`:

```php
            'payload_snapshot' => 'array',
            'render_envelope' => 'array',
```

And extend the one-way-backfill exemption in `booted()`, immediately after the `payload_snapshot` clause:

```php
                // Same one-way backfill as payload_snapshot above, for the
                // same reason and under the same proof: RenderDocument writes
                // this only AFTER a re-render has reproduced the recorded
                // content_hash byte for byte, so what gets frozen is provably
                // the envelope the original artefact was rendered with.
                if ($column === 'render_envelope' && $document->getOriginal('render_envelope') === null) {
                    continue;
                }
```

- [ ] **Step 3: Run the migration on the scratch database**

```bash
"C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe" -uroot -e "CREATE DATABASE IF NOT EXISTS opeschool_scratch_p2"
PHP='C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe'
DB_DATABASE=opeschool_scratch_p2 "$PHP" artisan migrate --force
```

Expected: the new migration reported as `DONE`.

- [ ] **Step 4: Confirm the existing snapshot suite still passes**

```bash
DB_DATABASE=opeschool_scratch_p2 "$PHP" artisan test tests/Feature/Reporting/DocumentPayloadSnapshotTest.php tests/Feature/Reporting/IssuedDocumentTest.php
```

Expected: all pass — the column is additive and nothing reads it yet.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_08_14_330001_add_render_envelope_to_issued_documents.php app/Modules/Reporting/Models/IssuedDocument.php
git commit -m "feat(reporting): add issued_documents.render_envelope for the chrome and label as at issue"
```

---

### Task 10: Freeze and read back the render envelope

**Files:**
- Modify: `app/Modules/Reporting/Actions/RenderDocument.php` (`renderSnapshotBacked` ~202-325, `issueOriginal` ~331-468, `schoolChrome` ~575-585)
- Create: `tests/Feature/Assessment/AssessmentTestHelpers.php`
- Modify: `tests/Feature/Assessment/PublicationTest.php`
- Create: `tests/Feature/Assessment/ReportCardReprintTest.php`

- [ ] **Step 1: Extract the assessment fixture helpers so a second file can use them**

`PublicationTest.php` defines `assessmentTruncateAll()`, `assessmentPublisher()` and `assessmentFixture()` inside `if (! function_exists(...))` guards, and `ReportCardSnapshot` has **no factory by design** — a snapshot may only come out of a real publication. Move all three function definitions verbatim (guards included, docblocks included) out of `tests/Feature/Assessment/PublicationTest.php` and into a new file `tests/Feature/Assessment/AssessmentTestHelpers.php` with this preamble:

```php
<?php

declare(strict_types=1);

use App\Modules\Assessment\Actions\ConfigureReportCard;
use App\Modules\Assessment\Models\AssessmentComponent;
use App\Modules\Assessment\Models\AssessmentFramework;
use App\Modules\Assessment\Models\GradeBand;
use App\Modules\Identity\Domain\Permission as PermissionEnum;
use App\Modules\Identity\Models\User;
use Database\Factories\ReportCardConfigFactory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Shared because a published report card cannot be faked: ReportCardSnapshot
 * has no factory ON PURPOSE, so every test that needs an issued bulletin has
 * to run a real publication. Extracted from PublicationTest, unchanged.
 */
```

Then at the top of `tests/Feature/Assessment/PublicationTest.php`, immediately after its `use` block, add:

```php
require_once __DIR__.'/AssessmentTestHelpers.php';
```

and delete the three moved function blocks from it. Remove any `use` statements in `PublicationTest.php` that are now unused only if PHPStan reports them.

- [ ] **Step 2: Prove the extraction changed nothing**

```bash
DB_DATABASE=opeschool_scratch_p2 "$PHP" artisan test tests/Feature/Assessment/PublicationTest.php
```

Expected: the same pass count as before the move (record it; it should be unchanged).

- [ ] **Step 3: Commit the extraction on its own**

```bash
git add tests/Feature/Assessment/AssessmentTestHelpers.php tests/Feature/Assessment/PublicationTest.php
git commit -m "test(assessment): extract the publication fixture helpers so more than one file can publish"
```

- [ ] **Step 4: Write the failing test**

Create `tests/Feature/Assessment/ReportCardReprintTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Assessment\Actions\PrintReportCard;
use App\Modules\Assessment\Actions\PublishPeriod;
use App\Modules\Identity\Domain\Permission as PermissionEnum;
use App\Modules\Reporting\Models\IssuedDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/AssessmentTestHelpers.php';
require_once __DIR__.'/../Reporting/P13MoneyHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    p13moneyDocumentProfile();
    p13moneyConfirmedFiscalIdentity();
});

/**
 * A published report card that a later rename makes UNPRINTABLE is the only
 * defect in the 2026-08-13 audits that destroys a statutory record for good.
 * The reprint re-renders and compares hashes; the pupil's name and the
 * period's name are re-derived LIVE into those bytes, so renaming either one
 * makes every card already issued under the old name refuse forever.
 */
function reportCardPublisher(): App\Modules\Identity\Models\User
{
    $user = assessmentPublisher();

    foreach ([
        PermissionEnum::AcademicsView,
        PermissionEnum::DocumentsReprint,
    ] as $permission) {
        $user->givePermissionTo($permission->value);
    }

    return $user->fresh() ?? $user;
}

it('reprints a report card after the assessment period is renamed', function (): void {
    actingAs(reportCardPublisher());

    $fx = assessmentFixture(['groups' => 1, 'students' => 1]);
    app(PublishPeriod::class)->handle($fx['period_id'], $fx['class_group_ids'], $fx['config_id']);

    $enrollmentId = $fx['enrollments'][$fx['class_group_ids'][0]][0];

    $original = app(PrintReportCard::class)->handle($enrollmentId, $fx['period_id']);
    expect($original->html)->toContain('Sequence 1');

    // The exact production event that stranded SCH/2026/RPT/000001: an
    // administrator corrects the period's name.
    DB::table('assessment_periods')->where('id', $fx['period_id'])->update(['name' => 'First Sequence']);

    $reprint = app(PrintReportCard::class)->handle($enrollmentId, $fx['period_id']);
    expect($reprint->isDuplicate)->toBeTrue();
    expect($reprint->html)->toContain('Sequence 1');
    expect($reprint->html)->not->toContain('First Sequence');
});

it('reprints a report card after the school profile is edited', function (): void {
    actingAs(reportCardPublisher());

    $fx = assessmentFixture(['groups' => 1, 'students' => 1]);
    app(PublishPeriod::class)->handle($fx['period_id'], $fx['class_group_ids'], $fx['config_id']);

    $enrollmentId = $fx['enrollments'][$fx['class_group_ids'][0]][0];
    app(PrintReportCard::class)->handle($enrollmentId, $fx['period_id']);

    // The latent second vector: the letterhead is re-derived live because the
    // report-card payload carries no `school` key. Phase 4 of this plan makes
    // an administrator do this, so it must be survivable before Phase 4 runs.
    p13moneyDocumentProfile(['address_line1' => 'BP 4000, Rue Manga Bell', 'phone' => '+237 233 000 000']);

    $reprint = app(PrintReportCard::class)->handle($enrollmentId, $fx['period_id']);
    expect($reprint->isDuplicate)->toBeTrue();
    expect($reprint->html)->not->toContain('Rue Manga Bell');
});

it('freezes the envelope at issue and refuses to let it be rewritten', function (): void {
    actingAs(reportCardPublisher());

    $fx = assessmentFixture(['groups' => 1, 'students' => 1]);
    app(PublishPeriod::class)->handle($fx['period_id'], $fx['class_group_ids'], $fx['config_id']);

    app(PrintReportCard::class)->handle($fx['enrollments'][$fx['class_group_ids'][0]][0], $fx['period_id']);

    /** @var IssuedDocument $issued */
    $issued = IssuedDocument::query()->where('subject_type', 'Enrollment')->firstOrFail();

    expect($issued->render_envelope)->not->toBeNull();
    expect($issued->render_envelope['subject_label'] ?? null)->toContain('Sequence 1');
    expect($issued->render_envelope['school'] ?? null)->toBeArray();

    // The whole fix depends on this being append-only, exactly like
    // content_hash and payload_snapshot.
    expect(fn () => $issued->update(['render_envelope' => ['subject_label' => 'tampered']]))
        ->toThrow(RuntimeException::class, 'append-only');
});

it('backfills a legacy document that still reproduces, so the next rename cannot strand it', function (): void {
    actingAs(reportCardPublisher());

    $fx = assessmentFixture(['groups' => 1, 'students' => 1]);
    app(PublishPeriod::class)->handle($fx['period_id'], $fx['class_group_ids'], $fx['config_id']);

    $enrollmentId = $fx['enrollments'][$fx['class_group_ids'][0]][0];
    app(PrintReportCard::class)->handle($enrollmentId, $fx['period_id']);

    // Reproduce a document issued BEFORE envelope freezing existed. The query
    // builder is the only way past the model's append-only guard.
    $issuedId = (int) DB::table('issued_documents')->where('subject_type', 'Enrollment')->value('id');
    DB::table('issued_documents')->where('id', $issuedId)->update(['render_envelope' => null]);

    // Nothing has been renamed, so this reprint still reproduces the recorded
    // hash - which is exactly when freezing the envelope is provably safe.
    app(PrintReportCard::class)->handle($enrollmentId, $fx['period_id']);

    expect(DB::table('issued_documents')->where('id', $issuedId)->value('render_envelope'))->not->toBeNull();

    DB::table('assessment_periods')->where('id', $fx['period_id'])->update(['name' => 'Renamed After Backfill']);

    $reprint = app(PrintReportCard::class)->handle($enrollmentId, $fx['period_id']);
    expect($reprint->html)->toContain('Sequence 1');
});
```

- [ ] **Step 5: Run it and watch the reprints refuse**

```bash
DB_DATABASE=opeschool_scratch_p2 "$PHP" artisan test tests/Feature/Assessment/ReportCardReprintTest.php
```

Expected: 4 failures. The first two report `DocumentReproducibilityViolation … produced content hash … where … was recorded at issue`; the third reports `render_envelope` is null.

- [ ] **Step 6: Resolve the envelope before chrome and label are used**

In `app/Modules/Reporting/Actions/RenderDocument.php`, inside `renderSnapshotBacked()`, replace:

```php
        $snapshot = $this->loadSnapshot($template, $snapshotId, $data, $issued);
        $chrome = $this->schoolChrome($template, $schoolSectionId, $snapshot['payload']);
        $actor = $this->currentActor();
```

with:

```php
        $snapshot = $this->loadSnapshot($template, $snapshotId, $data, $issued);

        // The envelope is the OTHER two render inputs - the letterhead and
        // the subject label. `payload_snapshot` freezes the payload, but for
        // a template with a REGISTERED SnapshotSourceMap entry the payload is
        // immutable by construction and deliberately not copied, which left
        // these two being re-derived LIVE on every reprint and rendered into
        // the hashed bytes. Renaming an assessment period therefore refused
        // every bulletin issued under the old name, permanently.
        $envelope = is_array($issued?->render_envelope) ? $issued->render_envelope : null;

        $chrome = $this->schoolChrome($template, $schoolSectionId, $snapshot['payload'], $envelope);

        if ($envelope !== null && is_string($envelope['subject_label'] ?? null)) {
            $subjectLabel = $envelope['subject_label'];
        }

        $actor = $this->currentActor();
```

- [ ] **Step 7: Let the envelope's chrome win in `schoolChrome()`**

Replace the signature and first branch of `schoolChrome()`:

```php
    /**
     * The chrome every document shares: state header, school identity,
     * fiscal identity, branding.
     *
     * Precedence, strongest first: the envelope frozen onto the issued
     * document at issue; then a `school` block the snapshot payload captured
     * itself (the receipt pattern); then a live read. Anything but the live
     * read is what makes a years-later reprint carry the letterhead AS AT
     * ISSUE rather than today's - which is the difference between a reprint
     * and a forgery, and, because the chrome is inside the hashed bytes,
     * between a reprint and a permanent 422.
     *
     * @param  array<string, mixed>  $payload
     * @param  array{subject_label?: string, school?: array<string, mixed>}|null  $envelope
     * @return array<string, mixed>
     */
    private function schoolChrome(DocumentTemplate $template, ?int $schoolSectionId, array $payload, ?array $envelope = null): array
    {
        if (is_array($envelope['school'] ?? null)) {
            /** @var array<string, mixed> $frozen */
            $frozen = $envelope['school'];

            return $frozen;
        }

        if (isset($payload['school']) && is_array($payload['school'])) {
            /** @var array<string, mixed> $captured */
            $captured = $payload['school'];

            return $captured;
        }

        return $this->captureSchoolChrome($template->state_header !== 'none', $schoolSectionId);
    }
```

- [ ] **Step 8: Write the envelope at issue**

In `issueOriginal()`, in the `IssuedDocument::query()->create([...])` array, add immediately after the `payload_snapshot` entry:

```php
            // Frozen for EVERY snapshot-backed issue, mapped or not. Receipt-
            // pattern payloads already carry `school`, so for them this half
            // is belt-and-braces - but `subject_label` is re-derived live for
            // them too, and one unbranched write is less to get wrong than a
            // condition that has already been got wrong once.
            'render_envelope' => ['subject_label' => $subjectLabel, 'school' => $chrome],
```

- [ ] **Step 9: Backfill on a reprint that still reproduces**

In `renderSnapshotBacked()`, immediately after the existing `payload_snapshot` backfill block (the `if ($issued->payload_snapshot === null && …)`), add:

```php
        // Same proof as the payload backfill directly above: control only
        // reaches here because the re-render reproduced the recorded hash
        // byte for byte, so this envelope IS the original artefact's. Only
        // ever NULL -> value; IssuedDocument's guard refuses anything else.
        if ($issued->render_envelope === null) {
            $issued->render_envelope = ['subject_label' => $subjectLabel, 'school' => $chrome];
            $issued->save();
        }
```

- [ ] **Step 10: Run the test again**

```bash
DB_DATABASE=opeschool_scratch_p2 "$PHP" artisan test tests/Feature/Assessment/ReportCardReprintTest.php
```

Expected: `Tests:  4 passed`.

- [ ] **Step 11: Confirm no receipt-pattern regression**

```bash
DB_DATABASE=opeschool_scratch_p2 "$PHP" artisan test tests/Feature/Reporting
```

Expected: all pass, including `SnapshotByteIdenticalTest`, `ReceiptRenderTest`, `SpecimenWatermarkTest` and `DocumentPayloadSnapshotTest`.

- [ ] **Step 12: Static analysis**

```bash
"$PHP" vendor/bin/phpstan analyse --memory-limit=1G
```

Expected: `[OK] No errors`.

- [ ] **Step 13: Commit**

```bash
git add app/Modules/Reporting/Actions/RenderDocument.php tests/Feature/Assessment/ReportCardReprintTest.php
git commit -m "fix(reporting): freeze the render envelope at issue so a rename cannot strand a bulletin"
```

---

### Task 11: Recover the documents already stranded

Two live report cards are already at 422 and no amount of forward fixing reaches them. `document_print_logs.subject_label_at_time` holds the label as at issue — the recovery key.

**Files:**
- Create: `app/Console/Commands/RepairDocumentEnvelope.php`
- Create: `tests/Feature/Reporting/RepairDocumentEnvelopeTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Reporting/RepairDocumentEnvelopeTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Assessment\Actions\PrintReportCard;
use App\Modules\Assessment\Actions\PublishPeriod;
use App\Modules\Identity\Domain\Permission as PermissionEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/../Assessment/AssessmentTestHelpers.php';
require_once __DIR__.'/P13MoneyHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    p13moneyDocumentProfile();
    p13moneyConfirmedFiscalIdentity();
});

it('recovers a document stranded by a rename, using the label recorded at issue', function (): void {
    $user = assessmentPublisher();
    $user->givePermissionTo(PermissionEnum::AcademicsView->value);
    $user->givePermissionTo(PermissionEnum::DocumentsReprint->value);
    actingAs($user->fresh() ?? $user);

    $fx = assessmentFixture(['groups' => 1, 'students' => 1]);
    app(PublishPeriod::class)->handle($fx['period_id'], $fx['class_group_ids'], $fx['config_id']);
    $enrollmentId = $fx['enrollments'][$fx['class_group_ids'][0]][0];

    app(PrintReportCard::class)->handle($enrollmentId, $fx['period_id']);

    // Recreate a pre-fix stranded document exactly: no envelope, and the
    // source row renamed underneath it.
    $issuedId = (int) DB::table('issued_documents')->where('subject_type', 'Enrollment')->value('id');
    DB::table('issued_documents')->where('id', $issuedId)->update(['render_envelope' => null]);
    DB::table('assessment_periods')->where('id', $fx['period_id'])->update(['name' => 'Renamed Sequence']);

    expect(fn () => app(PrintReportCard::class)->handle($enrollmentId, $fx['period_id']))
        ->toThrow(App\Modules\Reporting\Domain\DocumentReproducibilityViolation::class);

    $this->artisan('opes:documents:repair-envelope')
        ->expectsOutputToContain('repaired: 1')
        ->assertExitCode(0);

    $reprint = app(PrintReportCard::class)->handle($enrollmentId, $fx['period_id']);
    expect($reprint->html)->toContain('Sequence 1');
});

it('changes nothing for a document whose recorded label does not reproduce', function (): void {
    $user = assessmentPublisher();
    $user->givePermissionTo(PermissionEnum::AcademicsView->value);
    $user->givePermissionTo(PermissionEnum::DocumentsReprint->value);
    actingAs($user->fresh() ?? $user);

    $fx = assessmentFixture(['groups' => 1, 'students' => 1]);
    app(PublishPeriod::class)->handle($fx['period_id'], $fx['class_group_ids'], $fx['config_id']);
    app(PrintReportCard::class)->handle($fx['enrollments'][$fx['class_group_ids'][0]][0], $fx['period_id']);

    $issuedId = (int) DB::table('issued_documents')->where('subject_type', 'Enrollment')->value('id');
    DB::table('issued_documents')->where('id', $issuedId)->update(['render_envelope' => null]);

    // A label that was never the issued one. The command must not force it.
    DB::table('document_print_logs')->where('issued_document_id', $issuedId)
        ->update(['subject_label_at_time' => 'Not the label that was issued']);

    $this->artisan('opes:documents:repair-envelope')
        ->expectsOutputToContain('unrecoverable: 1')
        ->assertExitCode(0);

    expect(DB::table('issued_documents')->where('id', $issuedId)->value('render_envelope'))->toBeNull();
});
```

- [ ] **Step 2: Run it and watch the command not exist**

```bash
DB_DATABASE=opeschool_scratch_p2 "$PHP" artisan test tests/Feature/Reporting/RepairDocumentEnvelopeTest.php
```

Expected: FAIL — `The command "opes:documents:repair-envelope" does not exist.`

- [ ] **Step 3: Write the command**

Create `app/Console/Commands/RepairDocumentEnvelope.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Reporting\Models\DocumentPrintLog;
use App\Modules\Reporting\Models\IssuedDocument;
use Illuminate\Console\Command;
use Throwable;

/**
 * One-shot recovery for documents issued BEFORE `render_envelope` existed
 * AND already stranded by a rename - the reprint path's own backfill cannot
 * reach them, because it only runs after a re-render that reproduces the
 * recorded hash, and for these that re-render is precisely what fails.
 *
 * The recovery key is the audit trail: `document_print_logs
 * .subject_label_at_time` recorded the label AS AT ISSUE, which
 * PrintReportCard's docblock already says it exists to do. This tries
 * EXACTLY ONE candidate - that recorded label - and freezes the envelope
 * only if the resulting bytes reproduce `content_hash`. It is not a search:
 * a document whose recorded label does not reproduce is reported and left
 * untouched, because forcing an envelope that does not reproduce would turn
 * an honest refusal into a silent forgery.
 */
final class RepairDocumentEnvelope extends Command
{
    /** @var string */
    protected $signature = 'opes:documents:repair-envelope {--dry-run : Report what would change and write nothing}';

    /** @var string */
    protected $description = 'Freeze the render envelope on documents issued before the column existed, using the label recorded at issue.';

    public function handle(): int
    {
        $repaired = 0;
        $alreadyFine = 0;
        $unrecoverable = 0;

        IssuedDocument::query()
            ->whereNull('render_envelope')
            ->orderBy('id')
            ->chunkById(50, function ($documents) use (&$repaired, &$alreadyFine, &$unrecoverable): void {
                foreach ($documents as $document) {
                    $label = DocumentPrintLog::query()
                        ->where('issued_document_id', $document->getKey())
                        ->orderBy('id')
                        ->value('subject_label_at_time');

                    if (! is_string($label) || $label === '') {
                        $unrecoverable++;
                        $this->line(sprintf('  %s: no print log records the label at issue', (string) ($document->serial ?? $document->getKey())));

                        continue;
                    }

                    try {
                        $outcome = app(\App\Modules\Reporting\Actions\FreezeEnvelopeFromPrintLog::class)
                            ->handle((int) $document->getKey(), $label, (bool) $this->option('dry-run'));
                    } catch (Throwable $e) {
                        $unrecoverable++;
                        $this->line(sprintf('  %s: %s', (string) ($document->serial ?? $document->getKey()), $e->getMessage()));

                        continue;
                    }

                    match ($outcome) {
                        'repaired' => $repaired++,
                        'already_fine' => $alreadyFine++,
                        default => $unrecoverable++,
                    };
                }
            });

        $this->info(sprintf('repaired: %d · already fine: %d · unrecoverable: %d', $repaired, $alreadyFine, $unrecoverable));

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Write the action the command delegates to**

Create `app/Modules/Reporting/Actions/FreezeEnvelopeFromPrintLog.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Modules\Reporting\Models\IssuedDocument;
use Illuminate\Support\Facades\DB;

/**
 * Re-renders one issued document with the label recorded on its FIRST print
 * log and freezes the envelope only if the bytes reproduce the recorded
 * content_hash. Lives in the module rather than the command so the proof
 * ("it reproduced, therefore it is the original") is testable on its own and
 * cannot drift away from RenderDocument's reprint path.
 *
 * @return 'repaired'|'already_fine'|'unrecoverable'
 */
final class FreezeEnvelopeFromPrintLog
{
    public function __construct(private readonly RenderDocument $render) {}

    public function handle(int $issuedDocumentId, string $recordedLabel, bool $dryRun = false): string
    {
        /** @var IssuedDocument $issued */
        $issued = IssuedDocument::query()->findOrFail($issuedDocumentId);

        if ($issued->render_envelope !== null) {
            return 'already_fine';
        }

        $candidate = $this->render->rebuildEnvelope($issued, $recordedLabel);

        if ($candidate === null) {
            return 'unrecoverable';
        }

        if ($dryRun) {
            return 'repaired';
        }

        DB::transaction(function () use ($issued, $candidate): void {
            $issued->render_envelope = $candidate;
            $issued->save();
        });

        return 'repaired';
    }
}
```

- [ ] **Step 5: Expose `rebuildEnvelope()` on `RenderDocument`**

Add to `app/Modules/Reporting/Actions/RenderDocument.php`, next to `captureSchoolChrome()`:

```php
    /**
     * Recovery half of the envelope freeze: re-render one issued document
     * with a CANDIDATE subject label and return the envelope only if the
     * bytes reproduce the hash recorded at issue. Returns null otherwise -
     * an envelope that does not reproduce is not the original's, and writing
     * it would replace an honest refusal with a quiet forgery.
     *
     * @return array{subject_label: string, school: array<string, mixed>}|null
     */
    public function rebuildEnvelope(IssuedDocument $issued, string $candidateLabel): ?array
    {
        /** @var DocumentTemplate $template */
        $template = DocumentTemplate::query()->findOrFail($issued->document_template_id);

        $lang = DocumentLanguage::from($issued->language);
        $snapshot = $this->loadSnapshot($template, $issued->snapshot_id, [], $issued);
        $chrome = $this->schoolChrome($template, null, $snapshot['payload']);

        $html = $this->renderHtml($template, $issued->template_version, $issued->serial, $lang, $chrome, $snapshot['payload'], $candidateLabel, [
            'watermark' => null,
            'issued_at' => $issued->issued_at,
            'copy_no' => 1,
        ]);

        $stamp = new PdfStamp(
            $issued->issued_at->format('YmdHis'),
            $this->stampSeed($template, $issued->subject_type, $issued->subject_id, $issued->snapshot_id, $issued->serial),
        );

        $bytes = $this->pdf->render($html, $template->paperSize(), $template->orientation(), $stamp, $this->pageFooter($lang));

        if (! hash_equals($issued->content_hash, hash('sha256', $bytes))) {
            return null;
        }

        return ['subject_label' => $candidateLabel, 'school' => $chrome];
    }
```

- [ ] **Step 6: Run the test again**

```bash
DB_DATABASE=opeschool_scratch_p2 "$PHP" artisan test tests/Feature/Reporting/RepairDocumentEnvelopeTest.php
```

Expected: `Tests:  2 passed`.

- [ ] **Step 7: Static analysis**

```bash
"$PHP" vendor/bin/phpstan analyse --memory-limit=1G
```

Expected: `[OK] No errors`.

- [ ] **Step 8: Run it for real against the dev database and confirm the two live documents**

```bash
"$PHP" artisan opes:documents:repair-envelope --dry-run
```

Expected: a line naming `SCH/2026/RPT/000001` (and its sibling) under `repaired`. Then run without `--dry-run` and confirm:

```bash
"$PHP" artisan tinker --execute="echo file_get_contents('http://localhost:8931/assessment/report-cards/1/2/print') ? 'ok' : 'fail';"
```

Expected: the print route no longer answers 422. If a document reports `unrecoverable`, record which and stop — do not force it.

- [ ] **Step 9: Commit**

```bash
git add app/Console/Commands/RepairDocumentEnvelope.php app/Modules/Reporting/Actions/FreezeEnvelopeFromPrintLog.php app/Modules/Reporting/Actions/RenderDocument.php tests/Feature/Reporting/RepairDocumentEnvelopeTest.php
git commit -m "fix(reporting): recover documents already stranded, using the label recorded at issue"
```

---

# Phase 3 — KPI cards: `icon-bg` → `tone`

Scratch DB: `opeschool_scratch_p3`.

**The leverage.** 42 screens use `x-kpi-card`. Exactly **2 pass `tone`**; **39 still pass the legacy `icon-bg`**, which recolours only the badge and leaves `$tone` at its `'green'` default — so every card surface in the product is the same mint, whatever the badge. `/finance/invoices` shows it plainly: four identical mint cards carrying a dark-green, a bright-orange, a bright-blue and a bright-purple badge. Mapping `icon-bg` onto the right `tone` inside the component repaints all 42 screens from one change.

**The survey** (`grep -rhoE 'icon-bg="[^"]*"' resources/views | sort | uniq -c`) — every value is a **literal**; there are no dynamic `:icon-bg` bindings, so a `match()` covers the whole surface:

| `icon-bg` | uses | hex | → `tone` | why |
|---|---|---|---|---|
| `bg-primary` | 37 | `#0B5A32` | `green` | the house green |
| `bg-badge-blue` | 29 | `#2563EB` | `blue` | `--color-kpi-blue-solid` is `#2563C9` — the same colour |
| `bg-badge-orange` | 28 | `#F97316` | `amber` | orange family; `amber-solid` is `#E0912F` |
| `bg-heritage-red` | 12 | `#D64545` | `pink` | `pink-solid` `#D6336C` is the palette's only warm-red surface |
| `bg-badge-purple` | 12 | `#7C3AED` | `purple` | `purple-solid` `#7C4DBE` |
| `bg-heritage-yellow` | 6 | `#D9A829` | `amber` | gold sits in the amber family |
| `bg-chrome` | 4 | `#002D17` | `green` | the darkest house green |
| `bg-badge-teal` | 4 | `#0D9488` | `green` | hue 174° is nearer green (145°) than blue (221°) |
| `bg-charcoal` | 1 | charcoal | `green` | the black badge finding B20 flags as out-of-palette |

Unknown values fall through to `green`, which is today's behaviour — so no caller can break.

### Task 12: Derive `tone` from the legacy `icon-bg`

**Files:**
- Modify: `resources/views/components/kpi-card.blade.php:1-35`
- Create: `tests/Feature/Ui/KpiToneTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Ui/KpiToneTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

/**
 * 39 of the 42 x-kpi-card callers still pass the legacy `icon-bg`, which
 * recolours only the badge - so every card SURFACE in the product stayed the
 * default mint and /finance/invoices shipped four identical cards carrying
 * four differently-coloured badges. Mapping the legacy prop onto the right
 * tone here repaints all 42 screens from one change; these tests are what
 * stop the map silently losing an arm.
 */
it('maps every legacy icon-bg value onto the matching surface tone', function (string $iconBg, string $surface): void {
    $html = Blade::render('<x-kpi-card label="X" value="1" icon-bg="'.$iconBg.'" />');

    expect($html)->toContain($surface);
})->with([
    ['bg-primary', 'bg-kpi-green'],
    ['bg-chrome', 'bg-kpi-green'],
    ['bg-badge-teal', 'bg-kpi-green'],
    ['bg-charcoal', 'bg-kpi-green'],
    ['bg-badge-blue', 'bg-kpi-blue'],
    ['bg-badge-orange', 'bg-kpi-amber'],
    ['bg-heritage-yellow', 'bg-kpi-amber'],
    ['bg-heritage-red', 'bg-kpi-pink'],
    ['bg-badge-purple', 'bg-kpi-purple'],
]);

it('lets an explicit tone beat the legacy prop', function (): void {
    $html = Blade::render('<x-kpi-card label="X" value="1" tone="purple" icon-bg="bg-badge-blue" />');

    expect($html)->toContain('bg-kpi-purple');
    expect($html)->not->toContain('bg-kpi-blue border');
});

it('keeps the badge colour the caller chose', function (): void {
    $html = Blade::render('<x-kpi-card label="X" value="1" icon-bg="bg-badge-orange" icon="<i></i>" />');

    expect($html)->toContain('bg-badge-orange');
});

it('falls back to green for an icon-bg nobody mapped', function (): void {
    $html = Blade::render('<x-kpi-card label="X" value="1" icon-bg="bg-something-new" />');

    expect($html)->toContain('bg-kpi-green');
});
```

- [ ] **Step 2: Run it and watch every arm return mint**

```bash
"C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe" -uroot -e "CREATE DATABASE IF NOT EXISTS opeschool_scratch_p3"
PHP='C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe'
DB_DATABASE=opeschool_scratch_p3 "$PHP" artisan test tests/Feature/Ui/KpiToneTest.php
```

Expected: 5 failures — every non-green arm, plus the explicit-tone test.

- [ ] **Step 3: Change the `tone` default to null so "unset" is distinguishable**

In `resources/views/components/kpi-card.blade.php`, replace the `@props` line:

```php
    'tone' => 'green',  // green | blue | pink | amber | purple
```

with:

```php
    'tone' => null,     // green | blue | pink | amber | purple; null derives from $iconBg
```

Both existing explicit callers pass `blue` and `amber` (`resources/views/livewire/dashboard.blade.php:56,84`), so nothing relied on the old default being distinguishable.

- [ ] **Step 4: Derive the tone**

Replace the `$tone` normalisation line:

```php
    $tone = in_array($tone, ['green', 'blue', 'pink', 'amber', 'purple'], true) ? $tone : 'green';
```

with:

```php
    // The legacy escape hatch recoloured the BADGE only, so 39 of the 42
    // callers left the card surface on the default mint - which is why
    // /finance/invoices shipped four identical cards carrying a green, an
    // orange, a blue and a purple badge. An explicit `tone` still wins; where
    // there is none, the legacy prop names the hue the caller already chose,
    // so the surface can be derived from it and all 42 screens repaint from
    // this one arm list. Anything unmapped keeps today's green.
    $tone = in_array($tone, ['green', 'blue', 'pink', 'amber', 'purple'], true)
        ? $tone
        : match ($iconBg) {
            'bg-badge-blue' => 'blue',
            'bg-badge-orange', 'bg-heritage-yellow' => 'amber',
            'bg-heritage-red' => 'pink',
            'bg-badge-purple' => 'purple',
            default => 'green',
        };
```

- [ ] **Step 5: Run the test again**

```bash
DB_DATABASE=opeschool_scratch_p3 "$PHP" artisan test tests/Feature/Ui/KpiToneTest.php
```

Expected: `Tests:  12 passed` (9 dataset rows + 3).

- [ ] **Step 6: Look at it**

Start the preview, resize to 1440×900, and screenshot `/finance/invoices`, `/students`, `/staff`, `/attendance`, `/inventory`, `/library`, `/payroll`, `/visitors`. Then resize to 375×812 and screenshot `/students`.

Expected, **verified by looking at the image, not by measuring**: on `/finance/invoices` the four cards now carry four different tinted surfaces matching their badges, instead of four identical mint surfaces. If any row still reads as one colour, its screen passes no `icon-bg` at all and belongs in Task 14.

- [ ] **Step 7: Commit**

```bash
git add resources/views/components/kpi-card.blade.php tests/Feature/Ui/KpiToneTest.php
git commit -m "fix(ui): derive the KPI surface tone from the legacy icon-bg, repainting 42 screens"
```

---

### Task 13: Give the label a fixed box so numerals share a baseline

Labels wrap to 1, 2 or 3 lines, so numerals in one row sit at three different heights — `/welfare/discipline`'s three zeros sit at y≈237, y≈258 and y≈276. The row reads ragged.

**Files:**
- Modify: `resources/views/components/kpi-card.blade.php:84-115`
- Modify: `tests/Feature/Ui/KpiToneTest.php`

- [ ] **Step 1: Add the failing assertion**

Append to `tests/Feature/Ui/KpiToneTest.php`:

```php
it('reserves a two-line label box so a short label does not raise its numeral', function (): void {
    $short = Blade::render('<x-kpi-card label="TOTAL CASES" value="0" />');
    $long = Blade::render('<x-kpi-card label="AWAITING GUARDIAN SIGNATURE" value="0" />');

    expect($short)->toContain('min-h-[2.4em]');
    expect($long)->toContain('min-h-[2.4em]');
});
```

- [ ] **Step 2: Run it and watch it fail**

```bash
DB_DATABASE=opeschool_scratch_p3 "$PHP" artisan test tests/Feature/Ui/KpiToneTest.php
```

Expected: FAIL — `min-h-[2.4em]` not found.

- [ ] **Step 3: Reserve the box**

In `resources/views/components/kpi-card.blade.php`, replace the label paragraph:

```blade
            <p class="text-xs font-semibold uppercase leading-tight tracking-wide text-balance text-charcoal/55">{{ $label }}</p>
```

with:

```blade
            {{-- A fixed two-line box, not a wrapping one. The card's height
                 is set by the numeral beside it, so a one-line label used to
                 pull its numeral 20-40px above its neighbours' and the row
                 read ragged - three zeros on /welfare/discipline sat at three
                 different heights. Reserving the box costs nothing on the
                 short labels and buys one shared baseline across the row. A
                 third line still wraps rather than clipping: a truncated
                 label is the label doing none of its job. --}}
            <p class="flex min-h-[2.4em] items-start text-xs font-semibold uppercase leading-tight tracking-wide text-balance text-charcoal/55">{{ $label }}</p>
```

- [ ] **Step 4: Run the test again**

```bash
DB_DATABASE=opeschool_scratch_p3 "$PHP" artisan test tests/Feature/Ui/KpiToneTest.php
```

Expected: all pass.

- [ ] **Step 5: Look at it**

Screenshot `/welfare/discipline` and `/attendance` at 1440×900. Expected: the numerals in each row now sit on one baseline. Confirm by looking.

- [ ] **Step 6: Commit**

```bash
git add resources/views/components/kpi-card.blade.php tests/Feature/Ui/KpiToneTest.php
git commit -m "fix(ui): reserve a two-line KPI label box so numerals in a row share a baseline"
```

---

### Task 14: Stop a single KPI stretching the full page width

`/users` renders one card — `TOTAL USERS 37` — spanning the whole 1133px. Teacher `/dashboard` does the same with one em dash. The `auto-fit` track has a `minmax` floor but no ceiling.

**Files:**
- Modify: `resources/views/components/list-screen.blade.php:136`
- Modify: `resources/views/livewire/dashboard.blade.php` (the KPI grid)
- Modify: `tests/Feature/Ui/KpiToneTest.php`

- [ ] **Step 1: Add the failing assertion**

Append to `tests/Feature/Ui/KpiToneTest.php`:

```php
it('caps the KPI track so a single card cannot span the page', function (): void {
    $html = file_get_contents(resource_path('views/components/list-screen.blade.php'));

    expect($html)->toContain('minmax(12rem,22rem)');
});
```

- [ ] **Step 2: Run it and watch it fail**

```bash
DB_DATABASE=opeschool_scratch_p3 "$PHP" artisan test tests/Feature/Ui/KpiToneTest.php
```

Expected: FAIL.

- [ ] **Step 3: Cap the track**

In `resources/views/components/list-screen.blade.php`, replace:

```blade
            <div class="grid min-w-max grid-cols-2 gap-3 sm:min-w-0 md:grid-cols-3 lg:min-w-0 lg:grid-cols-[repeat(auto-fit,minmax(12rem,1fr))]">
```

with:

```blade
            {{-- `min-w-max` only below sm. Above it the strip must size to the
                 viewport: at 375px min-w-max made the strip wider than the
                 screen and cut card 2 in half behind a scrollbar, which is the
                 first thing a phone user sees on every list screen.

                 The track has a CEILING as well as a floor now. auto-fit with
                 `1fr` let one card stretch to the full 1133px - /users shipped
                 `TOTAL USERS 37` alone across the page - which reads as a
                 layout accident rather than a stat. --}}
            <div class="grid grid-cols-2 gap-3 max-sm:min-w-max sm:min-w-0 md:grid-cols-3 lg:min-w-0 lg:grid-cols-[repeat(auto-fit,minmax(12rem,22rem))]">
```

Note this also closes finding A8 (the 375px KPI clip) — `min-w-max` now applies only below `sm`, so `grid-cols-2` sizes to the viewport.

Apply the same track to the dashboard's KPI grid in `resources/views/livewire/dashboard.blade.php` so the two do not disagree.

- [ ] **Step 4: Run the test again**

```bash
DB_DATABASE=opeschool_scratch_p3 "$PHP" artisan test tests/Feature/Ui/KpiToneTest.php
```

Expected: all pass.

- [ ] **Step 5: Look at it at both widths**

Resize to 1440×900, screenshot `/users` — expected: the single card is ~370px, not 1133px. Resize to **375×812**, reload, screenshot `/students`, `/staff`, `/visitors` — expected: **both** cards fully visible, no horizontal scrollbar under the strip. This is the finding that measurements pass and eyes catch; look at the images.

- [ ] **Step 6: Commit**

```bash
git add resources/views/components/list-screen.blade.php resources/views/livewire/dashboard.blade.php tests/Feature/Ui/KpiToneTest.php
git commit -m "fix(ui): cap the KPI track and stop the strip clipping at 375px"
```

---

# Phase 4 — The school's document identity

Scratch DB: `opeschool_scratch_p4`.

**Do not start this phase until Phase 2 is committed.** `school_document_profiles` is read by `RenderDocument::captureSchoolChrome()` for **every** printed document, and until Phase 2 landed, saving this screen stranded every report card in the database. Phase 2's second test (`reprints a report card after the school profile is edited`) exists precisely to guard this ordering.

`school_document_profiles` has **0 rows** and **nothing in the application writes it**. It holds the logo, crest, address, PO box, phone, e-mail, ministry headers, principal/registrar signatures, stamp and the bilingual flag. `/settings/branding`, the only screen with "branding" in its name, is a single hex colour picker over `branding.primary_color` and touches none of it. This is why every document renders bare.

### Task 15: The write action

**Files:**
- Create: `app/Modules/SchoolProfile/Actions/SaveDocumentProfile.php`
- Create: `tests/Feature/SchoolProfile/DocumentProfileTest.php`

- [ ] **Step 1: Read the table's shape first**

```bash
grep -n "table->" database/migrations/2026_08_09_310007_add_document_fields_to_school_profile.php database/migrations/2026_08_11_500001_add_letterhead_contacts_to_school_document_profile.php
```

Every column named there is a field this action must accept. `RenderDocument::captureSchoolChrome()` (`app/Modules/Reporting/Actions/RenderDocument.php:599-667`) is the authoritative list of what is actually *read*.

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/SchoolProfile/DocumentProfileTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\SchoolProfile\Actions\SaveDocumentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Reporting/P13MoneyHelpers.php';

uses(RefreshDatabase::class);

it('creates the singleton profile row on first save', function (): void {
    $user = p13moneyUserAs(Role::Administrator);

    app(SaveDocumentProfile::class)->handle([
        'address_line1' => 'BP 4000, Rue Manga Bell',
        'city' => 'Douala',
        'region' => 'Littoral',
        'phone' => '+237 233 000 000',
        'email' => 'contact@hopeacademy.cm',
        'state_header_enabled' => true,
        'ministry_en' => 'Ministry of Secondary Education',
        'ministry_fr' => 'Ministère des Enseignements Secondaires',
        'bilingual_documents' => true,
        'default_document_language' => 'en',
    ], $user->toAuditActor());

    $row = DB::table('school_document_profiles')->where('id', 1)->first();

    expect($row)->not->toBeNull();
    expect($row->city)->toBe('Douala');
    expect((bool) $row->state_header_enabled)->toBeTrue();
});

it('updates the same singleton row on a second save, never a second row', function (): void {
    $user = p13moneyUserAs(Role::Administrator);

    app(SaveDocumentProfile::class)->handle(['city' => 'Douala'], $user->toAuditActor());
    app(SaveDocumentProfile::class)->handle(['city' => 'Yaoundé'], $user->toAuditActor());

    expect(DB::table('school_document_profiles')->count())->toBe(1);
    expect(DB::table('school_document_profiles')->where('id', 1)->value('city'))->toBe('Yaoundé');
});

it('refuses an email that is not one', function (): void {
    $user = p13moneyUserAs(Role::Administrator);

    expect(fn () => app(SaveDocumentProfile::class)->handle(['email' => 'not-an-email'], $user->toAuditActor()))
        ->toThrow(ValidationException::class);
});

it('refuses a state header switched on with no ministry named', function (): void {
    $user = p13moneyUserAs(Role::Administrator);

    expect(fn () => app(SaveDocumentProfile::class)->handle([
        'state_header_enabled' => true,
        'ministry_en' => '',
        'ministry_fr' => '',
    ], $user->toAuditActor()))->toThrow(ValidationException::class);
});
```

- [ ] **Step 3: Run it and watch the class not exist**

```bash
"C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe" -uroot -e "CREATE DATABASE IF NOT EXISTS opeschool_scratch_p4"
PHP='C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe'
DB_DATABASE=opeschool_scratch_p4 "$PHP" artisan test tests/Feature/SchoolProfile/DocumentProfileTest.php
```

Expected: FAIL — `Target class [App\Modules\SchoolProfile\Actions\SaveDocumentProfile] does not exist.`

- [ ] **Step 4: Write the action**

Create `app/Modules/SchoolProfile/Actions/SaveDocumentProfile.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\SchoolProfile\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * The ONLY writer of `school_document_profiles` - the row
 * RenderDocument::captureSchoolChrome() reads for EVERY printed document
 * (letterhead, crest, address, ministry headers, signatures, stamp, the
 * bilingual flag). The table shipped with 0 rows and no UI, which is why
 * every document in the product renders bare.
 *
 * A singleton by design, exactly like `fiscal_identities`: this is a
 * single-tenant-per-deployment platform, so `id = 1` is the school, and an
 * upsert on that key is the whole storage contract.
 *
 * Every field is nullable. 00-core §16: a school that has not filled these
 * in gets a letterhead WITHOUT them, never a guessed or seeded one - a wrong
 * ministry header on a statutory document is worse than none.
 */
final class SaveDocumentProfile
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(array $input, Actor $actor): void
    {
        Gate::authorize(Permission::SettingEdit->value);

        $data = Validator::make($input, [
            'address_line1' => ['nullable', 'string', 'max:160'],
            'address_line2' => ['nullable', 'string', 'max:160'],
            'city' => ['nullable', 'string', 'max:80'],
            'region' => ['nullable', 'string', 'max:80'],
            'po_box' => ['nullable', 'string', 'max:40'],
            'phone' => ['nullable', 'string', 'max:60'],
            'phone_alt' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:190'],
            'website' => ['nullable', 'string', 'max:190'],
            'authorisation_line' => ['nullable', 'string', 'max:200'],
            'state_header_enabled' => ['nullable', 'boolean'],
            // Required WHEN the header is on: a state header is the block that
            // makes a document look official, and printing an empty one is a
            // worse claim than printing none.
            'ministry_en' => ['nullable', 'string', 'max:190', 'required_if:state_header_enabled,true,1'],
            'ministry_fr' => ['nullable', 'string', 'max:190', 'required_if:state_header_enabled,true,1'],
            'regional_delegation_en' => ['nullable', 'string', 'max:190'],
            'regional_delegation_fr' => ['nullable', 'string', 'max:190'],
            'divisional_delegation_en' => ['nullable', 'string', 'max:190'],
            'divisional_delegation_fr' => ['nullable', 'string', 'max:190'],
            'bilingual_documents' => ['nullable', 'boolean'],
            'default_document_language' => ['nullable', 'in:en,fr'],
            'crest_path' => ['nullable', 'string', 'max:255'],
            'logo_path' => ['nullable', 'string', 'max:255'],
            'principal_signature_path' => ['nullable', 'string', 'max:255'],
            'registrar_signature_path' => ['nullable', 'string', 'max:255'],
            'school_stamp_path' => ['nullable', 'string', 'max:255'],
        ])->validate();

        DB::transaction(function () use ($data, $actor): void {
            $existing = DB::table('school_document_profiles')->where('id', 1)->first();

            DB::table('school_document_profiles')->updateOrInsert(
                ['id' => 1],
                array_merge($data, [
                    'updated_at' => now(),
                    'created_at' => $existing === null ? now() : $existing->created_at,
                ]),
            );

            // Auditable because it changes what EVERY printed document says
            // about the institution issuing it (00-core §14).
            $this->audit->handle(
                action: 'school_document_profile.saved',
                subjectType: 'SchoolDocumentProfile',
                subjectId: 1,
                actor: $actor,
                before: $existing === null ? [] : (array) $existing,
                after: $data,
            );
        });
    }
}
```

If `WriteAuditEntry::handle()`'s signature differs, read `app/Modules/Identity/Actions/WriteAuditEntry.php` and match it exactly — do not change the audit contract to suit this caller.

- [ ] **Step 5: Run the test again**

```bash
DB_DATABASE=opeschool_scratch_p4 "$PHP" artisan test tests/Feature/SchoolProfile/DocumentProfileTest.php
```

Expected: `Tests:  4 passed`.

- [ ] **Step 6: Static analysis, then commit**

```bash
"$PHP" vendor/bin/phpstan analyse --memory-limit=1G
git add app/Modules/SchoolProfile/Actions/SaveDocumentProfile.php tests/Feature/SchoolProfile/DocumentProfileTest.php
git commit -m "feat(schoolprofile): add the only writer of school_document_profiles"
```

---

### Task 16: The `/settings/school-identity` screen

**Files:**
- Create: `app/Modules/SchoolProfile/Livewire/DocumentProfile.php`
- Create: `resources/views/livewire/schoolprofile/document-profile.blade.php`
- Modify: `routes/web.php` (beside `settings.branding`, ~line 501)
- Modify: `lang/en/opes.php`, `lang/fr/opes.php`
- Modify: `tests/Feature/SchoolProfile/DocumentProfileTest.php`

- [ ] **Step 1: Add the failing screen test**

Append to `tests/Feature/SchoolProfile/DocumentProfileTest.php`:

```php
it('loads the school identity screen and saves it', function (): void {
    $user = p13moneyUserAs(Role::Administrator);

    Livewire::actingAs($user)
        ->test(App\Modules\SchoolProfile\Livewire\DocumentProfile::class)
        ->set('city', 'Bafoussam')
        ->set('phone', '+237 233 111 111')
        ->call('save')
        ->assertHasNoErrors();

    expect(DB::table('school_document_profiles')->where('id', 1)->value('city'))->toBe('Bafoussam');
});

it('answers 200 at /settings/school-identity', function (): void {
    $this->actingAs(p13moneyUserAs(Role::Administrator))
        ->get('/settings/school-identity')
        ->assertOk();
});
```

- [ ] **Step 2: Run it and watch both fail**

```bash
DB_DATABASE=opeschool_scratch_p4 "$PHP" artisan test tests/Feature/SchoolProfile/DocumentProfileTest.php
```

Expected: FAIL — class missing, route 404.

- [ ] **Step 3: Write the component**

Create `app/Modules/SchoolProfile/Livewire/DocumentProfile.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\SchoolProfile\Livewire;

use App\Modules\Identity\Domain\Permission;
use App\Modules\SchoolProfile\Actions\SaveDocumentProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /settings/school-identity - the letterhead every printed document wears.
 *
 * `school_document_profiles` shipped with 0 rows and no screen that writes
 * it, so every invoice, receipt, bulletin and attestation rendered without a
 * crest, an address, a phone number or a ministry header. That is not a
 * cosmetic gap: 10-documents §4.7's school_header block promises those
 * fields and silently dropped the middle third of the letterhead.
 *
 * Signature and crest PATHS only, no upload widget: the paths point at
 * storage the deployment already manages, and an upload flow is a separate
 * slice with its own validation and virus-scanning questions.
 */
#[Layout('layouts.app')]
final class DocumentProfile extends Component
{
    public string $addressLine1 = '';

    public string $addressLine2 = '';

    public string $city = '';

    public string $region = '';

    public string $poBox = '';

    public string $phone = '';

    public string $phoneAlt = '';

    public string $email = '';

    public string $website = '';

    public string $authorisationLine = '';

    public bool $stateHeaderEnabled = false;

    public string $ministryEn = '';

    public string $ministryFr = '';

    public string $regionalDelegationEn = '';

    public string $regionalDelegationFr = '';

    public string $divisionalDelegationEn = '';

    public string $divisionalDelegationFr = '';

    public bool $bilingualDocuments = false;

    public string $defaultDocumentLanguage = 'en';

    public string $crestPath = '';

    public string $logoPath = '';

    public string $principalSignaturePath = '';

    public string $registrarSignaturePath = '';

    public string $schoolStampPath = '';

    public function mount(): void
    {
        Gate::authorize(Permission::SettingEdit->value);

        $row = DB::table('school_document_profiles')->where('id', 1)->first();

        if ($row === null) {
            return;
        }

        $this->addressLine1 = (string) ($row->address_line1 ?? '');
        $this->addressLine2 = (string) ($row->address_line2 ?? '');
        $this->city = (string) ($row->city ?? '');
        $this->region = (string) ($row->region ?? '');
        $this->poBox = (string) ($row->po_box ?? '');
        $this->phone = (string) ($row->phone ?? '');
        $this->phoneAlt = (string) ($row->phone_alt ?? '');
        $this->email = (string) ($row->email ?? '');
        $this->website = (string) ($row->website ?? '');
        $this->authorisationLine = (string) ($row->authorisation_line ?? '');
        $this->stateHeaderEnabled = (bool) $row->state_header_enabled;
        $this->ministryEn = (string) ($row->ministry_en ?? '');
        $this->ministryFr = (string) ($row->ministry_fr ?? '');
        $this->regionalDelegationEn = (string) ($row->regional_delegation_en ?? '');
        $this->regionalDelegationFr = (string) ($row->regional_delegation_fr ?? '');
        $this->divisionalDelegationEn = (string) ($row->divisional_delegation_en ?? '');
        $this->divisionalDelegationFr = (string) ($row->divisional_delegation_fr ?? '');
        $this->bilingualDocuments = (bool) $row->bilingual_documents;
        $this->defaultDocumentLanguage = (string) ($row->default_document_language ?? 'en');
        $this->crestPath = (string) ($row->crest_path ?? '');
        $this->logoPath = (string) ($row->logo_path ?? '');
        $this->principalSignaturePath = (string) ($row->principal_signature_path ?? '');
        $this->registrarSignaturePath = (string) ($row->registrar_signature_path ?? '');
        $this->schoolStampPath = (string) ($row->school_stamp_path ?? '');
    }

    public function save(SaveDocumentProfile $save): void
    {
        $this->resetErrorBag();

        /** @var \App\Modules\Identity\Models\User $user */
        $user = auth()->user();

        try {
            $save->handle([
                'address_line1' => $this->addressLine1 ?: null,
                'address_line2' => $this->addressLine2 ?: null,
                'city' => $this->city ?: null,
                'region' => $this->region ?: null,
                'po_box' => $this->poBox ?: null,
                'phone' => $this->phone ?: null,
                'phone_alt' => $this->phoneAlt ?: null,
                'email' => $this->email ?: null,
                'website' => $this->website ?: null,
                'authorisation_line' => $this->authorisationLine ?: null,
                'state_header_enabled' => $this->stateHeaderEnabled,
                'ministry_en' => $this->ministryEn ?: null,
                'ministry_fr' => $this->ministryFr ?: null,
                'regional_delegation_en' => $this->regionalDelegationEn ?: null,
                'regional_delegation_fr' => $this->regionalDelegationFr ?: null,
                'divisional_delegation_en' => $this->divisionalDelegationEn ?: null,
                'divisional_delegation_fr' => $this->divisionalDelegationFr ?: null,
                'bilingual_documents' => $this->bilingualDocuments,
                'default_document_language' => $this->defaultDocumentLanguage,
                'crest_path' => $this->crestPath ?: null,
                'logo_path' => $this->logoPath ?: null,
                'principal_signature_path' => $this->principalSignaturePath ?: null,
                'registrar_signature_path' => $this->registrarSignaturePath ?: null,
                'school_stamp_path' => $this->schoolStampPath ?: null,
            ], $user->toAuditActor());
        } catch (ValidationException $e) {
            foreach ($e->validator->errors()->messages() as $field => $messages) {
                $this->addError((string) $field, (string) ($messages[0] ?? ''));
            }

            return;
        }

        session()->flash('status', __('opes.school_identity.saved'));
    }

    public function render(): mixed
    {
        return view('livewire.schoolprofile.document-profile');
    }
}
```

- [ ] **Step 4: Write the view**

Create `resources/views/livewire/schoolprofile/document-profile.blade.php`:

```blade
<div class="space-y-6">
    <x-page-header :title="__('opes.school_identity.title')"
                   :subtitle="__('opes.school_identity.subtitle')"/>

    @if (session('status'))
        <div class="rounded-xl border border-primary/30 bg-kpi-green px-4 py-3 text-sm text-primary">
            {{ session('status') }}
        </div>
    @endif

    <form wire:submit="save" class="space-y-6">
        <section class="rounded-xl border border-border-primary bg-white p-5">
            <h2 class="mb-4 text-xs font-semibold uppercase tracking-wide text-charcoal/55">
                {{ __('opes.school_identity.contacts') }}
            </h2>
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ([
                    'addressLine1' => 'address_line1', 'addressLine2' => 'address_line2',
                    'city' => 'city', 'region' => 'region', 'poBox' => 'po_box',
                    'phone' => 'phone', 'phoneAlt' => 'phone_alt', 'email' => 'email',
                    'website' => 'website', 'authorisationLine' => 'authorisation_line',
                ] as $model => $key)
                    <label class="block text-sm">
                        <span class="mb-1 block font-medium text-charcoal">{{ __('opes.school_identity.'.$key) }}</span>
                        <input type="text" wire:model="{{ $model }}" class="w-full">
                        @error($key) <span class="mt-1 block text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>
                @endforeach
            </div>
        </section>

        <section class="rounded-xl border border-border-primary bg-white p-5">
            <h2 class="mb-4 text-xs font-semibold uppercase tracking-wide text-charcoal/55">
                {{ __('opes.school_identity.state_header') }}
            </h2>
            <label class="mb-4 flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model.live="stateHeaderEnabled">
                <span>{{ __('opes.school_identity.state_header_enabled') }}</span>
            </label>
            @if ($stateHeaderEnabled)
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ([
                        'ministryEn' => 'ministry_en', 'ministryFr' => 'ministry_fr',
                        'regionalDelegationEn' => 'regional_delegation_en',
                        'regionalDelegationFr' => 'regional_delegation_fr',
                        'divisionalDelegationEn' => 'divisional_delegation_en',
                        'divisionalDelegationFr' => 'divisional_delegation_fr',
                    ] as $model => $key)
                        <label class="block text-sm">
                            <span class="mb-1 block font-medium text-charcoal">{{ __('opes.school_identity.'.$key) }}</span>
                            <input type="text" wire:model="{{ $model }}" class="w-full">
                            @error($key) <span class="mt-1 block text-xs text-heritage-red">{{ $message }}</span> @enderror
                        </label>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="rounded-xl border border-border-primary bg-white p-5">
            <h2 class="mb-4 text-xs font-semibold uppercase tracking-wide text-charcoal/55">
                {{ __('opes.school_identity.marks') }}
            </h2>
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ([
                    'logoPath' => 'logo_path', 'crestPath' => 'crest_path',
                    'principalSignaturePath' => 'principal_signature_path',
                    'registrarSignaturePath' => 'registrar_signature_path',
                    'schoolStampPath' => 'school_stamp_path',
                ] as $model => $key)
                    <label class="block text-sm">
                        <span class="mb-1 block font-medium text-charcoal">{{ __('opes.school_identity.'.$key) }}</span>
                        <input type="text" wire:model="{{ $model }}" class="w-full">
                        @error($key) <span class="mt-1 block text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>
                @endforeach
                <label class="block text-sm">
                    <span class="mb-1 block font-medium text-charcoal">{{ __('opes.school_identity.default_document_language') }}</span>
                    <select wire:model="defaultDocumentLanguage" class="w-full">
                        <option value="en">English</option>
                        <option value="fr">Français</option>
                    </select>
                </label>
                <label class="flex items-center gap-2 self-end text-sm">
                    <input type="checkbox" wire:model="bilingualDocuments">
                    <span>{{ __('opes.school_identity.bilingual_documents') }}</span>
                </label>
            </div>
        </section>

        <div class="flex items-center gap-3">
            <button type="submit" class="rounded-lg border border-primary bg-primary px-4 py-2 text-sm font-medium text-white transition hover:bg-primary/90">
                {{ __('opes.ui.save') }}
            </button>
            <p class="text-xs text-charcoal/55">{{ __('opes.school_identity.blank_means_omitted') }}</p>
        </div>
    </form>
</div>
```

If `x-page-header` does not exist under that name, use whatever the neighbouring `resources/views/livewire/schoolprofile/branding.blade.php` uses — do not invent a component.

- [ ] **Step 5: Route it**

In `routes/web.php`, immediately after the `settings.branding` route (~line 501):

```php
    /**
     * The school's DOCUMENT identity - letterhead contacts, ministry state
     * header, crest/logo/signature paths. RenderDocument reads this row for
     * every printed document; before this screen existed the table had zero
     * rows and no writer, so every document printed bare. `setting.edit`,
     * matching /settings/branding beside it.
     */
    Route::get('/settings/school-identity', \App\Modules\SchoolProfile\Livewire\DocumentProfile::class)
        ->middleware('can:setting.edit')->name('settings.school-identity');
```

- [ ] **Step 6: Add the strings to BOTH language files**

In `lang/en/opes.php`, add a `school_identity` block with keys: `title`, `subtitle`, `contacts`, `state_header`, `state_header_enabled`, `marks`, `saved`, `blank_means_omitted`, plus one key per field name used above (`address_line1`, `address_line2`, `city`, `region`, `po_box`, `phone`, `phone_alt`, `email`, `website`, `authorisation_line`, `ministry_en`, `ministry_fr`, `regional_delegation_en`, `regional_delegation_fr`, `divisional_delegation_en`, `divisional_delegation_fr`, `logo_path`, `crest_path`, `principal_signature_path`, `registrar_signature_path`, `school_stamp_path`, `default_document_language`, `bilingual_documents`). Mirror every key in `lang/fr/opes.php`. Verify:

```bash
DB_DATABASE=opeschool_scratch_p4 "$PHP" artisan test tests/Feature/LocalisationTest.php
```

Expected: pass — that suite is what enforces EN/FR key parity.

- [ ] **Step 7: Run the screen tests**

```bash
DB_DATABASE=opeschool_scratch_p4 "$PHP" artisan test tests/Feature/SchoolProfile/DocumentProfileTest.php
```

Expected: `Tests:  6 passed`.

- [ ] **Step 8: Look at it, then print a document**

Screenshot `/settings/school-identity` at 1440×900 and 375×812. Then fill it in live, save, and print any invoice — expected: the letterhead now carries the address and phone number it previously dropped. **Then reprint an already-issued report card** and confirm it still succeeds; that is Phase 2's guarantee under real load.

- [ ] **Step 9: Commit**

```bash
git add app/Modules/SchoolProfile/Livewire/DocumentProfile.php resources/views/livewire/schoolprofile/document-profile.blade.php routes/web.php lang/en/opes.php lang/fr/opes.php tests/Feature/SchoolProfile/DocumentProfileTest.php
git commit -m "feat(schoolprofile): ship the school identity screen that writes the document letterhead"
```

---

### Task 17: Surface the unconfirmed fiscal identity as a blocker with a route to fix it

`fiscal_identities` row 1 is `niu: "SPECIMEN0000A"` with `fiscal_identity_confirmed_at = NULL`, and `/settings/fiscal-identity` says in terms that *"printing invoices, receipts and attestations is blocked until [the NIU is] confirmed"*. That screen has **zero inbound links**. Every money document in the product is therefore watermarked SPECIMEN with no signposted way out.

**Files:**
- Modify: `resources/views/livewire/schoolprofile/document-profile.blade.php`
- Modify: `app/Modules/SchoolProfile/Livewire/DocumentProfile.php`
- Modify: `tests/Feature/SchoolProfile/DocumentProfileTest.php`

- [ ] **Step 1: Add the failing test**

Append to `tests/Feature/SchoolProfile/DocumentProfileTest.php`:

```php
it('warns about an unconfirmed fiscal identity and links the screen that confirms it', function (): void {
    DB::table('fiscal_identities')->updateOrInsert(['id' => 1], [
        'legal_name' => 'Hope Academy', 'niu' => 'SPECIMEN0000A',
        'fiscal_identity_confirmed_at' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Livewire::actingAs(p13moneyUserAs(Role::Administrator))
        ->test(App\Modules\SchoolProfile\Livewire\DocumentProfile::class)
        ->assertSee('/settings/fiscal-identity', escape: false);
});
```

- [ ] **Step 2: Run it and watch it fail**

```bash
DB_DATABASE=opeschool_scratch_p4 "$PHP" artisan test --filter="unconfirmed fiscal identity"
```

Expected: FAIL.

- [ ] **Step 3: Compute the state and render the banner**

Add to `app/Modules/SchoolProfile/Livewire/DocumentProfile.php`:

```php
    /**
     * The go-live blocker nobody could find: the shipped fiscal identity is a
     * SPECIMEN, so every money document carries the SPECIMEN watermark - and
     * /settings/fiscal-identity, the screen that clears it, had zero inbound
     * links anywhere in the product.
     */
    public function fiscalIdentityIsProvisional(): bool
    {
        $row = DB::table('fiscal_identities')->where('id', 1)->first();

        return $row === null || $row->fiscal_identity_confirmed_at === null;
    }
```

and pass it to the view:

```php
        return view('livewire.schoolprofile.document-profile', [
            'fiscalProvisional' => $this->fiscalIdentityIsProvisional(),
        ]);
```

At the top of `resources/views/livewire/schoolprofile/document-profile.blade.php`, under the header:

```blade
    @if ($fiscalProvisional)
        <div class="rounded-xl border border-heritage-yellow/60 bg-heritage-yellow/15 px-4 py-3 text-sm text-charcoal">
            <p class="font-semibold">{{ __('opes.school_identity.fiscal_provisional_title') }}</p>
            <p class="mt-1">{{ __('opes.school_identity.fiscal_provisional_body') }}</p>
            <a href="{{ route('tax.fiscal-identity') }}" class="mt-2 inline-block font-medium text-primary hover:underline">
                {{ __('opes.school_identity.fiscal_provisional_action') }}
            </a>
        </div>
    @endif
```

Add the three keys to both language files.

- [ ] **Step 4: Run the test again**

```bash
DB_DATABASE=opeschool_scratch_p4 "$PHP" artisan test tests/Feature/SchoolProfile/DocumentProfileTest.php tests/Feature/LocalisationTest.php
```

Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/SchoolProfile/Livewire/DocumentProfile.php resources/views/livewire/schoolprofile/document-profile.blade.php lang/en/opes.php lang/fr/opes.php tests/Feature/SchoolProfile/DocumentProfileTest.php
git commit -m "feat(schoolprofile): surface the SPECIMEN fiscal identity and link the screen that clears it"
```

---

# Phase 5 — The account screen and the settings hub

Scratch DB: `opeschool_scratch_p5`.

There is **no** `/profile`, `/account` or `/me` in the staff shell. The avatar menu holds "Signed in as", an EN/FR toggle and Sign out. A teacher, accountant or registrar cannot view their own record or change their own password; only an Administrator can, from `/users`. The guardian portal ships five account screens. Separately, `/settings` is a 3-row key/value browser that renders **zero links**, and the header gear is a permanently inert `<span>` behind a stale comment claiming the route does not exist.

### Task 18: `/account` — own profile and own password

**Files:**
- Create: `app/Modules/Identity/Livewire/Account.php`
- Create: `resources/views/livewire/identity/account.blade.php`
- Modify: `routes/web.php`
- Modify: `lang/en/opes.php`, `lang/fr/opes.php`
- Create: `tests/Feature/Identity/AccountScreenTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Identity/AccountScreenTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Livewire\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require_once __DIR__.'/../Reporting/P13MoneyHelpers.php';

uses(RefreshDatabase::class);

it('is reachable by a teacher, who holds no administrative permission', function (): void {
    $this->actingAs(p13moneyUserAs(Role::Teacher))->get('/account')->assertOk();
});

it('lets a teacher change their own password', function (): void {
    $user = p13moneyUserAs(Role::Teacher);
    DB::table('users')->where('id', $user->id)->update(['password' => Hash::make('old-password-1')]);

    Livewire::actingAs($user)
        ->test(Account::class)
        ->set('currentPassword', 'old-password-1')
        ->set('newPassword', 'new-password-2')
        ->set('newPasswordConfirmation', 'new-password-2')
        ->call('changePassword')
        ->assertHasNoErrors();

    $hash = (string) DB::table('users')->where('id', $user->id)->value('password');
    expect(Hash::check('new-password-2', $hash))->toBeTrue();
});

it('refuses a password change that gets the current password wrong', function (): void {
    $user = p13moneyUserAs(Role::Teacher);
    DB::table('users')->where('id', $user->id)->update(['password' => Hash::make('old-password-1')]);

    Livewire::actingAs($user)
        ->test(Account::class)
        ->set('currentPassword', 'not-it')
        ->set('newPassword', 'new-password-2')
        ->set('newPasswordConfirmation', 'new-password-2')
        ->call('changePassword')
        ->assertHasErrors('currentPassword');
});

it('refuses a new password under eight characters', function (): void {
    $user = p13moneyUserAs(Role::Teacher);
    DB::table('users')->where('id', $user->id)->update(['password' => Hash::make('old-password-1')]);

    Livewire::actingAs($user)
        ->test(Account::class)
        ->set('currentPassword', 'old-password-1')
        ->set('newPassword', 'short')
        ->set('newPasswordConfirmation', 'short')
        ->call('changePassword')
        ->assertHasErrors('newPassword');
});
```

- [ ] **Step 2: Run it and watch every case fail**

```bash
"C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe" -uroot -e "CREATE DATABASE IF NOT EXISTS opeschool_scratch_p5"
PHP='C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe'
DB_DATABASE=opeschool_scratch_p5 "$PHP" artisan test tests/Feature/Identity/AccountScreenTest.php
```

Expected: 4 failures — route 404, class missing.

- [ ] **Step 3: Write the component**

Create `app/Modules/Identity/Livewire/Account.php`. The password logic mirrors the existing house pattern in `app/Modules/HR/Livewire/Portal/Show.php:45-81` exactly — same checks, same order, same query-builder write:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Livewire;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /account - the staff shell's own-account screen.
 *
 * There was none. A teacher, accountant or registrar could not see their own
 * record or change their own password; only an Administrator could, from
 * /users. The guardian portal shipped five account screens and the staff
 * shell shipped zero, which is the sharpest self-service gap in the product.
 *
 * NO permission gate: this is the authenticated user acting on themselves.
 * It is deliberately NOT Identity\Actions\SetUserPassword, which is the
 * ADMIN-resets-SOMEONE-ELSE's-password door and rightly gates on
 * `user.set_password`. Same reasoning, same shape, as the staff portal's
 * changePassword (HR\Livewire\Portal\Show).
 */
#[Layout('layouts.app')]
final class Account extends Component
{
    public string $name = '';

    public string $email = '';

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public function mount(): void
    {
        $user = auth()->user();

        if ($user === null) {
            abort(403);
        }

        $this->name = (string) $user->name;
        $this->email = (string) $user->email;
    }

    public function changePassword(): void
    {
        $userId = auth()->id();

        if ($userId === null) {
            abort(403);
        }

        $hash = DB::table('users')->where('id', $userId)->value('password');

        if (! is_string($hash) || ! Hash::check($this->currentPassword, $hash)) {
            throw ValidationException::withMessages([
                'currentPassword' => __('opes.account.password_current_wrong'),
            ]);
        }

        if ($this->newPassword === '' || $this->newPassword !== $this->newPasswordConfirmation) {
            throw ValidationException::withMessages([
                'newPasswordConfirmation' => __('opes.account.password_mismatch'),
            ]);
        }

        if (strlen($this->newPassword) < 8) {
            throw ValidationException::withMessages([
                'newPassword' => __('opes.account.password_too_short'),
            ]);
        }

        DB::table('users')->where('id', $userId)->update([
            'password' => Hash::make($this->newPassword),
            // A user who has just chosen their own password is no longer
            // under a forced change.
            'must_change_password_at' => null,
            'updated_at' => now(),
        ]);

        $this->reset(['currentPassword', 'newPassword', 'newPasswordConfirmation']);

        session()->flash('status', __('opes.account.password_changed'));
    }

    /**
     * @return list<string>
     */
    public function roleLabels(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        /** @var list<string> $names */
        $names = $user->getRoleNames()->all();

        return $names;
    }

    public function render(): mixed
    {
        return view('livewire.identity.account', [
            'roles' => $this->roleLabels(),
        ]);
    }
}
```

- [ ] **Step 4: Write the view**

Create `resources/views/livewire/identity/account.blade.php`:

```blade
<div class="space-y-6">
    <x-page-header :title="__('opes.account.title')" :subtitle="__('opes.account.subtitle')"/>

    @if (session('status'))
        <div class="rounded-xl border border-primary/30 bg-kpi-green px-4 py-3 text-sm text-primary">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded-xl border border-border-primary bg-white p-5">
            <h2 class="mb-4 text-xs font-semibold uppercase tracking-wide text-charcoal/55">
                {{ __('opes.account.who_you_are') }}
            </h2>
            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="text-charcoal/55">{{ __('opes.account.name') }}</dt>
                    <dd class="font-medium text-charcoal">{{ $name }}</dd>
                </div>
                <div>
                    <dt class="text-charcoal/55">{{ __('opes.account.email') }}</dt>
                    <dd class="font-medium text-charcoal">{{ $email }}</dd>
                </div>
                <div>
                    <dt class="text-charcoal/55">{{ __('opes.account.roles') }}</dt>
                    <dd class="mt-1 flex flex-wrap gap-1.5">
                        @foreach ($roles as $role)
                            <x-status-pill :label="__('opes.roles.'.$role)"/>
                        @endforeach
                    </dd>
                </div>
            </dl>
            <p class="mt-4 text-xs text-charcoal/55">{{ __('opes.account.name_changes_via_admin') }}</p>
        </section>

        <section class="rounded-xl border border-border-primary bg-white p-5">
            <h2 class="mb-4 text-xs font-semibold uppercase tracking-wide text-charcoal/55">
                {{ __('opes.account.change_password') }}
            </h2>
            <form wire:submit="changePassword" class="space-y-4">
                <label class="block text-sm">
                    <span class="mb-1 block font-medium text-charcoal">{{ __('opes.account.current_password') }}</span>
                    <input type="password" wire:model="currentPassword" autocomplete="current-password" class="w-full">
                    @error('currentPassword') <span class="mt-1 block text-xs text-heritage-red">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm">
                    <span class="mb-1 block font-medium text-charcoal">{{ __('opes.account.new_password') }}</span>
                    <input type="password" wire:model="newPassword" autocomplete="new-password" class="w-full">
                    @error('newPassword') <span class="mt-1 block text-xs text-heritage-red">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm">
                    <span class="mb-1 block font-medium text-charcoal">{{ __('opes.account.confirm_new_password') }}</span>
                    <input type="password" wire:model="newPasswordConfirmation" autocomplete="new-password" class="w-full">
                    @error('newPasswordConfirmation') <span class="mt-1 block text-xs text-heritage-red">{{ $message }}</span> @enderror
                </label>
                <button type="submit" class="rounded-lg border border-primary bg-primary px-4 py-2 text-sm font-medium text-white transition hover:bg-primary/90">
                    {{ __('opes.account.change_password') }}
                </button>
            </form>
        </section>
    </div>
</div>
```

If `opes.roles.*` keys do not exist, render `{{ $role }}` through the existing role-label helper used by `/users` — do not invent a second label map.

- [ ] **Step 5: Route it**

In `routes/web.php`, inside the authenticated web shell group:

```php
    /**
     * The staff shell's own-account screen. NO `can:` middleware by design:
     * every authenticated user may read their own record and set their own
     * password, and gating it on any permission would reproduce the gap it
     * exists to close - a teacher unable to change their own password.
     */
    Route::get('/account', \App\Modules\Identity\Livewire\Account::class)->name('account.index');
```

- [ ] **Step 6: Add the strings to both language files**

Keys under `account`: `title`, `subtitle`, `who_you_are`, `name`, `email`, `roles`, `name_changes_via_admin`, `change_password`, `current_password`, `new_password`, `confirm_new_password`, `password_current_wrong`, `password_mismatch`, `password_too_short`, `password_changed`. Mirror in `lang/fr/opes.php`.

- [ ] **Step 7: Run the tests**

```bash
DB_DATABASE=opeschool_scratch_p5 "$PHP" artisan test tests/Feature/Identity/AccountScreenTest.php tests/Feature/LocalisationTest.php
```

Expected: `Tests:  4 passed` plus localisation green.

- [ ] **Step 8: Commit**

```bash
git add app/Modules/Identity/Livewire/Account.php resources/views/livewire/identity/account.blade.php routes/web.php lang/en/opes.php lang/fr/opes.php tests/Feature/Identity/AccountScreenTest.php
git commit -m "feat(identity): ship /account so staff can read their own record and set their own password"
```

---

### Task 19: Link `/account` from the avatar menu and wake the header gear

**Files:**
- Modify: `resources/views/layouts/app.blade.php:255-315`
- Modify: `tests/Feature/Identity/AccountScreenTest.php`

- [ ] **Step 1: Add the failing test**

Append to `tests/Feature/Identity/AccountScreenTest.php`:

```php
it('offers the account link and a live settings gear in the shell', function (): void {
    $response = $this->actingAs(p13moneyUserAs(Role::Administrator))->get('/dashboard');

    $response->assertOk();
    $response->assertSee('href="/account"', escape: false);
    $response->assertSee('href="'.route('settings.index').'"', escape: false);
    $response->assertDontSee('Settings has no route yet', escape: false);
});
```

- [ ] **Step 2: Run it and watch it fail**

```bash
DB_DATABASE=opeschool_scratch_p5 "$PHP" artisan test --filter="settings gear"
```

Expected: FAIL.

- [ ] **Step 3: Add the account link to the dropdown**

In `resources/views/layouts/app.blade.php`, inside the avatar dropdown, immediately after the "Signed in as" paragraph and before the EN/FR group:

```blade
                        {{-- The staff shell had no own-account screen at all,
                             so this menu offered a name, a language toggle and
                             a way out. --}}
                        <a href="/account"
                           class="block border-t border-border-primary px-3 py-2 text-sm text-charcoal hover:bg-sand">
                            {{ __('opes.account.title') }}
                        </a>
```

- [ ] **Step 4: Make the gear a link**

Replace the inert `<span>` block (the comment plus the `<span title=… aria-disabled="true" …>` wrapper) with:

```blade
                {{-- /settings has existed since the wiring pass and is in the
                     sidebar; the comment that used to sit here claiming
                     otherwise outlived the route by several phases. --}}
                <a href="{{ route('settings.index') }}" title="{{ __('opes.nav.settings') }}"
                   class="hidden rounded-full p-2 text-charcoal/60 transition hover:bg-sand hover:text-primary lg:inline-flex">
```

keeping the existing `<svg>…</svg>` exactly as it is and closing with `</a>` instead of `</span>`.

- [ ] **Step 5: Run the test again**

```bash
DB_DATABASE=opeschool_scratch_p5 "$PHP" artisan test tests/Feature/Identity/AccountScreenTest.php
```

Expected: `Tests:  5 passed`.

- [ ] **Step 6: Look at it**

Screenshot `/dashboard` at 1440×900 with the avatar menu open. Expected: the gear reads as an enabled control (not the greyed cursor-not-allowed state) and the dropdown carries the account entry.

- [ ] **Step 7: Commit**

```bash
git add resources/views/layouts/app.blade.php tests/Feature/Identity/AccountScreenTest.php
git commit -m "fix(shell): link /account from the avatar menu and wake the inert settings gear"
```

---

### Task 20: Turn `/settings` into a hub that links the settings screens that exist

`/settings` renders a 3-row key/value grid over the `settings` table with **no links at all** — not to Branding, not to Tax, not to Academic settings. An administrator looking for the school address, logo, currency or document header finds `TOTAL SETTINGS 3`.

**Files:**
- Modify: `app/Modules/SchoolProfile/Livewire/Index.php`
- Create: `resources/views/livewire/schoolprofile/settings-hub.blade.php`
- Modify: `resources/views/livewire/schoolprofile/index.blade.php`
- Create: `tests/Feature/SchoolProfile/SettingsHubTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SchoolProfile/SettingsHubTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../Reporting/P13MoneyHelpers.php';

uses(RefreshDatabase::class);

it('links every settings screen an administrator is allowed to open', function (): void {
    $response = $this->actingAs(p13moneyUserAs(Role::Administrator))->get('/settings');

    $response->assertOk();

    foreach ([
        '/settings/school-identity',
        '/settings/branding',
        '/settings/tax',
        '/settings/fiscal-identity',
        '/academics/settings',
    ] as $href) {
        $response->assertSee('href="'.$href.'"', escape: false);
    }
});

it('hides a settings card the role may not open', function (): void {
    // Administrator deliberately lacks licence.manage - /settings/licence
    // returns 403 by design, so a card pointing at it would be a link the
    // nav-and-route-agree contract forbids.
    $this->actingAs(p13moneyUserAs(Role::Administrator))
        ->get('/settings')
        ->assertDontSee('href="/settings/licence"', escape: false);
});
```

- [ ] **Step 2: Run it and watch it fail**

```bash
DB_DATABASE=opeschool_scratch_p5 "$PHP" artisan test tests/Feature/SchoolProfile/SettingsHubTest.php
```

Expected: 1 failure (no links); the second passes vacuously and stays as a regression guard.

- [ ] **Step 3: Compute the hub cards**

Add to `app/Modules/SchoolProfile/Livewire/Index.php`:

```php
    /**
     * The settings HUB. /settings was a raw key/value browser over a 3-row
     * table that rendered no links at all, while six real settings screens
     * sat unreachable beside it - three of them with zero inbound links from
     * anywhere in the product.
     *
     * Gate-filtered, not Route::has()-filtered: the sidebar's contract is
     * that a link is only ever offered to a role whose permissions allow it,
     * and /settings/licence (403 for Administrator by design) is exactly the
     * case Route::has() would get wrong.
     *
     * @return list<array{href: string, title: string, body: string}>
     */
    public function hubCards(): array
    {
        $candidates = [
            ['href' => '/settings/school-identity', 'permission' => 'setting.edit', 'key' => 'school_identity'],
            ['href' => '/settings/branding', 'permission' => 'setting.edit', 'key' => 'branding'],
            ['href' => '/settings/tax', 'permission' => 'ledger.configure', 'key' => 'tax'],
            ['href' => '/settings/fiscal-identity', 'permission' => 'ledger.configure', 'key' => 'fiscal_identity'],
            ['href' => '/academics/settings', 'permission' => 'academics.manage', 'key' => 'academics'],
            ['href' => '/settings/licence', 'permission' => 'licence.manage', 'key' => 'licence'],
        ];

        $cards = [];

        foreach ($candidates as $candidate) {
            if (! Gate::allows($candidate['permission'])) {
                continue;
            }

            $cards[] = [
                'href' => $candidate['href'],
                'title' => (string) __('opes.settings_hub.'.$candidate['key'].'_title'),
                'body' => (string) __('opes.settings_hub.'.$candidate['key'].'_body'),
            ];
        }

        return $cards;
    }
```

Add `use Illuminate\Support\Facades\Gate;` and pass `'hubCards' => $this->hubCards()` into the existing `render()` view data.

- [ ] **Step 4: Render the cards above the existing table**

At the top of `resources/views/livewire/schoolprofile/index.blade.php`, before the existing list-screen block:

```blade
    <section class="mb-6">
        <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-charcoal/55">
            {{ __('opes.settings_hub.heading') }}
        </h2>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($hubCards as $card)
                <a href="{{ $card['href'] }}"
                   class="block rounded-xl border border-kpi-green-solid/15 bg-kpi-green p-4 transition hover:-translate-y-px hover:shadow-md">
                    <p class="text-sm font-semibold text-charcoal">{{ $card['title'] }}</p>
                    <p class="mt-1 text-xs text-charcoal/60">{{ $card['body'] }}</p>
                </a>
            @endforeach
        </div>
    </section>
```

Add the `settings_hub` keys (`heading` plus `*_title` / `*_body` for each of the six) to both language files.

- [ ] **Step 5: Run the tests**

```bash
DB_DATABASE=opeschool_scratch_p5 "$PHP" artisan test tests/Feature/SchoolProfile/SettingsHubTest.php tests/Feature/LocalisationTest.php
```

Expected: all pass.

- [ ] **Step 6: Look at it**

Screenshot `/settings` at 1440×900 and 375×812. Expected: a tinted card grid above the key/value table, no flat white rectangles.

- [ ] **Step 7: Commit**

```bash
git add app/Modules/SchoolProfile/Livewire/Index.php resources/views/livewire/schoolprofile/index.blade.php lang/en/opes.php lang/fr/opes.php tests/Feature/SchoolProfile/SettingsHubTest.php
git commit -m "feat(schoolprofile): turn /settings into a permission-gated hub linking the real settings screens"
```

---

# Phase 6 — The 17 orphans

Scratch DB: `opeschool_scratch_p6`.

A 244-page administrator crawl found **17 built, correctly-gated screens with zero inbound links**. The nav/route contract holds perfectly in one direction — no role is ever offered a link its permissions refuse — and fails completely in the other.

```
/accounting/year-end          /procurement/invoices          /settings/fiscal-identity
/documents/verify             /procurement/invoices/capture  /settings/licence
/marks                        /procurement/orders            /settings/tax
/procurement/payables         /procurement/orders/capture    /tax/declarations
/procurement/payments         /procurement/receipts          /welfare/discipline
/procurement/payments/pay     /procurement/requisitions
```

Phase 5's settings hub already recovered `/settings/tax`, `/settings/fiscal-identity` and `/settings/licence`. This phase recovers the other fourteen.

### Task 21: Put marks entry in the Teacher's sidebar

`routes/web.php:305` calls `/marks` *"the single highest-traffic academic screen"*. It has **zero** inbound links from anywhere. The Teacher sidebar has ten items and marks entry is not one of them; a teacher can open the screen only by typing the URL.

**Files:**
- Modify: `app/Modules/Identity/Support/Navigation.php` (beside the `homework` entry, ~line 43)
- Modify: `lang/en/opes.php`, `lang/fr/opes.php` (`nav.marks`)
- Create: `tests/Feature/Shell/ReachabilityTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Shell/ReachabilityTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../Reporting/P13MoneyHelpers.php';

uses(RefreshDatabase::class);

/**
 * The 2026-08-13 routes audit found 17 built, correctly-gated screens with
 * ZERO inbound links in a 244-page crawl. The nav/route contract holds in
 * one direction (no role is offered a link its permissions refuse) and
 * failed completely in the other. These tests are the other direction.
 */
it('offers marks entry in the navigation', function (): void {
    $keys = array_column(Navigation::items(), 'key');

    expect($keys)->toContain('marks');
});

it('shows a teacher the marks screen in their own sidebar', function (): void {
    $response = $this->actingAs(p13moneyUserAs(Role::Teacher))->get('/dashboard');

    $response->assertOk();
    $response->assertSee('href="/marks"', escape: false);
});
```

- [ ] **Step 2: Run it and watch both fail**

```bash
"C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe" -uroot -e "CREATE DATABASE IF NOT EXISTS opeschool_scratch_p6"
PHP='C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe'
DB_DATABASE=opeschool_scratch_p6 "$PHP" artisan test tests/Feature/Shell/ReachabilityTest.php
```

Expected: 2 failures.

- [ ] **Step 3: Add the nav item**

In `app/Modules/Identity/Support/Navigation.php`, immediately **before** the `homework` entry (so marks entry sits above the thing it feeds):

```php
            // The route's own comment calls /marks "the single highest-
            // traffic academic screen", and a 244-page crawl found zero links
            // to it anywhere in the product - a teacher could reach their
            // primary daily task only by typing the URL. Gated on
            // marks.enter, matching the route, per this file's nav-and-route-
            // agree-by-construction contract.
            ['key' => 'marks', 'route' => '/marks', 'permission' => Permission::MarksEnter, 'enabled' => true, 'built' => true],
```

Add `'marks' => 'Marks entry',` to `lang/en/opes.php`'s `nav` block and `'marks' => 'Saisie des notes',` to `lang/fr/opes.php`'s.

- [ ] **Step 4: Add the in-context entry point**

`/results` is where a teacher already goes to look at a class's marks. In `resources/views/livewire/assessment/results/index.blade.php` (confirm the path with `grep -rl "results" resources/views/livewire --include=*.blade.php`), add to the header actions:

```blade
                @can('marks.enter')
                    <a href="{{ route('marks.entry') }}"
                       class="rounded-lg border border-primary bg-primary px-3.5 py-2 text-sm font-medium text-white transition hover:bg-primary/90">
                        {{ __('opes.nav.marks') }}
                    </a>
                @endcan
```

- [ ] **Step 5: Run the tests**

```bash
DB_DATABASE=opeschool_scratch_p6 "$PHP" artisan test tests/Feature/Shell/ReachabilityTest.php tests/Feature/LocalisationTest.php
```

Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Identity/Support/Navigation.php lang/en/opes.php lang/fr/opes.php resources/views/livewire/assessment/results/index.blade.php tests/Feature/Shell/ReachabilityTest.php
git commit -m "fix(navigation): put marks entry in the sidebar, where the teacher who needs it can find it"
```

---

### Task 22: A module sub-nav, and the purchase-order CTA that 404s

Nine procurement screens have zero inbound links — requisitions, orders, order capture, goods receipts, supplier invoices, invoice capture, payments, pay-a-supplier, payables. The sidebar's Procurement item lands on `/procurement/suppliers`, which renders **no sub-nav**: its only outbound links are the seven supplier rows. Separately, the Purchase Orders list's primary CTA points at `/procurement/orders/new`, which **404s** — the real route is `/procurement/orders/capture`, and `/procurement/orders/{order}` is `whereNumber`-constrained so `new` cannot fall through.

**Files:**
- Create: `resources/views/components/module-subnav.blade.php`
- Modify: the seven `resources/views/livewire/procurement/*/index.blade.php` and `payables` views
- Modify: `resources/views/livewire/procurement/purchase-orders/index.blade.php:25`
- Modify: `tests/Feature/Shell/ReachabilityTest.php`

- [ ] **Step 1: Add the failing tests**

Append to `tests/Feature/Shell/ReachabilityTest.php`:

```php
it('links every procurement screen from every procurement screen', function (): void {
    $response = $this->actingAs(p13moneyUserAs(Role::Bursar, Role::Accountant))->get('/procurement/suppliers');

    $response->assertOk();

    foreach ([
        '/procurement/suppliers', '/procurement/requisitions', '/procurement/orders',
        '/procurement/receipts', '/procurement/invoices', '/procurement/payments',
        '/procurement/payables',
    ] as $href) {
        $response->assertSee('href="'.$href.'"', escape: false);
    }
});

it('points the new-purchase-order button at a route that exists', function (): void {
    $response = $this->actingAs(p13moneyUserAs(Role::Bursar, Role::Accountant))->get('/procurement/orders');

    $response->assertOk();
    $response->assertDontSee('/procurement/orders/new', escape: false);
    $response->assertSee('/procurement/orders/capture', escape: false);
});
```

- [ ] **Step 2: Run and watch both fail**

```bash
DB_DATABASE=opeschool_scratch_p6 "$PHP" artisan test tests/Feature/Shell/ReachabilityTest.php
```

Expected: 2 new failures.

- [ ] **Step 3: Build the sub-nav component**

Create `resources/views/components/module-subnav.blade.php`:

```blade
@props([
    'items' => [],   // list<array{href: string, label: string, permission: string|null}>
])

{{-- A module whose sidebar entry lands on ONE of its screens needs a
     sub-nav, or the other eight are unreachable - which is exactly what
     happened to the whole procure-to-pay chain and to the ledger. Rendered
     on every screen in the module, so the operator can move between them
     without going back to the sidebar.

     Permission-filtered here rather than by Route::has(): the sidebar's
     contract is that a link is only ever offered to a role allowed to
     follow it, and Reports\Hub already shows what Route::has() gets wrong. --}}
<nav {{ $attributes->merge(['class' => '-mx-4 overflow-x-auto border-b border-border-primary px-4 sm:mx-0 sm:px-0']) }}
     aria-label="{{ __('opes.ui.section_navigation') }}">
    <div class="flex flex-wrap items-center gap-1">
        @foreach ($items as $item)
            @if ($item['permission'] === null || Gate::allows($item['permission']))
                @php $isCurrent = request()->is(ltrim($item['href'], '/')); @endphp
                <a href="{{ $item['href'] }}"
                   @if ($isCurrent) aria-current="page" @endif
                   class="rounded-t-lg border-b-2 px-3 py-2 text-sm font-medium transition {{ $isCurrent ? 'border-primary text-primary' : 'border-transparent text-charcoal/60 hover:border-border-primary hover:text-charcoal' }}">
                    {{ $item['label'] }}
                </a>
            @endif
        @endforeach
    </div>
</nav>
```

- [ ] **Step 4: Mount it on all seven procurement screens**

Add this block immediately under the page header in each of `resources/views/livewire/procurement/suppliers/index.blade.php`, `requisitions/index.blade.php`, `purchase-orders/index.blade.php`, `goods-receipts/index.blade.php`, `supplier-invoices/index.blade.php`, `payments/index.blade.php` and `payables/index.blade.php` (confirm exact filenames with `ls resources/views/livewire/procurement/*/`):

```blade
    <x-module-subnav :items="[
        ['href' => '/procurement/suppliers', 'label' => __('opes.procurement.nav_suppliers'), 'permission' => 'procurement.view'],
        ['href' => '/procurement/requisitions', 'label' => __('opes.procurement.nav_requisitions'), 'permission' => 'procurement.view'],
        ['href' => '/procurement/orders', 'label' => __('opes.procurement.nav_orders'), 'permission' => 'procurement.view'],
        ['href' => '/procurement/receipts', 'label' => __('opes.procurement.nav_receipts'), 'permission' => 'procurement.view'],
        ['href' => '/procurement/invoices', 'label' => __('opes.procurement.nav_invoices'), 'permission' => 'procurement.invoice_view'],
        ['href' => '/procurement/payments', 'label' => __('opes.procurement.nav_payments'), 'permission' => 'procurement.payment_record'],
        ['href' => '/procurement/payables', 'label' => __('opes.procurement.nav_payables'), 'permission' => 'procurement.view'],
    ]"/>
```

Add the seven `procurement.nav_*` keys and `ui.section_navigation` to both language files.

- [ ] **Step 5: Fix the 404 CTA**

In `resources/views/livewire/procurement/purchase-orders/index.blade.php:25`, replace:

```blade
url('/procurement/orders/new')
```

with:

```blade
route('procurement.orders.capture')
```

- [ ] **Step 6: Run the tests**

```bash
DB_DATABASE=opeschool_scratch_p6 "$PHP" artisan test tests/Feature/Shell/ReachabilityTest.php tests/Feature/LocalisationTest.php
```

Expected: all pass.

- [ ] **Step 7: Look at it**

Screenshot `/procurement/suppliers` and `/procurement/orders` at 1440×900 and 375×812. Expected: a tab strip under the header on both; at 375 it **wraps** rather than clipping the last item.

- [ ] **Step 8: Commit**

```bash
git add resources/views/components/module-subnav.blade.php resources/views/livewire/procurement lang/en/opes.php lang/fr/opes.php tests/Feature/Shell/ReachabilityTest.php
git commit -m "fix(procurement): give the module a sub-nav and stop the new-order CTA 404-ing"
```

---

### Task 23: The same sub-nav for the ledger, plus the year-end console

`/ledger/chart-of-accounts` (the sidebar's Ledger target) links neither the journal register nor the trial balance. `/ledger/trial-balance` is reachable only from `/finance/dashboard`; `/ledger/journal-entries` only from `/ledger/journal-entries/create`, which is itself only reachable from `/finance/dashboard`. `/accounting/year-end` has zero inbound links, and its own route comment says *"Without this the ledger could never enter a second fiscal year."*

**Files:**
- Modify: `resources/views/livewire/accounting/chart-of-accounts/index.blade.php`, `journal-entries/index.blade.php`, `journal-entries/create.blade.php`, `trial-balance/index.blade.php`, `year-end/console.blade.php` (confirm paths with `ls resources/views/livewire/accounting/`)
- Modify: `tests/Feature/Shell/ReachabilityTest.php`

- [ ] **Step 1: Add the failing test**

Append to `tests/Feature/Shell/ReachabilityTest.php`:

```php
it('links every ledger screen from the chart of accounts', function (): void {
    $response = $this->actingAs(p13moneyUserAs(Role::Accountant))->get('/ledger/chart-of-accounts');

    $response->assertOk();

    foreach ([
        '/ledger/chart-of-accounts', '/ledger/journal-entries',
        '/ledger/trial-balance', '/accounting/year-end',
    ] as $href) {
        $response->assertSee('href="'.$href.'"', escape: false);
    }
});
```

- [ ] **Step 2: Run and watch it fail**

```bash
DB_DATABASE=opeschool_scratch_p6 "$PHP" artisan test --filter="ledger screen"
```

Expected: FAIL.

- [ ] **Step 3: Mount the sub-nav on all five ledger screens**

```blade
    <x-module-subnav :items="[
        ['href' => '/ledger/chart-of-accounts', 'label' => __('opes.ledger.nav_chart'), 'permission' => 'ledger.view'],
        ['href' => '/ledger/journal-entries', 'label' => __('opes.ledger.nav_journals'), 'permission' => 'ledger.view'],
        ['href' => '/ledger/trial-balance', 'label' => __('opes.ledger.nav_trial_balance'), 'permission' => 'ledger.view'],
        ['href' => '/accounting/year-end', 'label' => __('opes.ledger.nav_year_end'), 'permission' => 'ledger.view'],
    ]"/>
```

Add the four `ledger.nav_*` keys to both language files.

- [ ] **Step 4: Run the tests**

```bash
DB_DATABASE=opeschool_scratch_p6 "$PHP" artisan test tests/Feature/Shell/ReachabilityTest.php tests/Feature/LocalisationTest.php
```

Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add resources/views/livewire/accounting lang/en/opes.php lang/fr/opes.php tests/Feature/Shell/ReachabilityTest.php
git commit -m "fix(ledger): give the ledger a sub-nav and surface the year-end console"
```

---

### Task 24: Link Tax declarations, the attestation print, discipline and document verification

Four remaining orphans, four one-line links:

- `/tax/declarations` — the declarations register; `/tax` (the sidebar's Tax item) does not link it and nothing else does. The withholding-attestation print route is likewise unlinked.
- `/welfare/discipline` — `routes/web.php:544-551` says it is *"deliberately NOT in the sidebar — reached from within (the student profile's Discipline tab and the Welfare area)"*. That tab is inert (Task 33) and there is no Welfare area.
- `/documents/verify` — the front-desk certificate-verification screen, no entry point at all.

**Files:**
- Modify: `resources/views/livewire/tax/dashboard.blade.php`
- Modify: `resources/views/livewire/tax/declarations/show.blade.php`
- Modify: `app/Modules/Identity/Support/Navigation.php`
- Modify: `lang/en/opes.php`, `lang/fr/opes.php`
- Modify: `tests/Feature/Shell/ReachabilityTest.php`

- [ ] **Step 1: Add the failing tests**

Append to `tests/Feature/Shell/ReachabilityTest.php`:

```php
it('links the declarations register from the tax dashboard', function (): void {
    $this->actingAs(p13moneyUserAs(Role::Accountant))
        ->get('/tax')
        ->assertOk()
        ->assertSee('href="/tax/declarations"', escape: false);
});

it('carries discipline and document verification in the navigation', function (): void {
    $keys = array_column(Navigation::items(), 'key');

    expect($keys)->toContain('discipline');
    expect($keys)->toContain('documents_verify');
});
```

- [ ] **Step 2: Run and watch both fail**

```bash
DB_DATABASE=opeschool_scratch_p6 "$PHP" artisan test tests/Feature/Shell/ReachabilityTest.php
```

Expected: 2 new failures.

- [ ] **Step 3: Link declarations from the tax dashboard**

In `resources/views/livewire/tax/dashboard.blade.php`, in the header actions:

```blade
                @can('tax.declare')
                    <a href="{{ route('tax.declarations.index') }}"
                       class="rounded-lg border border-border-primary px-3.5 py-2 text-sm font-medium text-charcoal transition hover:border-primary/50 hover:bg-sand hover:text-primary">
                        {{ __('opes.tax.declarations') }}
                    </a>
                @endcan
```

- [ ] **Step 4: Add the attestation print button**

In `resources/views/livewire/tax/declarations/show.blade.php`, on each withholding-attestation row:

```blade
                        <a href="{{ url('/tax/withholding-attestations/'.$attestation->id.'/print') }}"
                           class="text-sm font-medium text-primary hover:underline">
                            {{ __('opes.tax.print_attestation') }}
                        </a>
```

- [ ] **Step 5: Add the two nav items**

In `app/Modules/Identity/Support/Navigation.php`, beside the other Welfare entries:

```php
            // The route comment says discipline is "reached from within (the
            // student profile's Discipline tab and the Welfare area)" - but
            // that tab is disabled and there is no Welfare area, so the
            // screen had zero inbound links. Gated on discipline.view,
            // matching the route.
            ['key' => 'discipline', 'route' => '/welfare/discipline', 'permission' => Permission::DisciplineView, 'enabled' => true, 'built' => true],
            // The front-desk certificate check (10-documents §17.2) had no
            // entry point anywhere.
            ['key' => 'documents_verify', 'route' => '/documents/verify', 'permission' => null, 'enabled' => true, 'built' => true],
```

Use the exact `Permission` case names from `app/Modules/Identity/Domain/Permission.php`; if `DisciplineView` is spelled differently there, match the file. Add `nav.discipline`, `nav.documents_verify`, `tax.declarations` and `tax.print_attestation` to both language files.

- [ ] **Step 6: Run the tests**

```bash
DB_DATABASE=opeschool_scratch_p6 "$PHP" artisan test tests/Feature/Shell/ReachabilityTest.php tests/Feature/LocalisationTest.php
```

Expected: all pass.

- [ ] **Step 7: Commit**

```bash
git add app/Modules/Identity/Support/Navigation.php resources/views/livewire/tax lang/en/opes.php lang/fr/opes.php tests/Feature/Shell/ReachabilityTest.php
git commit -m "fix(navigation): surface tax declarations, attestation printing, discipline and document verification"
```

---

### Task 25: Give the go-live console and the dashboard something to click

`/setup` reports three checks that *"block go-live"* — tax treatment undecided, no confirmed prorata for 2026/2027, 0 of 94 accounts mapped to DSF lines — and renders **zero anchors inside `<main>`**. It is the "wizard with no next step" defect exactly. Separately, `/dashboard` tells a school administrator to run `php artisan opes:backup:run` even though `/operations/backups` exists, is in the sidebar, and has a run control.

**Files:**
- Modify: `app/Modules/Operations/Livewire/Setup/Index.php`
- Modify: `resources/views/livewire/operations/setup/index.blade.php`
- Modify: `resources/views/livewire/dashboard.blade.php`
- Modify: `tests/Feature/Shell/ReachabilityTest.php`

- [ ] **Step 1: Add the failing tests**

Append to `tests/Feature/Shell/ReachabilityTest.php`:

```php
it('gives every go-live blocker a link to the screen that clears it', function (): void {
    $response = $this->actingAs(p13moneyUserAs(Role::Administrator))->get('/setup');

    $response->assertOk();
    $response->assertSee('href="/settings/tax"', escape: false);
    $response->assertSee('href="/ledger/chart-of-accounts"', escape: false);
});

it('offers a backup screen instead of an artisan command', function (): void {
    $response = $this->actingAs(p13moneyUserAs(Role::Administrator))->get('/dashboard');

    $response->assertOk();
    $response->assertSee('href="/operations/backups"', escape: false);
    $response->assertDontSee('php artisan opes:backup:run', escape: false);
});
```

- [ ] **Step 2: Run and watch both fail**

```bash
DB_DATABASE=opeschool_scratch_p6 "$PHP" artisan test tests/Feature/Shell/ReachabilityTest.php
```

Expected: 2 new failures.

- [ ] **Step 3: Attach a remediation route to each readiness row**

In `app/Modules/Operations/Livewire/Setup/Index.php`, wherever the readiness rows are assembled, add a `fix_href` to each. Read the existing structure first:

```bash
grep -n "check\|blocker\|readiness" app/Modules/Operations/Livewire/Setup/Index.php | head -30
```

Then map each check key to the screen that owns it — tax treatment and prorata to `/settings/tax`, DSF account mapping to `/ledger/chart-of-accounts`, fiscal identity to `/settings/fiscal-identity`, school identity to `/settings/school-identity`:

```php
            // A readiness console that names a blocker and gives no way to
            // clear it is a wizard with no next step. Every row owns the
            // screen that fixes it; that mapping belongs here, beside the
            // check, not in the operator's head.
            'fix_href' => match ($check['key']) {
                'tax_treatment', 'prorata' => '/settings/tax',
                'dsf_mapping' => '/ledger/chart-of-accounts',
                'fiscal_identity' => '/settings/fiscal-identity',
                'school_identity' => '/settings/school-identity',
                default => null,
            },
```

In `resources/views/livewire/operations/setup/index.blade.php`, on each row:

```blade
                    @if ($check['fix_href'] !== null)
                        <a href="{{ $check['fix_href'] }}"
                           class="text-sm font-medium text-primary hover:underline">
                            {{ __('opes.setup.fix_this') }}
                        </a>
                    @endif
```

- [ ] **Step 4: Replace the CLI instruction on the dashboard**

In `resources/views/livewire/dashboard.blade.php`, replace the backup remediation copy with:

```blade
                <a href="{{ route('operations.backups') }}"
                   class="mt-2 inline-block rounded-lg border border-primary bg-primary px-3.5 py-2 text-sm font-medium text-white transition hover:bg-primary/90">
                    {{ __('opes.dashboard.run_a_backup') }}
                </a>
                {{-- The command that used to be printed here belongs to whoever
                     runs the server, not to a school administrator reading a
                     dashboard. --}}
                <details class="mt-2 text-xs text-charcoal/55">
                    <summary class="cursor-pointer">{{ __('opes.dashboard.for_your_it_provider') }}</summary>
                    <code class="mt-1 block">php artisan opes:backup:run</code>
                </details>
```

Do the same for the `schedule:work` copy, pointing at `/operations/backups` and disclosing the command the same way. Add `setup.fix_this`, `dashboard.run_a_backup` and `dashboard.for_your_it_provider` to both language files.

- [ ] **Step 5: Run the tests**

```bash
DB_DATABASE=opeschool_scratch_p6 "$PHP" artisan test tests/Feature/Shell/ReachabilityTest.php tests/Feature/LocalisationTest.php
```

Expected: all pass.

- [ ] **Step 6: Look at it**

Screenshot `/setup` and `/dashboard` at 1440×900. Expected: every blocked readiness row carries a "Fix this" link, and the backup panel carries a button rather than a shell command.

- [ ] **Step 7: Commit**

```bash
git add app/Modules/Operations/Livewire/Setup/Index.php resources/views/livewire/operations/setup/index.blade.php resources/views/livewire/dashboard.blade.php lang/en/opes.php lang/fr/opes.php tests/Feature/Shell/ReachabilityTest.php
git commit -m "fix(operations): give every go-live blocker a fix link and replace the dashboard's CLI instruction"
```

---

### Task 26: The insurance back-link that 404s

Both the breadcrumb and the "Back" button on the insurance policy detail page point at `/welfare/insurance`, which **404s**. The list lives at `/insurance`.

**Files:**
- Modify: `resources/views/livewire/welfare/insurance/policy-show.blade.php:35,49`
- Modify: `tests/Feature/Shell/ReachabilityTest.php`

- [ ] **Step 1: Add the failing test**

```php
it('sends the insurance back-link to a route that exists', function (): void {
    $response = $this->actingAs(p13moneyUserAs(Role::Administrator))->get('/insurance');

    $response->assertOk();
    $response->assertDontSee('/welfare/insurance', escape: false);
});
```

Add a second assertion against a real policy detail page once one exists in the fixture; if `insurance_policies` is empty in the test database, assert against the rendered Blade instead:

```php
it('does not name a 404 route in the policy detail view', function (): void {
    $blade = (string) file_get_contents(resource_path('views/livewire/welfare/insurance/policy-show.blade.php'));

    expect($blade)->not->toContain("url('/welfare/insurance')");
});
```

- [ ] **Step 2: Run and watch it fail**

```bash
DB_DATABASE=opeschool_scratch_p6 "$PHP" artisan test --filter="insurance"
```

Expected: FAIL.

- [ ] **Step 3: Point both at the real route**

In `resources/views/livewire/welfare/insurance/policy-show.blade.php`, replace both occurrences of `url('/welfare/insurance')` with `route('welfare.insurance.index')`. Confirm the route name first:

```bash
grep -n "insurance" routes/web.php | head
```

Use whatever name that file actually declares — do not guess.

- [ ] **Step 4: Run the test again, then commit**

```bash
DB_DATABASE=opeschool_scratch_p6 "$PHP" artisan test tests/Feature/Shell/ReachabilityTest.php
git add resources/views/livewire/welfare/insurance/policy-show.blade.php tests/Feature/Shell/ReachabilityTest.php
git commit -m "fix(welfare): send the insurance back-link to the route the list actually lives at"
```

---

# Phase 7 — The remaining component-level visual work

Scratch DB: `opeschool_scratch_p7`.

### Task 27: The empty rail that squeezes the table

The single defect behind both halves of the "empty *and* cramped at the same time" complaint. The right rail is a 238px column that is 25–91% blank — measured gap between the rail card's bottom and the rail's bottom: `/users` **2721px of 2978**, `/staff` **1356px of 1605**, `/library` **996px of 1203** — while the table beside it is squeezed to 862px and wraps five of its eight columns on `/staff` (`NAME` breaking "Bertrand / Atangana", `STAFF NO` breaking "STF-2026- / 005") and seven of eight on `/visitors`.

**The fix, argued.** Not "fill the rail" — that is per-screen content work and cannot be done from a component. Make the rail **sticky and self-sizing**: it scrolls with the viewport, stops at its own content height, and the table takes the full body width past the rail's end. The rail keeps its 238px where it has content and stops charging the table for the 2721px where it does not.

**Files:**
- Modify: `resources/views/components/list-screen.blade.php:187-275`
- Create: `tests/Feature/Ui/ListScreenLayoutTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Ui/ListScreenLayoutTest.php`:

```php
<?php

declare(strict_types=1);

it('makes the rail sticky and self-sizing so it stops charging the table for empty space', function (): void {
    $blade = (string) file_get_contents(resource_path('views/components/list-screen.blade.php'));

    expect($blade)->toContain('lg:sticky');
    expect($blade)->toContain('lg:top-4');
    expect($blade)->toContain('lg:self-start');
});
```

- [ ] **Step 2: Run and watch it fail**

```bash
"C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe" -uroot -e "CREATE DATABASE IF NOT EXISTS opeschool_scratch_p7"
PHP='C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe'
DB_DATABASE=opeschool_scratch_p7 "$PHP" artisan test tests/Feature/Ui/ListScreenLayoutTest.php
```

Expected: FAIL.

- [ ] **Step 3: Make the rail sticky**

In `resources/views/components/list-screen.blade.php`, on the `<aside>` (or `<div>`) that opens the `@isset($rail)` block, add to its class list:

```blade
lg:sticky lg:top-4 lg:self-start
```

with this comment above it:

```blade
            {{-- Sticky and self-sizing. As a stretched flex child the rail
                 ran the full height of the table with one card at the top -
                 2721px of blank canvas on /users, 1356px on /staff - while
                 charging the table 238px of width the whole way down, which
                 is why /staff wrapped five of its eight columns and /visitors
                 seven of eight. `self-start` stops it stretching; `sticky`
                 keeps it in view while the table scrolls past it. This is the
                 same fix, one axis over, as the sidebar height bug in
                 5c6bb8b: nothing was constraining a flex child's height. --}}
```

- [ ] **Step 4: Run the test, then look at it**

```bash
DB_DATABASE=opeschool_scratch_p7 "$PHP" artisan test tests/Feature/Ui/ListScreenLayoutTest.php
```

Expected: pass. Then screenshot `/staff`, `/users`, `/visitors`, `/library` at 1440×900 and **look**: the rail card should sit at the top and stay in view while the table scrolls; the tall blank strip is gone.

**If `/staff` still wraps `NAME` and `STAFF NO` after this, that is Task 28's job, not a failure of this one.**

- [ ] **Step 5: Commit**

```bash
git add resources/views/components/list-screen.blade.php tests/Feature/Ui/ListScreenLayoutTest.php
git commit -m "fix(ui): make the list-screen rail sticky and self-sizing so it stops squeezing the table"
```

---

### Task 28: Identifiers and pills that break across lines

`/students`' class pills read "Lower / Sixth / Science" and "Form 3 / (4eme) A" **inside a single pill**, so rows 1–2 measure 84px and rows 3+ measure 65px — the row height is inconsistent within one table. `/visitors` breaks "On / site" inside its status pill. `/students` splits `OS-26-` / `CUR0001`.

**Files:**
- Modify: `resources/views/components/status-pill.blade.php:28`
- Modify: `resources/css/app.css`
- Modify: `tests/Feature/Ui/ListScreenLayoutTest.php`

- [ ] **Step 1: Add the failing test**

```php
it('never breaks a status pill across lines', function (): void {
    $blade = (string) file_get_contents(resource_path('views/components/status-pill.blade.php'));

    expect($blade)->toContain('whitespace-nowrap');
});
```

- [ ] **Step 2: Run and watch it fail**

```bash
DB_DATABASE=opeschool_scratch_p7 "$PHP" artisan test tests/Feature/Ui/ListScreenLayoutTest.php
```

Expected: FAIL.

- [ ] **Step 3: Stop the pill wrapping**

In `resources/views/components/status-pill.blade.php`, change the wrapper class string:

```blade
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border px-2.5 py-0.5 text-xs font-semibold '.$tone]) }}>
```

- [ ] **Step 4: Stop identifier cells wrapping, unlayered**

Append to `resources/css/app.css`, **outside any `@layer`** — per the restyle brief, Tailwind v4 compiles utilities into `@layer utilities` and unlayered CSS outranks every layered rule regardless of specificity, so a `@layer components` version would lose to the very `px-2`/`py-1.5` it exists to correct and ship as a measurable no-op:

```css
/* An identifier is one token. `OS-26-` / `CUR0001` and `STF-2026-` / `005`
   split across two lines are not a narrow column, they are a broken record
   number - and the row-height ripple was most of what made /staff the
   tallest table in the app. Scoped to the staff layout: auth and the
   guardian portal have their own approved designs. Exclusions live inside
   :where() so every rule here weighs the same (0,2,0) and SOURCE ORDER
   decides - bare :not() chains are what produced the chevron-over-value bug
   in the forms pass. */
.opes-app :where(td, th)[data-identifier] {
    white-space: nowrap;
}

.opes-app :where(table) :where(td, th):first-child {
    white-space: nowrap;
}
```

Then add `data-identifier` to the ID, date and phone cells on `/staff`, `/students`, `/visitors` and `/finance/invoices`. Find them with:

```bash
grep -rn "staff_no\|matricule\|admission_no\|phone" resources/views/livewire/hr/index.blade.php resources/views/livewire/students/index.blade.php resources/views/livewire/welfare/visitors/index.blade.php
```

- [ ] **Step 5: Run the test, then look**

```bash
DB_DATABASE=opeschool_scratch_p7 "$PHP" artisan test tests/Feature/Ui/ListScreenLayoutTest.php
```

Screenshot `/staff`, `/students`, `/visitors` at 1440×900 and **look at the images**: no name, staff number, phone or pill should break mid-token, and every row in one table should be the same height. Measurements will pass before this looks right; the brief's rule applies.

- [ ] **Step 6: Commit**

```bash
git add resources/views/components/status-pill.blade.php resources/css/app.css resources/views/livewire/hr/index.blade.php resources/views/livewire/students/index.blade.php resources/views/livewire/welfare/visitors/index.blade.php tests/Feature/Ui/ListScreenLayoutTest.php
git commit -m "fix(ui): stop identifiers and status pills breaking mid-token and skewing row heights"
```

---

### Task 29: The second horizontal scrollbar, the clipped page shell, and 40px touch targets

Three mobile defects, all in shared components. At 375px every list screen stacks **two** horizontally-scrolling strips: the KPI strip (fixed in Task 14) and the status tabs, whose last tab clips mid-word ("Inactive"). `/students/1`'s `<main>` is `overflow-x-hidden`, so nine elements sit past the right edge with no way to reach them. And 61 of 186 interactive elements on `/finance/invoices` are under 40px tall.

**Files:**
- Modify: `resources/views/components/list-screen.blade.php:180`
- Modify: `resources/views/layouts/app.blade.php` (`<main>`)
- Modify: `resources/css/app.css`
- Modify: `tests/Feature/Ui/ListScreenLayoutTest.php`

- [ ] **Step 1: Add the failing test**

```php
it('wraps the status tabs instead of scrolling them at 375px', function (): void {
    $blade = (string) file_get_contents(resource_path('views/components/list-screen.blade.php'));

    expect($blade)->not->toContain('overflow-x-auto border-b border-border-primary');
});

it('does not clip the page shell horizontally', function (): void {
    $blade = (string) file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($blade)->not->toContain('overflow-x-hidden bg-sand');
});
```

- [ ] **Step 2: Run and watch both fail**

```bash
DB_DATABASE=opeschool_scratch_p7 "$PHP" artisan test tests/Feature/Ui/ListScreenLayoutTest.php
```

Expected: 2 failures.

- [ ] **Step 3: Wrap the tabs**

Replace the tabs block in `resources/views/components/list-screen.blade.php`:

```blade
    @isset($tabs)
        {{-- Wrapped, not scrolled. At 375px this was the SECOND horizontal
             scroller stacked on one screen (the KPI strip above it was the
             first), and it clipped "Inactive" mid-word. Three status tabs
             fit on two lines and cost 24px; a hidden tab costs the operator
             the filter they were looking for. --}}
        <div class="border-b border-border-primary">
            <div class="flex flex-wrap items-center gap-1">
                {{ $tabs }}
            </div>
        </div>
    @endisset
```

- [ ] **Step 4: Scroll the wide child, not the shell**

In `resources/views/layouts/app.blade.php`, on `<main>`, replace `overflow-x-hidden` with `overflow-x-auto`:

```blade
        {{-- overflow-x-AUTO, not hidden. `hidden` silently cut nine elements
             off /students/1 at 375px with no way to reach them; a page that
             clips its own content is worse than one that scrolls. The rule
             list-screen already follows is: scroll the wide child (the
             table), never the shell - and where a screen is still wider than
             the viewport, a scrollbar is the honest answer until it isn't. --}}
        <main class="min-w-0 flex-1 overflow-x-auto bg-sand px-4 py-6">
```

- [ ] **Step 5: Give touch targets a floor**

Append to `resources/css/app.css`, unlayered, beside the Task 28 block:

```css
/* 44px is the floor a thumb can hit. 61 of 186 interactive elements on
   /finance/invoices were under 40px - the "Check out" and "View" text links
   and the pagination chips - which on the cheap Android handsets that make
   up most of the field fleet means mis-taps, not inconvenience. Below md
   only: on a mouse-driven desktop the same links are fine and the extra
   height would just loosen the tables. */
@media (max-width: 767px) {
    .opes-app :where(a, button):not(:where([role='tab'], .opes-inline-link)) {
        min-height: 44px;
        display: inline-flex;
        align-items: center;
    }
}
```

- [ ] **Step 6: Run the tests, then look at 375px**

```bash
DB_DATABASE=opeschool_scratch_p7 "$PHP" artisan test tests/Feature/Ui/ListScreenLayoutTest.php
```

Resize to **375×812**, reload, and screenshot `/students`, `/finance/invoices`, `/staff`, `/students/1`. Expected: no horizontal scrollbar under the tabs, no clipped tab, nothing unreachable past the right edge of `/students/1`, and row links that read as tappable. **Look at the images** — this whole task is invisible to measurement.

- [ ] **Step 7: Commit**

```bash
git add resources/views/components/list-screen.blade.php resources/views/layouts/app.blade.php resources/css/app.css tests/Feature/Ui/ListScreenLayoutTest.php
git commit -m "fix(ui): wrap the status tabs, stop the shell clipping, and give mobile targets a 44px floor"
```

---

### Task 30: One button component

`/students` — the most-visited screen in the product — renders "Add New Student" as a pale outline that reads **disabled**, while `/staff`, `/attendance` and `/inventory` render the same rank as a filled dark green. `/students/1` invents two variants that exist nowhere else: an amber-outlined "Suspend" and a red-outlined "Withdraw".

**Files:**
- Create: `resources/views/components/btn.blade.php`
- Modify: `resources/views/livewire/students/index.blade.php`, `resources/views/livewire/students/show.blade.php`
- Create: `tests/Feature/Ui/ButtonVariantTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Ui/ButtonVariantTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

it('renders each button variant with its own literal class string', function (string $variant, string $marker): void {
    $html = Blade::render('<x-btn variant="'.$variant.'">Do it</x-btn>');

    expect($html)->toContain($marker);
})->with([
    ['primary', 'bg-primary'],
    ['outline', 'border-border-primary'],
    ['ghost', 'hover:bg-sand'],
    ['warning', 'border-heritage-yellow'],
    ['danger', 'border-heritage-red'],
]);

it('renders an anchor when given an href', function (): void {
    expect(Blade::render('<x-btn href="/students">Go</x-btn>'))->toContain('<a href="/students"');
});
```

- [ ] **Step 2: Run and watch it fail**

```bash
DB_DATABASE=opeschool_scratch_p7 "$PHP" artisan test tests/Feature/Ui/ButtonVariantTest.php
```

Expected: FAIL — component missing.

- [ ] **Step 3: Write the component**

Create `resources/views/components/btn.blade.php`:

```blade
@props([
    'variant' => 'primary',   // primary | outline | ghost | warning | danger
    'href' => null,
    'type' => 'button',
])

@php
    // Literal class strings in every match arm. Tailwind v4 scans Blade
    // literally, so a string built by concatenation compiles to nothing and
    // ships as an unstyled button that measures fine in the DOM.
    $classes = match ($variant) {
        'outline' => 'border-border-primary bg-white text-charcoal hover:border-primary/50 hover:bg-sand hover:text-primary',
        'ghost' => 'border-transparent bg-transparent text-charcoal hover:bg-sand',
        'warning' => 'border-heritage-yellow bg-white text-charcoal hover:bg-heritage-yellow/15',
        'danger' => 'border-heritage-red bg-white text-heritage-red hover:bg-heritage-red/10',
        default => 'border-primary bg-primary text-white shadow-[0_1px_2px_rgba(0,45,23,0.12)] hover:bg-primary/90',
    };

    $base = 'inline-flex items-center justify-center gap-2 rounded-lg border px-3.5 py-2 text-sm font-medium transition ';
@endphp

@if (is_string($href) && $href !== '')
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $base.$classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $base.$classes]) }}>{{ $slot }}</button>
@endif
```

- [ ] **Step 4: Move the three worst offenders onto it**

In `resources/views/livewire/students/index.blade.php`, replace the "Add New Student" anchor with:

```blade
                <x-btn variant="primary" :href="route('admissions.wizard')">
                    {{ __('opes.students.add_new') }}
                </x-btn>
```

In `resources/views/livewire/students/show.blade.php`, replace the ENROLLMENT strip's three buttons:

```blade
                <x-btn variant="outline" wire:click="startTransferClass">{{ __('opes.students.transfer_class') }}</x-btn>
                <x-btn variant="warning" wire:click="startSuspend">{{ __('opes.students.suspend') }}</x-btn>
                <x-btn variant="danger" wire:click="startWithdraw">{{ __('opes.students.withdraw') }}</x-btn>
```

keeping whatever `wire:` bindings and translation keys the existing markup already uses.

- [ ] **Step 5: Run the tests, then look**

```bash
DB_DATABASE=opeschool_scratch_p7 "$PHP" artisan test tests/Feature/Ui/ButtonVariantTest.php
```

Screenshot `/students` and `/students/1` at 1440×900. Expected: "Add New Student" is filled dark green like every other screen's primary, and the amber/red actions read as a deliberate pair rather than two one-offs.

- [ ] **Step 6: Commit**

```bash
git add resources/views/components/btn.blade.php resources/views/livewire/students/index.blade.php resources/views/livewire/students/show.blade.php tests/Feature/Ui/ButtonVariantTest.php
git commit -m "feat(ui): one button component with five variants, and fix the primary CTA that read as disabled"
```

---

### Task 31: One container radius

Measured distinct `border-radius` values inside `<main>`: ten screens return **both 17px and 4.25px**; `/library/books/1` returns 12.75px; and `/academics/settings` and `/students/1` — the two biggest detail pages — return **only 4.25px**, i.e. none of the new radius at all. Mixed corner radii in one column is the loudest "assembled, not designed" cue in the product, and the brief already says so.

**Files:**
- Modify: `resources/css/app.css`
- Create: `tests/Feature/Ui/RadiusTokenTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

it('defines one card radius token and applies it unlayered', function (): void {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    expect($css)->toContain('--radius-card:');
    expect($css)->toContain('.opes-app :where(.card, section, aside) > :where(.rounded)');
});
```

- [ ] **Step 2: Run and watch it fail**

```bash
DB_DATABASE=opeschool_scratch_p7 "$PHP" artisan test tests/Feature/Ui/RadiusTokenTest.php
```

Expected: FAIL.

- [ ] **Step 3: Add the token and the unlayered correction**

In the `:root`/`@theme` block of `resources/css/app.css`, beside the other tokens:

```css
    --radius-card: 12px;
```

Then, unlayered and at the end of the file:

```css
/* Three container radii coexisted in one column - 17px, 12.75px and 4.25px -
   and the two biggest detail pages (/academics/settings, /students/1)
   carried only the 4.25px default, so they read as a different product from
   the list screens beside them. Corrected UNLAYERED: Tailwind v4 puts
   `rounded` in @layer utilities, and a @layer components version of this
   rule loses to the very `rounded` it exists to override - it would measure
   correct in the stylesheet and change nothing on screen.

   Scoped to .opes-app (auth and the guardian portal have their own approved
   designs) with exclusions inside :where() so source order decides. Pills,
   avatars and badges keep their full round. */
.opes-app :where(.card, section, aside) > :where(.rounded, .rounded-md, .rounded-lg):not(:where(.rounded-full, [data-pill])) {
    border-radius: var(--radius-card);
}

.opes-app :where(main) :where(.rounded, .rounded-md):not(:where(.rounded-full, [data-pill], input, select, textarea, button, a)) {
    border-radius: var(--radius-card);
}
```

- [ ] **Step 4: Run the test, then look at every screen it touches**

```bash
DB_DATABASE=opeschool_scratch_p7 "$PHP" artisan test tests/Feature/Ui/RadiusTokenTest.php
```

Screenshot `/academics/settings`, `/students/1`, `/library/books/1`, `/students`, `/finance/dashboard` at 1440×900. Expected: one corner radius down each column. **This is the highest-risk change in Phase 7** — an unlayered rule beats everything, so check for anything that has gone unexpectedly round (inputs, chips, the sidebar). Look, do not measure.

- [ ] **Step 5: Commit**

```bash
git add resources/css/app.css tests/Feature/Ui/RadiusTokenTest.php
git commit -m "fix(ui): one card radius token, applied unlayered so it actually reaches the detail pages"
```

---

### Task 32: The two broken role dashboards

The teacher lands on **one** full-width KPI card whose value is an em dash, plus a raw framework exception: `LedgerIntegrityCheck / This check could not run: This action is unauthorized.` The accountant lands on **zero** KPI cards and a 190px empty-state slab reading "Nothing needs your attention right now." Neither is fixable at the component layer, and both are what a prospect sees within ten seconds.

**Files:**
- Modify: `app/Modules/Operations/Livewire/Dashboard.php`
- Modify: `resources/views/livewire/dashboard.blade.php`
- Create: `tests/Feature/Operations/RoleDashboardTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Operations/RoleDashboardTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../Reporting/P13MoneyHelpers.php';

uses(RefreshDatabase::class);

it('never shows a role a health check it is not allowed to run', function (): void {
    $response = $this->actingAs(p13moneyUserAs(Role::Teacher))->get('/dashboard');

    $response->assertOk();
    $response->assertDontSee('This action is unauthorized', escape: false);
    $response->assertDontSee('LedgerIntegrityCheck', escape: false);
});

it('gives the accountant a populated dashboard', function (): void {
    $response = $this->actingAs(p13moneyUserAs(Role::Accountant))->get('/dashboard');

    $response->assertOk();
    $response->assertSee('kpi', escape: false);
    $response->assertDontSee('Nothing needs your attention right now', escape: false);
});
```

- [ ] **Step 2: Run and watch both fail**

```bash
DB_DATABASE=opeschool_scratch_p7 "$PHP" artisan test tests/Feature/Operations/RoleDashboardTest.php
```

Expected: 2 failures.

- [ ] **Step 3: Suppress checks the role cannot run**

In `app/Modules/Operations/Livewire/Dashboard.php`, wherever the health checks are collected, filter by the permission each check needs **before** running it:

```php
        // A check the role may not run is not an ALERT, it is a check that
        // does not apply to them - and rendering the authorization exception
        // as the teacher's only "needs attention" item put a raw framework
        // error on the first screen they see every morning.
        $checks = array_values(array_filter(
            $checks,
            static fn (array $check): bool => $check['permission'] === null || Gate::allows($check['permission']),
        ));
```

Read the existing check array first (`grep -n "checks\|Check::" app/Modules/Operations/Livewire/Dashboard.php`) and add a `permission` key to each — `LedgerIntegrityCheck` needs `ledger.view`.

- [ ] **Step 4: Populate the accountant's dashboard**

Add four KPIs the accountant's own screens already compute — cash position, unpaid invoices, today's receipts, unposted journals — each gated on `ledger.view` or `fee.view`. Reuse the existing Actions rather than writing new queries:

```bash
grep -rn "class Count\|class Sum\|Treasury" app/Modules/Accounting/Actions app/Modules/Fees/Actions | head -20
```

Wire them into the existing KPI array in `Dashboard.php` with `tone` set explicitly (Phase 3's derivation only covers the legacy `icon-bg` callers), and give each a `sub` line — the component has supported `sub` since the restyle and **zero** callers pass one.

- [ ] **Step 5: Run the tests**

```bash
DB_DATABASE=opeschool_scratch_p7 "$PHP" artisan test tests/Feature/Operations/RoleDashboardTest.php
```

Expected: `Tests:  2 passed`.

- [ ] **Step 6: Look at all three role dashboards**

Log in as teacher, accountant and administrator in turn and screenshot `/dashboard` at 1440×900. Expected: no raw exception anywhere, no role with zero cards, and no row that is mostly em dashes.

- [ ] **Step 7: Commit**

```bash
git add app/Modules/Operations/Livewire/Dashboard.php resources/views/livewire/dashboard.blade.php tests/Feature/Operations/RoleDashboardTest.php
git commit -m "fix(operations): stop showing a teacher an authorization exception and give the accountant a dashboard"
```

---

# Phase 8 — Screen-level long tail

Scratch DB: `opeschool_scratch_p8`.

### Task 33: Wire the five student-profile tabs whose data now exists

`Students\Livewire\Students\Show.php:83` declares `LIVE_TABS = ['general','guardians','documents','medical']` and `:91` `DISABLED_TABS = [overview, academic_records, attendance, examinations, fees, discipline, activity_log]`, frozen at *"the seven mockup tabs that Phase 2 cannot fill"*. The platform now has all of that data: an attendance module, examinations, `/finance/statement/{student}`, discipline cases, computed results and the audit log. Eleven tabs, seven greyed out, is the loudest "unbuilt product" signal on the most-visited detail page.

**Files:**
- Modify: `app/Modules/Students/Livewire/Students/Show.php:83,91`
- Modify: `resources/views/livewire/students/show.blade.php:396-402`
- Create: `tests/Feature/Students/StudentTabsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Students\Livewire\Students\Show;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Modules\Students\Models\Student;

require_once __DIR__.'/../Reporting/P13MoneyHelpers.php';

uses(RefreshDatabase::class);

it('offers the five tabs whose data the platform now has', function (string $tab): void {
    $student = Student::factory()->create();

    Livewire::actingAs(p13moneyUserAs(Role::Registrar))
        ->test(Show::class, ['student' => $student->id])
        ->set('tab', $tab)
        ->assertHasNoErrors()
        ->assertOk();
})->with(['attendance', 'fees', 'discipline', 'academic_records', 'activity_log']);
```

- [ ] **Step 2: Run and watch it fail**

```bash
"C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe" -uroot -e "CREATE DATABASE IF NOT EXISTS opeschool_scratch_p8"
PHP='C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe'
DB_DATABASE=opeschool_scratch_p8 "$PHP" artisan test tests/Feature/Students/StudentTabsTest.php
```

Expected: 5 failures — the tabs are refused.

- [ ] **Step 3: Move the five tabs across**

In `app/Modules/Students/Livewire/Students/Show.php`:

```php
    /**
     * The four tabs Phase 2 could fill, plus the five whose data the platform
     * has since grown: attendance (the attendance module), fees (there is a
     * per-student statement at /finance/statement/{student}), discipline
     * (welfare cases), academic_records (computed results) and activity_log
     * (the audit log). `overview` and `examinations` stay disabled: the
     * first duplicates the identity card above it and the second has no
     * per-student view yet.
     */
    private const LIVE_TABS = [
        'general', 'guardians', 'documents', 'medical',
        'attendance', 'fees', 'discipline', 'academic_records', 'activity_log',
    ];

    private const DISABLED_TABS = ['overview', 'examinations'];
```

Then add a render branch per tab, each reading through the owning module's existing Action or a `DB::table` join in this component (the house pattern for detail screens). Do **not** import another module's Models — `tests/Architecture/ModuleBoundaryTest.php` forbids it, and Phase 0 Tasks 2 and 3 exist because that rule was broken twice.

- [ ] **Step 4: Run the tests, then look**

```bash
DB_DATABASE=opeschool_scratch_p8 "$PHP" artisan test tests/Feature/Students/StudentTabsTest.php --testsuite=Architecture
```

Screenshot `/students/1` at 1440×900 and click each newly-live tab. Expected: two disabled tabs, not seven; nine tabs still overflow the strip, which Task 36 addresses.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Students/Livewire/Students/Show.php resources/views/livewire/students/show.blade.php tests/Feature/Students/StudentTabsTest.php
git commit -m "feat(students): wire the five profile tabs whose data the platform has since grown"
```

---

### Task 34: Route the nine dead items in the academics settings sub-nav

`/academics/settings` renders a ten-item sub-nav of which **nine are inert**, including "Subject Management" and "Class Settings" — screens that exist at `/subjects` and `/classes` and are already in the sidebar. The nav tells the operator those modules are unbuilt.

**Files:**
- Modify: `resources/views/livewire/academics/settings/academic-settings.blade.php:15-26`
- Create: `tests/Feature/Academics/SettingsSubnavTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../Reporting/P13MoneyHelpers.php';

uses(RefreshDatabase::class);

it('routes the sub-nav entries whose screens exist', function (): void {
    $response = $this->actingAs(p13moneyUserAs(Role::Administrator))->get('/academics/settings');

    $response->assertOk();
    $response->assertSee('href="/subjects"', escape: false);
    $response->assertSee('href="/classes"', escape: false);
});
```

- [ ] **Step 2: Run and watch it fail**

```bash
DB_DATABASE=opeschool_scratch_p8 "$PHP" artisan test tests/Feature/Academics/SettingsSubnavTest.php
```

Expected: FAIL.

- [ ] **Step 3: Point the live entries at the screens that exist**

In `resources/views/livewire/academics/settings/academic-settings.blade.php`, in the `$subnav` array, set `'live' => true` and add an `href` for the four entries whose screens exist — `subjects` → `/subjects`, `classes` → `/classes`, `term` → `/academics/settings#term-structure`, `grading` → `/academics/settings#grading`:

```blade
    @php
        // A nav that is 90% dead is the loudest "amateur" signal in the
        // product. Four of these nine were disabled while their screens sat
        // in the sidebar; the five that remain disabled are genuinely
        // unbuilt and keep the existing aria-disabled convention.
        $subnav = [
            ['key' => 'academic', 'href' => '/academics/settings', 'live' => true],
            ['key' => 'subjects', 'href' => '/subjects', 'live' => true],
            ['key' => 'classes', 'href' => '/classes', 'live' => true],
            ['key' => 'term', 'href' => '/academics/settings#term-structure', 'live' => true],
            ['key' => 'grading', 'href' => '/academics/settings#grading', 'live' => true],
            ['key' => 'general', 'href' => null, 'live' => false],
            ['key' => 'admission', 'href' => null, 'live' => false],
            ['key' => 'examination', 'href' => null, 'live' => false],
            ['key' => 'promotion', 'href' => null, 'live' => false],
            ['key' => 'holiday', 'href' => null, 'live' => false],
        ];
    @endphp
```

and render the live ones as `<a href="{{ $item['href'] }}">`.

- [ ] **Step 4: Run the test, then look**

```bash
DB_DATABASE=opeschool_scratch_p8 "$PHP" artisan test tests/Feature/Academics/SettingsSubnavTest.php
```

Screenshot `/academics/settings` at 1440×900. Expected: five live entries, five greyed — not one live and nine grey.

- [ ] **Step 5: Commit**

```bash
git add resources/views/livewire/academics/settings/academic-settings.blade.php tests/Feature/Academics/SettingsSubnavTest.php
git commit -m "fix(academics): route the settings sub-nav entries whose screens already exist"
```

---

### Task 35: A staff profile screen, so `/staff` stops being a dead end

18 staff rows, no detail page, and no `/staff/{staff}` route at all. The only row control is `togglePortalAccessForm(id)` — rendered as a three-line-tall "Enable / portal / access" button that forces 88px rows, the tallest table in the app. Payroll, by contrast, has `/payroll/runs/{run}`.

**Files:**
- Create: `app/Modules/HR/Livewire/StaffShow.php`
- Create: `resources/views/livewire/hr/staff-show.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/livewire/hr/index.blade.php`
- Create: `tests/Feature/HR/StaffShowTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Reporting/P13MoneyHelpers.php';

uses(RefreshDatabase::class);

it('opens a staff member from the directory', function (): void {
    $staffId = (int) DB::table('staff_members')->insertGetId([
        'staff_no' => 'STF-2026-001', 'first_name' => 'Awa', 'last_name' => 'Bello',
        'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs(p13moneyUserAs(Role::HrOfficer))
        ->get('/staff/'.$staffId)
        ->assertOk()
        ->assertSee('Awa');
});

it('links every directory row at its own record', function (): void {
    DB::table('staff_members')->insert([
        'staff_no' => 'STF-2026-002', 'first_name' => 'Bertrand', 'last_name' => 'Atangana',
        'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs(p13moneyUserAs(Role::HrOfficer))
        ->get('/staff')
        ->assertOk()
        ->assertSee('/staff/', escape: false);
});
```

Fill in any additional NOT NULL columns from `database/migrations/*staff*`.

- [ ] **Step 2: Run and watch both fail**

```bash
DB_DATABASE=opeschool_scratch_p8 "$PHP" artisan test tests/Feature/HR/StaffShowTest.php
```

Expected: 2 failures — 404 and no row link.

- [ ] **Step 3: Build the screen**

Create `app/Modules/HR/Livewire/StaffShow.php` following the shape of `app/Modules/Payroll/Livewire/Show.php` — `#[Layout('layouts.app')]`, `Gate::authorize(HrPermission::VIEW)` in `mount()`, and `DB::table` reads only (the module-boundary rule from Phase 0 Task 2 applies: **no Identity, Payroll or Assessment model imports**). Show: identity, contract, leave balance, recent payslips, documents. Create the matching view under `resources/views/livewire/hr/staff-show.blade.php`.

Route it beside the directory in `routes/web.php`:

```php
    Route::get('/staff/{staff}', \App\Modules\HR\Livewire\StaffShow::class)
        ->middleware('can:staff.view')->whereNumber('staff')->name('hr.staff.show');
```

- [ ] **Step 4: Link the rows and drop the three-line button**

In `resources/views/livewire/hr/index.blade.php`, make the name cell an anchor and replace the "Enable portal access" row button with a single icon + overflow control using `x-btn variant="ghost"` from Task 30:

```blade
                    <td class="px-3 py-2">
                        <a href="{{ route('hr.staff.show', $row->id) }}" class="font-medium text-primary hover:underline">
                            {{ $row->first_name }} {{ $row->last_name }}
                        </a>
                    </td>
```

- [ ] **Step 5: Run the tests, then look**

```bash
DB_DATABASE=opeschool_scratch_p8 "$PHP" artisan test tests/Feature/HR/StaffShowTest.php --testsuite=Architecture
```

Screenshot `/staff` and `/staff/1` at 1440×900. Expected: rows drop from 88px to the ~48px the rest of the product uses, and the three-line button is gone.

- [ ] **Step 6: Commit**

```bash
git add app/Modules/HR/Livewire/StaffShow.php resources/views/livewire/hr/staff-show.blade.php resources/views/livewire/hr/index.blade.php routes/web.php tests/Feature/HR/StaffShowTest.php
git commit -m "feat(hr): add a staff profile screen and stop the directory being a dead end"
```

---

### Task 36: The deterministic small ones

Five findings that each cost minutes and each remove a visible defect.

**Files:**
- Modify: `app/Modules/Guardians/Livewire/Pta/Index.php`
- Modify: `app/Modules/Reporting/Livewire/Reports/Hub.php:106`
- Modify: `resources/views/livewire/identity/users/index.blade.php`
- Modify: `resources/views/livewire/inventory/index.blade.php`
- Modify: `resources/views/livewire/fees/invoices/index.blade.php`
- Create: `tests/Feature/Ui/LongTailTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

use App\Modules\Guardians\Livewire\Pta\Index as PtaIndex;
use Livewire\Attributes\Url;

it('persists the PTA tab in the URL like every other tabbed screen', function (): void {
    $property = new ReflectionProperty(PtaIndex::class, 'tab');

    expect($property->getAttributes(Url::class))->not->toBeEmpty();
});

it('filters the reports hub by permission, not by route existence', function (): void {
    $source = (string) file_get_contents(app_path('Modules/Reporting/Livewire/Reports/Hub.php'));

    expect($source)->toContain('Gate::allows');
});

it('does not print raw snake_case role names as pills', function (): void {
    $blade = (string) file_get_contents(resource_path('views/livewire/identity/users/index.blade.php'));

    expect($blade)->toContain("__('opes.roles.'");
});
```

- [ ] **Step 2: Run and watch all three fail**

```bash
DB_DATABASE=opeschool_scratch_p8 "$PHP" artisan test tests/Feature/Ui/LongTailTest.php
```

Expected: 3 failures.

- [ ] **Step 3: Add `#[Url]` to the PTA tab**

30 of 31 components declaring `public string $tab` carry `#[Url]`; `app/Modules/Guardians/Livewire/Pta/Index.php` does not, so its Meetings/Officers tabs are neither linkable nor bookmarkable:

```php
    #[Url]
    public string $tab = 'meetings';
```

Add `use Livewire\Attributes\Url;`.

- [ ] **Step 4: Gate the reports hub**

In `app/Modules/Reporting/Livewire/Reports/Hub.php:106`, replace the `Route::has()` filter:

```php
        // Route::has() answers "does this screen exist", which is not the
        // question. A role with reports.view but without ledger.view was
        // shown a Financial Reports card that 403s the moment they click it -
        // the exact inverse of the nav/route contract the sidebar keeps by
        // construction.
        return array_filter(
            $items,
            static fn (array $item): bool => Route::has($item['route']) && Gate::allows($item['permission']),
        );
```

Add a `permission` key to each hub item, matching the permission its route's `can:` middleware declares, and `use Illuminate\Support\Facades\Gate;`.

- [ ] **Step 5: Label the role pills**

In `resources/views/livewire/identity/users/index.blade.php`, replace the raw value with `{{ __('opes.roles.'.$role) }}` and add `roles.super_admin`, `roles.payroll_officer`, `roles.hr_officer` (and every other seeded role name) to both language files.

- [ ] **Step 6: Format inventory counts and drop the empty column**

In `resources/views/livewire/inventory/index.blade.php`, replace the raw `90.000` rendering with an integer format for whole-unit items:

```blade
                        {{ fmod((float) $row->on_hand, 1.0) === 0.0 ? number_format((float) $row->on_hand) : rtrim(rtrim(number_format((float) $row->on_hand, 3), '0'), '.') }}
```

In `resources/views/livewire/fees/invoices/index.blade.php`, delete the `TERM` column header and its (always-empty) cell.

- [ ] **Step 7: Run the tests and the whole localisation check**

```bash
DB_DATABASE=opeschool_scratch_p8 "$PHP" artisan test tests/Feature/Ui/LongTailTest.php tests/Feature/LocalisationTest.php
```

Expected: all pass.

- [ ] **Step 8: Look at it**

Screenshot `/users`, `/inventory`, `/finance/invoices` and `/reports` at 1440×900. Expected: role pills read as labels, "90" not "90.000", no empty `TERM` column.

- [ ] **Step 9: Commit**

```bash
git add app/Modules/Guardians/Livewire/Pta/Index.php app/Modules/Reporting/Livewire/Reports/Hub.php resources/views/livewire/identity/users/index.blade.php resources/views/livewire/inventory/index.blade.php resources/views/livewire/fees/invoices/index.blade.php lang/en/opes.php lang/fr/opes.php tests/Feature/Ui/LongTailTest.php
git commit -m "fix(ui): PTA tab URL, permission-gated reports hub, role labels, integer counts, dead column"
```

---

# Recorded, not scheduled

These are findings the audits produced that this plan deliberately does **not** turn into tasks. They are recorded so the next person does not have to rediscover them, and so a decision to skip them is a decision rather than an oversight.

## R1. The Feature suite has no trustworthy baseline

**Unit + Architecture ran to completion: 261 tests, 259 passed, 2 failed.** Both failures are real, deterministic and fixed by Phase 0 Tasks 2 and 3.

**The Feature suite — 258 of the 279 test files — did NOT complete.** It was killed after an hour with zero output, on a contended machine. **Nothing that suite might eventually report should be treated as signal until it has been re-run solo and quiet.**

Two independent observations, of very different quality:

- **Solid, and independent of contention:** MySQL's process list showed **more than one full `migrate:fresh` cycle** on the scratch schema. Connection `11877` was midway through applying migrations (`alter table document_print_logs add constraint fk_print_logs_template`); roughly ten minutes later connection `11898` was executing a `drop table` of the whole schema, immediately followed by `11904` re-applying migrations from the start. The connection IDs increase monotonically through migrate → drop-all → migrate. A single `migrate:fresh` drops and then migrates; it does not migrate, then drop, then migrate again. 245 of the 279 files use `RefreshDatabase`, whose contract is `migrate:fresh` **once per process**.
- **Unreliable, and stated as such:** the wall-clock cost. The schema holds 284 tables across 225 migrations at roughly 0.5 migrations/second — about 7–8 minutes per cycle before a single test body runs. But the same MySQL instance was simultaneously serving another project's suite (`meritra_laravel_test`, actively creating tables) and an unrelated `composer update --with-all-dependencies` (PID 4516) was rewriting `vendor/` mid-run. **Treat the timing as noise; treat the repeated-cycle evidence as real.**

Where to look when someone picks this up: whether a test calls `migrate:fresh` explicitly (there is a comment acknowledging the once-per-process contract at `tests/Feature/Assessment/PublicationTest.php:79`), or whether a second connection or a `RefreshDatabaseState` reset is defeating the cache. `tests/Feature/Assessment/PublicationTest.php` deliberately does not use `RefreshDatabase` and truncates instead — that file is the first place to look, and this plan's Task 10 moves its helpers into a shared file, which is a good moment to check.

**Before treating any Feature failure as real, run:** `DB_DATABASE=opeschool_scratch_full "$PHP" artisan test --testsuite=Feature` on a machine with nothing else running, and record the pass/fail counts here.

## R2. The untested band — where all seven crashes lived

The 279-file suite is written at the **Action / domain layer**. The Livewire presentation layer — reactive `updated*` handlers, row actions and export buttons — is essentially untested. `grep` over `tests/` finds **zero** references to:

- `startAmend`, `updatedFormPurchaseOrderId`, `updatedCreditNoteInvoiceId` — the three 500s — despite **20 procurement test files**;
- any `export*Pdf` / `exportAssetCardPdf` / `exportLibraryCardPdf` action, despite **12 asset and 5 library test files**;
- any asserted download filename (`asset-card-`, `library-card-`, `purchase-order-`);
- `PrintReportCard` or the `report-cards` print route.

**Every one of the seven confirmed crashes lives in exactly that band.** This plan adds targeted tests for each crash it fixes (Tasks 4, 5, 6, 7, 10, 11), which closes the specific holes but not the class.

**Recommended, not scheduled — two changes would close the band itself:**

1. **A sweep test for `updated*` handlers and `export*` actions.** One loop that mounts each Livewire component, calls every `updated*` handler with a plausible value and every `export*` action, and asserts no 500. It would have caught **six of the seven** in a single pass; the audit's throwaway harness is a working proof (145 components mounted, 503 action calls, 340 `updated*` calls). Cost is one test file; the payoff is that this whole defect class stops being invisible.
2. **Production-shaped identifiers in fixtures, and seed the empty modules.** A fixture using `AST1` where production uses `AST/000001` cannot catch the filename family **at all** — which is why nine call sites shipped unsanitised. Five tables are empty in the dev database (`expenses`, `supplier_invoices`, `supplier_payments`, `tax_declarations`, `withholding_attestations`), so six detail routes could not be exercised by any sweep and no fixture covers them either. **Three of the seven crashes were in Procurement, the most data-starved module.** That is not a coincidence worth ignoring.

## R3. Latent enum-in-string sites

The static sweep found **no further live instances** of the discipline-case bug class fixed in `c1ccba2` — 28 concatenation hits, 117 array-offset hits and 200 string-comparison hits on enum-cast property names, and every one traced to a `DB::table` row (`stdClass`), where the Eloquent cast does not apply. That is a real negative result, not a failure to look.

But they become crashes **the moment the backing query is refactored to Eloquent**. The highest-risk cluster, worth a comment or a `->value` today:

`resources/views/livewire/procurement/payments/show.blade.php:37,38` and `supplier-invoices/show.blade.php:45,46` — `$payTone[$payment->status]`, `str_replace('_',' ', $payment->clearing_state)`, `$invoice->match_status`. `SupplierPayment` and `SupplierInvoice` enum-cast every one of those columns, both screens are `DB::table`-backed *today*, and **both tables are empty**, so nothing would catch the regression.

Also: `welfare/insurance/policy-show.blade.php:44,46,85,193,254`; `welfare/transport/vehicle-show.blade.php:43,219,378,420`; `welfare/hostel/room-show.blade.php:32,82,216`; `attendance/index.blade.php:114,122`; `operations/rollover-wizard.blade.php:420`.

## R4. Print routes mutate on GET

Every first print issues an `IssuedDocument` and consumes a series serial. An 80-request read-only sweep took `issued_documents` from 12 → 86. This is correct by design (10-documents §4.4), but a prefetching browser, a duplicated tab or a crawler burns statutory document numbers. Worth issuing on an explicit POST, or making the idempotency key mandatory on those controllers. Not scheduled because it is a design change with compliance implications, not a defect.

## R5. Filesystem-touching delete paths remain unaudited

147 delete / archive / void / cancel / toggle invocations ran clean inside rolled-back transactions — but Documents, Branding, Import, Backups, Photo and BulkPrints were excluded, because a rollback cannot undo an `unlink`. Those delete paths have never been exercised by anything.

## R6. Not covered by any audit

- **No POST/write flow was submitted** anywhere — no form posted, no wizard advanced, no record created. "A create form with no post-create destination" was assessed statically only.
- **The 10-step rollover wizard and the promotion wizard** were fetched (200, step bars render) but never driven through their steps.
- **Roles never exercised:** `staff_portal`, vice-principal, super administrator, and roughly 14 other seeded roles.
- **The orphan set is a lower bound.** ~90 URLs returned `ERR` mid-crawl from connection exhaustion, so their outbound links were never harvested. Every listed orphan was additionally confirmed by grep, but there may be more.

---

# Self-review

**Spec coverage.** All eight requested priorities have tasks: crashes grouped by root cause (Tasks 4–8, with the two shared causes fixed once each), report cards (9–11, with the design argued and the already-stranded documents recovered), KPI `icon-bg`→`tone` (12–14, from a real survey of all nine literal values), `school_document_profiles` (15–17, plus the SPECIMEN fiscal identity), account screen and settings hub (18–20), the 17 orphans (21–26), component visual (27–32), long tail (33–36). The two architecture regressions the coordinator reported are Tasks 2–3. Test coverage and the unfinished suite are recorded in R1/R2 rather than scheduled, as instructed.

**Placeholder scan.** No "TBD", no "add error handling", no "similar to Task N". Where a task cannot name a line it does not yet know (the readiness-check array in Task 25, the payroll row markup in Task 8, the accountant's existing Actions in Task 32), it gives the exact `grep` that finds it and the exact code to write once found — rather than guessing at a file's contents and shipping a plan whose code does not apply.

**Type consistency.** `render_envelope` is the same shape — `array{subject_label: string, school: array<string, mixed>}` — in the migration, the model docblock, `renderSnapshotBacked`, `issueOriginal`, `schoolChrome`, `rebuildEnvelope` and `FreezeEnvelopeFromPrintLog`. `FindUserIdByEmail::handle(): ?int` is consumed identically in Tasks 2 and 3. `ProvisionPortalUser::handle()`'s two new parameters are optional and defaulted, so Guardians' existing call is untouched. `x-btn`'s five variants are the same five in the component, the test and both callers.



