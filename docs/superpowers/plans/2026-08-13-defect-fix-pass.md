# Concrete Defect Fix Pass — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix three concrete, root-caused defects found by a live QA pass over opeschool-cloud: (1) money-document reprints crash permanently after a legitimate identity correction, (2) cashiers can never reach the already-built thermal receipt format, (3) the fully-built staff self-service portal has no way for any staff member to ever get access to it.

**Architecture:** No new subsystems. Task 1 closes a real gap between this codebase's own documented invariant ("the immutable payload... NEVER re-queries live tables", `database/migrations/2026_08_09_310003_create_issued_documents_table.php:42-44`) and what `RenderDocument` actually does for "receipt pattern" templates. Task 2 exposes an existing controller parameter in the UI. Task 3 adds the one missing link (`staff_members.portal_user_id`) in an otherwise-complete feature, following the existing Guardian-portal-activation precedent but admin-mediated (this platform sends no email).

**Tech Stack:** Laravel 13 / Livewire 4 / Pest, MySQL 8.4, `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe` (PHP is not on PATH).

**How this plan was scoped:** a background live-QA agent browsed the app as Guardian/Accountant/Administrator and hit a real HTTP 500 on `GET /finance/payments/1/receipt`. Root-caused (systematic-debugging Phase 1-2) to `app/Modules/Reporting/Actions/RenderDocument.php`: for templates with no `SnapshotSourceMap` entry, the "snapshot" payload is re-derived from live joined tables on every render — including reprints — rather than frozen at issue time. `payments`/`payment_allocations`/`suppliers` etc. are mutable after issue, so any legitimate later correction changes the recomputed hash and permanently breaks that document's reprintability with a crash instead of reproducing history. Two further gaps were confirmed directly in code (not from the stale `docs/specs/2026-08-12-module-gap-analysis.md`, which predates recent fixes and was wrong about at least one other claim — see "false positives ruled out" below).

**False positives ruled out during scoping (do not "fix" these):** the guardian portal's Fees screen invoice/receipt rows already link correctly (`resources/views/livewire/guardians/portal/fees.blade.php:126-155` via `x-portal.row`'s `href`); the QA agent's dead-link claim was a grep miss on the route name (singular `portal.children.invoice`, not plural). The guardian portal never getting a receipt/report-card PDF is deliberate, documented architecture (`resources/views/livewire/guardians/portal/receipt.blade.php:46-48`, `academics.blade.php:278-280`) — RenderDocument gates on the staff-only `documents.print` permission by design; guardians see the verification number instead. Do not add a download button there.

---

### Task 1: Freeze the render payload at issue time so reprints never crash

**Files:**
- Create: `database/migrations/2026_08_13_320001_add_payload_snapshot_to_issued_documents.php`
- Modify: `app/Modules/Reporting/Models/IssuedDocument.php`
- Modify: `app/Modules/Reporting/Actions/RenderDocument.php:202-234,658-691`
- Test: `tests/Feature/Reporting/DocumentPayloadSnapshotTest.php`

- [ ] **Step 1: Write the failing test proving the bug**

```php
<?php

declare(strict_types=1);

use App\Modules\Fees\Actions\PrintReceipt;
use App\Modules\Identity\Domain\Role;
use App\Modules\Students\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Accounting/AccountingTestHelpers.php';
require_once __DIR__.'/P13MoneyHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    p13moneyDocumentProfile();
    p13moneyConfirmedFiscalIdentity();
});

it('reprints the name recorded at issue, even after the student is later renamed', function (): void {
    $cashier = p13moneyUserAs(Role::Bursar, Role::Accountant);
    $cal = ledgerCalendar('2031-03-15');
    p13moneySaveCashPaymentRule($cashier);

    $studentId = Student::factory()->create(['first_name' => 'Carine', 'last_name' => 'Ndongo'])->id;
    $payment = p13moneyRecordCash($studentId, null, $cal, $cashier, 25_000);

    $original = app(PrintReceipt::class)->handle($payment->id);
    expect($original->html)->toContain('Carine Ndongo');

    // A legitimate later correction - e.g. a misspelled name fixed at the
    // front desk - must not break the ability to reprint what was already
    // issued.
    DB::table('students')->where('id', $studentId)->update(['first_name' => 'Karine']);

    $reprint = app(PrintReceipt::class)->handle($payment->id);
    expect($reprint->html)->toContain('Carine Ndongo');
    expect($reprint->html)->not->toContain('Karine Ndongo');
    expect($reprint->isDuplicate)->toBeTrue();
});
```

- [ ] **Step 2: Run it to confirm it fails with the real bug**

Run: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter "reprints the name recorded at issue"`
Expected: FAIL — `DocumentReproducibilityViolation: Reprint of issued document ... produced content hash ... where ... was recorded at issue.`

- [ ] **Step 3: Add the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/10-documents.md 4.2/4.4 promises "the immutable payload it
 * renders from... It NEVER re-queries live tables" - true only for
 * templates with a registered SnapshotSourceMap entry. "Receipt pattern"
 * templates (FEE-RECEIPT, FEE-RECEIPT-POS, FEE-INVOICE, WHT-CERT,
 * PAY-VOUCHER) had no registered source, so RenderDocument re-derived their
 * payload from live joins (students/suppliers/payment_allocations) on every
 * render, including reprints. A legitimate later correction (a misspelled
 * name fixed, a payment re-allocated) then permanently broke that
 * document's reprintability with a DocumentReproducibilityViolation crash
 * instead of reproducing history. This column freezes the payload actually
 * rendered at issue so a reprint reads it back instead of re-deriving it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issued_documents', function (Blueprint $table): void {
            $table->json('payload_snapshot')->nullable()->after('snapshot_id');
        });
    }

    public function down(): void
    {
        Schema::table('issued_documents', function (Blueprint $table): void {
            $table->dropColumn('payload_snapshot');
        });
    }
};
```

- [ ] **Step 4: Run the migration on the test DB**

Run: `DB_DATABASE=opeschool_test C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan migrate --force`
Expected: `2026_08_13_320001_add_payload_snapshot_to_issued_documents ....... DONE`

- [ ] **Step 5: Add the column to `IssuedDocument`**

In `app/Modules/Reporting/Models/IssuedDocument.php`, add `payload_snapshot` to the docblock, `$fillable`, and `casts()`. Leave it OUT of the `booted()` `$mutable` list (it must stay append-only, same as `content_hash`).

```php
 * @property array<string, mixed>|null $payload_snapshot
 * @property string $language
```
(insert the new `@property` line directly above the existing `@property string $language` line)

```php
    protected $fillable = [
        'document_template_id', 'template_version',
        'series_code', 'serial',
        'subject_type', 'subject_id',
        'snapshot_type', 'snapshot_id', 'payload_snapshot',
        'language', 'content_hash', 'qr_token',
        'issued_by', 'issued_at', 'issued_by_name_at_time',
        'status', 'revoked_by', 'revoked_at', 'revoked_reason',
        'superseded_by_document_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'template_version' => 'integer',
            'subject_id' => 'integer',
            'snapshot_id' => 'integer',
            'payload_snapshot' => 'array',
            'issued_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
```

- [ ] **Step 6: Make `loadSnapshot` read the frozen payload on reprint**

In `app/Modules/Reporting/Actions/RenderDocument.php`, change the `loadSnapshot` signature and its receipt-pattern branch:

```php
    /**
     * @param  array<string, mixed>  $callerData
     * @return array{payload: array<string, mixed>, version: int|null}
     */
    private function loadSnapshot(
        DocumentTemplate $template,
        int $snapshotId,
        array $callerData,
        ?IssuedDocument $issued,
    ): array {
        $source = $template->snapshot_source;

        if ($source !== null && SnapshotSourceMap::has($source)) {
            $map = SnapshotSourceMap::get($source);

            $row = DB::table($map['table'])->where('id', $snapshotId)->first();

            if ($row === null) {
                throw ValidationException::withMessages([
                    'snapshot_id' => "Snapshot {$snapshotId} does not exist in [{$map['table']}]; "
                        .'a snapshot-backed document renders only from an existing immutable payload (10-documents 4.2).',
                ]);
            }

            /** @var mixed $decoded */
            $decoded = json_decode((string) $row->{$map['payload']}, true);
            $version = $map['version'] !== null && is_numeric($row->{$map['version']} ?? null)
                ? (int) $row->{$map['version']}
                : null;

            return [
                'payload' => is_array($decoded) ? $decoded : [],
                'version' => $version,
            ];
        }

        // The receipt pattern: no registered snapshot table exists, so the
        // payload the caller assembled at ORIGINAL issue is what gets
        // frozen onto the IssuedDocument row (see issueOriginal() below). A
        // reprint reads that frozen payload back instead of re-deriving it
        // from live joins - which is what makes the reprint reproducible
        // even after a later, legitimate correction to the source rows.
        if ($issued !== null && $issued->payload_snapshot !== null) {
            return ['payload' => $issued->payload_snapshot, 'version' => null];
        }

        return ['payload' => $callerData, 'version' => null];
    }
```

Update the two call sites. In `renderSnapshotBacked()`:

```php
        $snapshot = $this->loadSnapshot($template, $snapshotId, $data, $issued);
```

(replacing the existing `$snapshot = $this->loadSnapshot($template, $snapshotId, $data);` — `$issued` is already resolved a few lines above it in that method.)

In `issueOriginal()`, freeze the payload used for the original render onto the row. Add the freeze condition and pass it through to `IssuedDocument::query()->create([...])`:

```php
        /** @var IssuedDocument $issued */
        $issued = IssuedDocument::query()->create([
            'document_template_id' => $template->getKey(),
            'template_version' => $template->version,
            'series_code' => $seriesCode,
            'serial' => $serial,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'snapshot_type' => $template->snapshot_source ?? $subjectType,
            'snapshot_id' => $snapshotId,
            // Only the receipt-pattern branch needs this: a registered
            // SnapshotSourceMap table (e.g. ReportCardSnapshot) is already
            // its own immutable payload, so freezing it again here would
            // just duplicate storage for no benefit.
            'payload_snapshot' => ($template->snapshot_source !== null && SnapshotSourceMap::has($template->snapshot_source))
                ? null
                : $snapshot['payload'],
            'language' => $lang->value,
            'content_hash' => $hash,
            'qr_token' => null, // D2 wires the OPES1 signing stack (10-documents 17).
            'issued_by' => $actor->id,
            'issued_at' => $issuedAt,
            'issued_by_name_at_time' => $actor->name,
            'status' => IssuedDocument::STATUS_VALID,
        ]);
```

- [ ] **Step 7: Run the test again to confirm it passes**

Run: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter "reprints the name recorded at issue"`
Expected: PASS

- [ ] **Step 8: Run the existing receipt/invoice/statement/voucher/attestation render suites to confirm nothing broke**

Run: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test tests/Feature/Reporting/`
Expected: all green, including `ReceiptRenderTest`'s existing DUPLICATA/VOID/POS-variant assertions (they still pass because the frozen payload for a payment issued and never mutated is byte-identical to what live re-derivation would have produced).

- [ ] **Step 9: PHPStan**

Run: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe vendor/bin/phpstan analyse --memory-limit=1G`
Expected: 0 errors.

- [ ] **Step 10: Live-verify the originally-reported crash is fixed**

Using the Browser pane against the running `opes` preview (`php artisan serve --port=8931`), log in as Accountant/Administrator via the demo dropdown, navigate to `http://localhost:8931/finance/payments/1/receipt`. Expected: PDF streams with 200 (previously 500). Take a screenshot.

- [ ] **Step 11: Commit**

```bash
git add database/migrations/2026_08_13_320001_add_payload_snapshot_to_issued_documents.php app/Modules/Reporting/Models/IssuedDocument.php app/Modules/Reporting/Actions/RenderDocument.php tests/Feature/Reporting/DocumentPayloadSnapshotTest.php
git commit -m "fix(documents): freeze the receipt-pattern payload at issue so a later correction cannot break reprinting"
```

---

### Task 2: Let a cashier actually reach the thermal (POS) receipt

**Files:**
- Modify: `resources/views/livewire/fees/cashier.blade.php:51-56`
- Modify: `lang/en/opes.php:1491`, `lang/fr/opes.php:1476`
- Test: `tests/Feature/Fees/CashierReceiptVariantTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Fees\Livewire\Cashier;
use App\Modules\Identity\Domain\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__.'/../Accounting/AccountingTestHelpers.php';
require_once __DIR__.'/../Reporting/P13MoneyHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    p13moneyDocumentProfile();
    p13moneyConfirmedFiscalIdentity();
});

it('offers both the A5 and the thermal receipt after recording a payment', function (): void {
    $cashier = p13moneyUserAs(Role::Bursar, Role::Accountant);
    $cal = ledgerCalendar('2031-03-15');
    p13moneySaveCashPaymentRule($cashier);

    $payment = p13moneyRecordCash(\App\Modules\Students\Models\Student::factory()->create()->id, null, $cal, $cashier, 10_000);

    Livewire::actingAs($cashier)
        ->test(Cashier::class)
        ->set('lastPaymentId', $payment->id)
        ->set('receiptNo', $payment->receipt_no)
        ->assertSeeHtml(route('fees.payments.receipt', ['payment' => $payment->id]))
        ->assertSeeHtml(route('fees.payments.receipt', ['payment' => $payment->id, 'variant' => 'pos']));
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter "offers both the A5 and the thermal receipt"`
Expected: FAIL — the `variant=pos` URL is not found in the rendered HTML.

- [ ] **Step 3: Add the second print link**

In `resources/views/livewire/fees/cashier.blade.php`, replace lines 48-56:

```blade
                {{-- Phase 13 D3 (10-documents §10.1): the real receipt
                     template now exists in two formats - Print opens the A5
                     PDF, Print (thermal) the 80mm POS variant, both in a
                     new tab; each request re-authorizes documents.print
                     itself. --}}
                @if (is_int($lastPaymentId))
                    <a href="{{ route('fees.payments.receipt', ['payment' => $lastPaymentId]) }}" target="_blank" rel="noopener"
                       class="inline-flex items-center gap-1 text-sm font-medium text-primary underline hover:no-underline">
                        {{ __('opes.fees_screen.print_receipt') }}
                    </a>
                    <a href="{{ route('fees.payments.receipt', ['payment' => $lastPaymentId, 'variant' => 'pos']) }}" target="_blank" rel="noopener"
                       class="inline-flex items-center gap-1 text-sm font-medium text-primary underline hover:no-underline">
                        {{ __('opes.fees_screen.print_receipt_pos') }}
                    </a>
                @endif
```

- [ ] **Step 4: Add the lang keys**

In `lang/en/opes.php`, directly below the `'print_receipt' => 'Print receipt',` line:

```php
        'print_receipt_pos' => 'Print thermal receipt',
```

In `lang/fr/opes.php`, directly below the `'print_receipt' => 'Imprimer le reçu',` line:

```php
        'print_receipt_pos' => 'Imprimer le reçu thermique',
```

- [ ] **Step 5: Run the test again to confirm it passes**

Run: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter "offers both the A5 and the thermal receipt"`
Expected: PASS

- [ ] **Step 6: Live-verify**

Log in as Bursar/Accountant, go to Finance → Cashier, record a cash payment against any student, confirm both "Print receipt" and "Print thermal receipt" links appear and each opens a real PDF (A5 vs a narrow 80mm layout). Screenshot both.

- [ ] **Step 7: Commit**

```bash
git add resources/views/livewire/fees/cashier.blade.php lang/en/opes.php lang/fr/opes.php tests/Feature/Fees/CashierReceiptVariantTest.php
git commit -m "feat(fees): expose the thermal receipt format on the cashier screen"
```

---

### Task 3: Give staff a way to actually reach their own portal

**Files:**
- Create: `app/Modules/HR/Actions/GrantStaffPortalAccess.php`
- Modify: `app/Modules/HR/Livewire/Index.php`
- Modify: `resources/views/livewire/hr/index.blade.php:174,190-191,198-206,254-259`
- Modify: `config/opes.php` (demo_login.identities)
- Modify: `database/seeders/DemoDataSeeder2.php`
- Test: `tests/Feature/HR/GrantStaffPortalAccessTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\HR\Actions\GrantStaffPortalAccess;
use App\Modules\HR\Actions\HireStaffMember;
use App\Modules\Identity\Actions\CreateUser;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function hrTestAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(Role::Administrator->value);

    return $admin;
}

it('grants a staff member their own portal login and links staff_members.portal_user_id', function (): void {
    $admin = hrTestAdmin();
    $this->actingAs($admin);

    $staffMember = app(HireStaffMember::class)->handle(
        firstName: 'Jean',
        lastName: 'Fotso',
        gender: 'male',
        dateOfBirth: '1990-01-01',
        phone: '677000000',
        hiredOn: '2026-01-01',
        email: null,
    );

    $result = app(GrantStaffPortalAccess::class)->handle(
        (int) $staffMember->id,
        'jean.fotso@opeschool.test',
        $admin,
    );

    expect($result['user']->hasRole(Role::StaffPortal->value))->toBeTrue();
    expect(DB::table('staff_members')->where('id', $staffMember->id)->value('portal_user_id'))
        ->toBe($result['user']->id);
});

it('refuses to grant portal access twice to the same staff member', function (): void {
    $admin = hrTestAdmin();

    $staffMember = app(HireStaffMember::class)->handle(
        firstName: 'Awa', lastName: 'Bello', gender: 'female', dateOfBirth: '1988-05-05',
        phone: '677000001', hiredOn: '2026-01-01', email: null,
    );

    app(GrantStaffPortalAccess::class)->handle((int) $staffMember->id, 'awa.bello@opeschool.test', $admin);

    expect(fn () => app(GrantStaffPortalAccess::class)->handle((int) $staffMember->id, 'awa2@opeschool.test', $admin))
        ->toThrow(DomainException::class, 'already has portal access');
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter "GrantStaffPortalAccess"`
Expected: FAIL — class `App\Modules\HR\Actions\GrantStaffPortalAccess` not found. (Check first whether `HireStaffMember::handle` returns the created staff row/model with an `id` property — read `app/Modules/HR/Actions/HireStaffMember.php` before writing this test; adjust `$staffMember->id` access to match its actual return type.)

- [ ] **Step 3: Write the Action**

```php
<?php

declare(strict_types=1);

namespace App\Modules\HR\Actions;

use App\Modules\HR\Domain\HrPermission;
use App\Modules\Identity\Actions\CreateUser;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * `/staff` row action - grants a staff member their own self-service portal
 * login (payslips, leave, timetable at `HR\Livewire\Portal\Show`, gated on
 * the `staff_portal` role). That screen and its context resolver
 * (`StaffPortalContext`, keyed on `staff_members.portal_user_id`) have
 * existed since Phase 12-13; nothing anywhere ever created the user or set
 * that column, so no staff member - demo or real - could ever reach the
 * portal built for them.
 *
 * Admin-mediated rather than self-activated (unlike Guardians'
 * IssuePortalInvitation/ActivatePortalAccount pair): this platform sends no
 * email (the login page says so explicitly), so there is no channel for an
 * invitation code to reach the staff member except the admin handing over
 * credentials directly - the same reason `Identity\Actions\CreateUser`
 * already sets a plain temporary password rather than emailing one.
 */
final class GrantStaffPortalAccess
{
    public function __construct(private readonly CreateUser $createUser) {}

    /**
     * @return array{user: User, temporary_password: string}
     */
    public function handle(int $staffMemberId, ?string $email, User $actor): array
    {
        Gate::authorize(HrPermission::MANAGE);

        /** @var object{id: int, first_name: string, last_name: string, email: string|null, portal_user_id: int|null}|null $staff */
        $staff = DB::table('staff_members')->where('id', $staffMemberId)
            ->first(['id', 'first_name', 'last_name', 'email', 'portal_user_id']);

        if ($staff === null) {
            throw new DomainException("Staff member {$staffMemberId} does not exist.");
        }

        if ($staff->portal_user_id !== null) {
            throw new DomainException('This staff member already has portal access.');
        }

        $resolvedEmail = ($email !== null && $email !== '') ? $email : $staff->email;

        if ($resolvedEmail === null) {
            throw ValidationException::withMessages([
                'email' => 'This staff member has no email on file; supply one to grant portal access.',
            ]);
        }

        if (User::query()->where('email', $resolvedEmail)->exists()) {
            throw ValidationException::withMessages([
                'email' => "A user with email {$resolvedEmail} already exists.",
            ]);
        }

        $password = Str::random(16).'Aa1!';

        return DB::transaction(function () use ($staff, $resolvedEmail, $password, $actor): array {
            $user = $this->createUser->handle(
                trim($staff->first_name.' '.$staff->last_name),
                $resolvedEmail,
                Role::StaffPortal,
                $password,
                $actor,
            );

            $user->forceFill(['must_change_password_at' => now()])->save();

            DB::table('staff_members')->where('id', $staff->id)->update([
                'portal_user_id' => $user->id,
                'updated_at' => now(),
            ]);

            return ['user' => $user, 'temporary_password' => $password];
        });
    }
}
```

- [ ] **Step 4: Run the test again to confirm it passes**

Run: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter "GrantStaffPortalAccess"`
Expected: PASS

- [ ] **Step 5: Wire the staff directory row action**

In `app/Modules/HR/Livewire/Index.php`, add new public state near the other per-row form state (after the `terminateLastWorkingDay` block):

```php
    // ── Grant staff portal access (per-row, Staff tab) ──────────────────
    public ?int $portalAccessStaffId = null;

    public string $portalAccessEmail = '';

    public ?string $portalAccessTemporaryPassword = null;
```

Add these methods near `saveTermination()`:

```php
    public function togglePortalAccessForm(int $staffMemberId): void
    {
        Gate::authorize(HrPermission::MANAGE);

        $this->portalAccessStaffId = $this->portalAccessStaffId === $staffMemberId ? null : $staffMemberId;
        $this->portalAccessEmail = '';
        $this->portalAccessTemporaryPassword = null;
    }

    public function grantPortalAccess(\App\Modules\HR\Actions\GrantStaffPortalAccess $grant): void
    {
        Gate::authorize(HrPermission::MANAGE);

        if ($this->portalAccessStaffId === null) {
            return;
        }

        /** @var \App\Modules\Identity\Models\User $actorUser */
        $actorUser = auth()->user();

        try {
            $result = $grant->handle($this->portalAccessStaffId, $this->portalAccessEmail === '' ? null : $this->portalAccessEmail, $actorUser);
        } catch (ValidationException $e) {
            $this->addError('portalAccess', implode(' ', $e->validator->errors()->all()));

            return;
        } catch (\DomainException $e) {
            $this->addError('portalAccess', $e->getMessage());

            return;
        }

        $this->portalAccessTemporaryPassword = $result['temporary_password'];
        session()->flash('status', 'Portal access granted.');
        $this->resetPage();
    }
```

In `staffRows()`, add `sm.portal_user_id` to the `->select([...])` list so the blade can check it:

```php
            ->select([
                'sm.id', 'sm.staff_no', 'sm.first_name', 'sm.last_name', 'sm.phone',
                'sm.email', 'sm.status', 'sm.portal_user_id',
            ])
```

- [ ] **Step 6: Add the row action and rail form in the blade**

In `resources/views/livewire/hr/index.blade.php`, add an Action header for the staff tab (line 174, directly after the Status `<th>`):

```blade
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Action</th>
```

Add the corresponding `<td>` to the staff branch (directly after line 206's closing `</td>` for the status pill):

```blade
                <td class="px-4 py-2.5">
                    @if ($row->portal_user_id === null)
                        <button type="button" wire:click="togglePortalAccessForm({{ $row->id }})"
                                class="rounded border border-primary/40 bg-primary/10 px-2 py-1 text-xs font-medium text-primary hover:bg-primary/20">
                            Enable portal access
                        </button>
                    @else
                        <span class="text-charcoal/40">Portal linked</span>
                    @endif
                </td>
```

Add the mobile-card equivalent inside the `@if ($tab === 'staff')` card block (after line 259's department/position line):

```blade
                    @if ($row->portal_user_id === null)
                        <button type="button" wire:click="togglePortalAccessForm({{ $row->id }})"
                                class="mt-2 rounded border border-primary/40 bg-primary/10 px-2 py-1 text-xs font-medium text-primary hover:bg-primary/20">
                            Enable portal access
                        </button>
                    @endif
```

Add the rail form, in `<x-slot:rail>`, alongside the existing Hire/Contract/Leave forms:

```blade
            @if ($portalAccessStaffId !== null)
                <section aria-label="Grant staff portal access" class="rounded border border-border-primary bg-white p-3">
                    <h3 class="mb-2 text-sm font-semibold text-charcoal">Enable Portal Access</h3>

                    @if ($portalAccessTemporaryPassword !== null)
                        <p class="mb-2 rounded border border-primary/40 bg-primary/10 p-2 text-xs text-charcoal">
                            Access granted. Temporary password (give this to the staff member; it is shown once):
                            <span class="mt-1 block font-mono text-sm font-bold text-charcoal">{{ $portalAccessTemporaryPassword }}</span>
                        </p>
                    @else
                        <div class="space-y-2">
                            <label class="flex flex-col gap-1 text-xs font-medium text-charcoal/70">
                                Portal login email
                                <input type="email" wire:model="portalAccessEmail" class="rounded border border-border-primary px-2 py-1.5 text-sm text-charcoal"/>
                            </label>
                            @error('portalAccess')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                        </div>
                        <button type="button" wire:click="grantPortalAccess" wire:confirm="Grant this staff member portal access?"
                                class="mt-3 w-full rounded bg-primary px-3 py-2 text-sm font-medium text-white hover:bg-primary/90">
                            Grant Access
                        </button>
                    @endif
                </section>
            @endif
```

- [ ] **Step 7: Run the full HR suite**

Run: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test tests/Feature/HR/`
Expected: all green.

- [ ] **Step 8: Wire a demo identity so the dropdown can show it**

In `database/seeders/DemoDataSeeder2.php`, add a new private method and call it from wherever `seedUsers()`/`seedGuardianPortal()` are invoked (find that call site first — likely `run()`):

```php
    private function seedStaffPortal(): void
    {
        $actor = Auth::user();

        if ($actor === null) {
            return;
        }

        if (User::query()->where('email', 'demo.staffportal@opeschool.test')->exists()) {
            return;
        }

        $staffId = DB::table('staff_members')->whereNull('portal_user_id')->orderBy('id')->value('id');

        if ($staffId === null) {
            return;
        }

        try {
            app(\App\Modules\HR\Actions\GrantStaffPortalAccess::class)->handle(
                (int) $staffId,
                'demo.staffportal@opeschool.test',
                $actor,
            );
        } catch (Throwable) {
        }
    }
```

Add the call `$this->seedStaffPortal();` next to the existing `$this->seedGuardianPortal();` call.

- [ ] **Step 9: Add the demo login identity**

In `config/opes.php`, inside `demo_login.identities`, add after the `guardian` entry:

```php
            // The staff portal (/portal/staff) has existed since
            // Phase 12-13 with a real Show screen (profile, timetable,
            // leave, payslip PDF), but nothing ever granted any user the
            // staff_portal role - demo.staffportal@opeschool.test is
            // created by DemoDataSeeder2::seedStaffPortal() via the same
            // GrantStaffPortalAccess Action a real admin now uses from
            // /staff.
            [
                'role' => 'staff_portal',
                'email' => 'demo.staffportal@opeschool.test',
                'name' => 'Demo Staff',
            ],
```

- [ ] **Step 10: Re-seed and verify**

Run: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan db:seed --class=DemoDataSeeder2 --force`
Expected: no errors; `demo.staffportal@opeschool.test` now exists with the `staff_portal` role and a linked `staff_members.portal_user_id`.

- [ ] **Step 11: Live-verify both the admin flow and the demo flow**

As Administrator: go to `/staff`, click "Enable portal access" on any row without a link, submit an email, confirm the temporary password banner appears and the row now shows "Portal linked". As the new demo "Staff" identity (via the login dropdown, now offering a Staff option): confirm `/portal/staff` loads with real profile/timetable/leave data and the payslip download works. Screenshot both.

- [ ] **Step 12: PHPStan and full suite**

Run: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe vendor/bin/phpstan analyse --memory-limit=1G`
Run: `DB_DATABASE=opeschool_test C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test` (SOLO — nothing else touching that DB, per this repo's standing testing rule)
Expected: 0 PHPStan errors; full suite green.

- [ ] **Step 13: Commit**

```bash
git add app/Modules/HR/Actions/GrantStaffPortalAccess.php app/Modules/HR/Livewire/Index.php resources/views/livewire/hr/index.blade.php config/opes.php database/seeders/DemoDataSeeder2.php tests/Feature/HR/GrantStaffPortalAccessTest.php
git commit -m "feat(hr): give admins a way to grant staff their own portal access, and demo it"
```

---

## Roadmap beyond this pass (not detailed here — separate plans)

This pass fixes the three concrete, root-caused defects found live. Two larger bodies of work the user also asked for are deliberately **not** planned in this document, per the writing-plans "Scope Check" (independent subsystems get their own plan):

- **Visual/design polish rollout** — apply the approved "liquid glass" frosted treatment (see memory `liquid-glass-design-direction`) from the desktop login screen to the platform sidebar, plus a general spacing/consistency pass. Self-contained CSS/Blade work; no backend changes. Should be its own plan once this fix pass is verified, so visual regressions aren't confused with functional ones.
- **Backlog module builds** — `docs/specs/2026-08-12-module-gap-analysis.md` §3 already has a prioritized order (Admissions depth → Activities → Curriculum → Alumni → receipt-format-family/recurring-journals/setup-wizard). That doc is 1 day stale as of this session (it claims only one receipt format exists; two do) — re-verify each claim live before planning off it, the same way this pass did. Each module in that list is its own multi-day plan, not a task in this one.
