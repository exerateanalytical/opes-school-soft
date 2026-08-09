<?php

declare(strict_types=1);

// Portal invitations end to end (docs/plans/phase-12-13.md 12.2, migration
// 2026_08_09_300003): issue -> hand over out of band -> activate -> the
// subject holds a portal account. Plus every way the code must FAIL, and
// fail generically.

use App\Modules\Guardians\Actions\ActivatePortalAccount;
use App\Modules\Guardians\Actions\IssuePortalInvitation;
use App\Modules\Guardians\Actions\RevokePortalInvitation;
use App\Modules\Guardians\Domain\PortalInvitationCode;
use App\Modules\Guardians\Domain\PortalSubjectType;
use App\Modules\Guardians\Models\Guardian;
use App\Modules\Guardians\Models\PortalInvitation;
use App\Modules\HR\Models\StaffMember;
use App\Modules\Identity\Domain\Permission as OpesPermission;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

if (! function_exists('p12gAdmin')) {
    /** A signed-in operator holding (or not) portal.manage. */
    function p12gAdmin(bool $withPermission = true): User
    {
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate(OpesPermission::PortalManage->value, 'web');

        $user = User::factory()->create();

        if ($withPermission) {
            $user->givePermissionTo(OpesPermission::PortalManage->value);
        }

        return $user->fresh() ?? $user;
    }
}

if (! function_exists('p12gSeedPortalRoles')) {
    /**
     * What RolePermissionSeeder does in production, reduced to the two portal
     * roles: each holds exactly portal.access.
     */
    function p12gSeedPortalRoles(): void
    {
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
        $access = Permission::findOrCreate(OpesPermission::PortalAccess->value, 'web');
        SpatieRole::findOrCreate('guardian', 'web')->givePermissionTo($access);
        SpatieRole::findOrCreate('staff_portal', 'web')->givePermissionTo($access);
    }
}

if (! function_exists('p12gIssueFor')) {
    /**
     * Issue as a permissioned admin, returning the invitation and the
     * plaintext code.
     *
     * @return array{invitation: PortalInvitation, code: string}
     */
    function p12gIssueFor(PortalSubjectType $type, int $subjectId): array
    {
        actingAs(p12gAdmin());

        $result = app(IssuePortalInvitation::class)->handle($type, $subjectId);

        // The activation flow is unauthenticated; drop the admin session so
        // every activation in these tests runs as the anonymous visitor it
        // is in production.
        auth()->logout();

        return $result;
    }
}

// ── Issuing ────────────────────────────────────────────────────────────────

it('issues a code for a guardian, shows it once, and stores only its SHA-256', function () {
    $guardian = Guardian::factory()->create();
    ['invitation' => $invitation, 'code' => $code] = p12gIssueFor(PortalSubjectType::Guardian, (int) $guardian->getKey());

    // Handover-friendly shape: three groups of four, unambiguous alphabet.
    expect($code)->toMatch('/^[2-9A-HJKMNP-Z]{4}-[2-9A-HJKMNP-Z]{4}-[2-9A-HJKMNP-Z]{4}$/');

    $invitation->refresh();

    expect($invitation->code_hash)->toBe(hash('sha256', str_replace('-', '', $code)))
        ->and($invitation->code_hash)->not->toContain(str_replace('-', '', $code))
        ->and($invitation->used_at)->toBeNull()
        ->and($invitation->revoked_at)->toBeNull()
        ->and($invitation->expires_at->isAfter(Carbon::now()->addDays(13)))->toBeTrue();

    // The audit trail records the act but NEVER the code or its hash.
    $audit = AuditLog::query()
        ->where('module', 'Guardians')
        ->where('auditable_type', PortalInvitation::class)
        ->where('auditable_id', $invitation->getKey())
        ->first();

    expect($audit)->not->toBeNull()
        ->and(json_encode($audit?->getAttributes()))->not->toContain(str_replace('-', '', $code))
        ->and(json_encode($audit?->getAttributes()))->not->toContain($invitation->code_hash);
});

it('refuses to issue without portal.manage', function () {
    $guardian = Guardian::factory()->create();
    actingAs(p12gAdmin(withPermission: false));

    expect(fn () => app(IssuePortalInvitation::class)->handle(PortalSubjectType::Guardian, (int) $guardian->getKey()))
        ->toThrow(AuthorizationException::class);
});

it('supersedes: issuing again revokes the earlier open code for the same subject', function () {
    $guardian = Guardian::factory()->create();

    ['invitation' => $first, 'code' => $firstCode] = p12gIssueFor(PortalSubjectType::Guardian, (int) $guardian->getKey());
    ['invitation' => $second] = p12gIssueFor(PortalSubjectType::Guardian, (int) $guardian->getKey());

    expect($first->refresh()->revoked_at)->not->toBeNull()
        ->and($second->refresh()->revoked_at)->toBeNull();

    // And the superseded code is dead.
    p12gSeedPortalRoles();
    expect(fn () => app(ActivatePortalAccount::class)->handle($firstCode, 'Bela Merceline', 'bela@example.test', 'S3cure-password'))
        ->toThrow(ValidationException::class, ActivatePortalAccount::GENERIC_FAILURE);
});

it('refuses to issue for an inactive, archived or already-linked guardian', function () {
    actingAs(p12gAdmin());
    $issue = app(IssuePortalInvitation::class);

    $inactive = Guardian::factory()->create(['status' => 'inactive']);
    expect(fn () => $issue->handle(PortalSubjectType::Guardian, (int) $inactive->getKey()))
        ->toThrow(ValidationException::class);

    $archived = Guardian::factory()->create(['is_archived' => true]);
    expect(fn () => $issue->handle(PortalSubjectType::Guardian, (int) $archived->getKey()))
        ->toThrow(ValidationException::class);

    $linked = Guardian::factory()->create(['portal_user_id' => User::factory()->create()->getKey()]);
    expect(fn () => $issue->handle(PortalSubjectType::Guardian, (int) $linked->getKey()))
        ->toThrow(ValidationException::class);
});

// ── Activation, the happy paths ────────────────────────────────────────────

it('activates a guardian account: user created, guardian role, portal_user_id linked, code consumed', function () {
    p12gSeedPortalRoles();
    $guardian = Guardian::factory()->create();
    ['invitation' => $invitation, 'code' => $code] = p12gIssueFor(PortalSubjectType::Guardian, (int) $guardian->getKey());

    // Typed the way people type: lowercase, no dashes, stray spaces.
    $typed = ' '.strtolower(str_replace('-', '', $code)).' ';

    $userId = app(ActivatePortalAccount::class)->handle($typed, 'Bela Merceline', 'bela@example.test', 'S3cure-password');

    $user = User::query()->findOrFail($userId);

    expect($user->status)->toBe('active')
        ->and($user->hasRole('guardian'))->toBeTrue()
        ->and(Gate::forUser($user)->allows(OpesPermission::PortalAccess->value))->toBeTrue()
        // The portal role opens the shell and nothing else - a portal account
        // may never see a staff screen.
        ->and(Gate::forUser($user)->allows(OpesPermission::StudentsView->value))->toBeFalse()
        ->and($guardian->refresh()->portal_user_id)->toBe($userId)
        ->and($invitation->refresh()->used_at)->not->toBeNull()
        ->and($invitation->used_by_user_id)->toBe($userId);
});

it('activates a staff account with the staff_portal role and links staff_members.portal_user_id', function () {
    p12gSeedPortalRoles();
    /** @var StaffMember $staff */
    $staff = StaffMember::factory()->create();
    ['code' => $code] = p12gIssueFor(PortalSubjectType::Staff, (int) $staff->getKey());

    $userId = app(ActivatePortalAccount::class)->handle($code, 'Ngwa Bertrand', 'ngwa@example.test', 'S3cure-password');

    $user = User::query()->findOrFail($userId);

    expect($user->hasRole('staff_portal'))->toBeTrue()
        ->and(Gate::forUser($user)->allows(OpesPermission::PortalAccess->value))->toBeTrue()
        ->and((int) DB::table('staff_members')->where('id', $staff->getKey())->value('portal_user_id'))->toBe($userId);
});

// ── Activation, every failure - generic on purpose ─────────────────────────

it('fails activation generically for an unknown code', function () {
    p12gSeedPortalRoles();

    expect(fn () => app(ActivatePortalAccount::class)->handle('ABCD-EFGH-JKMN', 'X', 'x@example.test', 'S3cure-password'))
        ->toThrow(ValidationException::class, ActivatePortalAccount::GENERIC_FAILURE);
});

it('fails activation generically for an expired code', function () {
    p12gSeedPortalRoles();
    $guardian = Guardian::factory()->create();
    ['invitation' => $invitation, 'code' => $code] = p12gIssueFor(PortalSubjectType::Guardian, (int) $guardian->getKey());

    $invitation->expires_at = Carbon::now()->subMinute();
    $invitation->save();

    expect(fn () => app(ActivatePortalAccount::class)->handle($code, 'X', 'x@example.test', 'S3cure-password'))
        ->toThrow(ValidationException::class, ActivatePortalAccount::GENERIC_FAILURE);
});

it('fails activation generically for a revoked code', function () {
    p12gSeedPortalRoles();
    $guardian = Guardian::factory()->create();
    ['invitation' => $invitation, 'code' => $code] = p12gIssueFor(PortalSubjectType::Guardian, (int) $guardian->getKey());

    actingAs(p12gAdmin());
    app(RevokePortalInvitation::class)->handle($invitation);
    auth()->logout();

    expect(fn () => app(ActivatePortalAccount::class)->handle($code, 'X', 'x@example.test', 'S3cure-password'))
        ->toThrow(ValidationException::class, ActivatePortalAccount::GENERIC_FAILURE);
});

it('fails a second activation of the same code generically: a code redeems exactly once', function () {
    p12gSeedPortalRoles();
    $guardian = Guardian::factory()->create();
    ['code' => $code] = p12gIssueFor(PortalSubjectType::Guardian, (int) $guardian->getKey());

    app(ActivatePortalAccount::class)->handle($code, 'First', 'first@example.test', 'S3cure-password');

    expect(fn () => app(ActivatePortalAccount::class)->handle($code, 'Second', 'second@example.test', 'S3cure-password'))
        ->toThrow(ValidationException::class, ActivatePortalAccount::GENERIC_FAILURE);
});

it('fails activation generically when the guardian became ineligible after issue', function () {
    // The slip was printed two weeks ago; custody moved, the guardian was
    // deactivated. The code must die with the eligibility.
    p12gSeedPortalRoles();
    $guardian = Guardian::factory()->create();
    ['code' => $code] = p12gIssueFor(PortalSubjectType::Guardian, (int) $guardian->getKey());

    $guardian->status = \App\Modules\Guardians\Domain\GuardianStatus::Inactive;
    $guardian->save();

    expect(fn () => app(ActivatePortalAccount::class)->handle($code, 'X', 'x@example.test', 'S3cure-password'))
        ->toThrow(ValidationException::class, ActivatePortalAccount::GENERIC_FAILURE);
});

it('rejects a taken email by name, and consumes nothing', function () {
    // The one non-generic failure: the person HOLDS a valid code, and the
    // problem is the email they chose, not the code.
    p12gSeedPortalRoles();
    User::factory()->create(['email' => 'taken@example.test']);
    $guardian = Guardian::factory()->create();
    ['invitation' => $invitation, 'code' => $code] = p12gIssueFor(PortalSubjectType::Guardian, (int) $guardian->getKey());

    try {
        app(ActivatePortalAccount::class)->handle($code, 'X', 'taken@example.test', 'S3cure-password');
        expect(false)->toBeTrue('Activation should have failed on the taken email.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('email');
    }

    // The code survives to be retried with a different email; no account was
    // linked.
    expect($invitation->refresh()->used_at)->toBeNull()
        ->and($guardian->refresh()->portal_user_id)->toBeNull();
});

// ── The admin panel on the guardian record ─────────────────────────────────

it('issues from the profile portal tab and shows the code exactly until navigation', function () {
    p12gSeedPortalRoles();
    $guardian = Guardian::factory()->create();

    $admin = p12gAdmin();
    Permission::findOrCreate(OpesPermission::StudentsView->value, 'web');
    $admin->givePermissionTo(OpesPermission::StudentsView->value);
    actingAs($admin->fresh() ?? $admin);

    $component = \Livewire\Livewire::test(\App\Modules\Guardians\Livewire\Guardians\Show::class, ['guardian' => $guardian])
        ->call('selectTab', 'portal')
        ->call('issuePortalInvitation');

    $code = $component->get('issuedCode');

    expect($code)->toBeString()
        ->and((string) $code)->toMatch('/^[2-9A-HJKMNP-Z]{4}-[2-9A-HJKMNP-Z]{4}-[2-9A-HJKMNP-Z]{4}$/');

    $component->assertSee((string) $code);

    // Leaving the tab discards the one-time display; the database never had
    // the plaintext to begin with.
    $component->call('selectTab', 'linked_students');
    expect($component->get('issuedCode'))->toBeNull()
        ->and(DB::table('portal_invitations')->where('code_hash', PortalInvitationCode::hash((string) $code))->exists())->toBeTrue();
});

it('hides the issue controls from operators without portal.manage, and the Action refuses a forged call', function () {
    p12gSeedPortalRoles();
    $guardian = Guardian::factory()->create();

    $viewer = p12gAdmin(withPermission: false);
    Permission::findOrCreate(OpesPermission::StudentsView->value, 'web');
    $viewer->givePermissionTo(OpesPermission::StudentsView->value);
    actingAs($viewer->fresh() ?? $viewer);

    \Livewire\Livewire::test(\App\Modules\Guardians\Livewire\Guardians\Show::class, ['guardian' => $guardian])
        ->call('selectTab', 'portal')
        ->assertDontSee(__('opes.guardians_screen.portal_issue_button'))
        // The wire method itself is still reachable by a forged payload; the
        // Action's own gate is the real door.
        ->call('issuePortalInvitation')
        ->assertForbidden();
});

// ── Revocation ─────────────────────────────────────────────────────────────

it('revokes an open invitation under portal.manage, and refuses to revoke a used one', function () {
    p12gSeedPortalRoles();
    $guardian = Guardian::factory()->create();
    ['invitation' => $invitation, 'code' => $code] = p12gIssueFor(PortalSubjectType::Guardian, (int) $guardian->getKey());

    actingAs(p12gAdmin(withPermission: false));
    expect(fn () => app(RevokePortalInvitation::class)->handle($invitation))
        ->toThrow(AuthorizationException::class);

    auth()->logout();
    app(ActivatePortalAccount::class)->handle($code, 'X', 'x@example.test', 'S3cure-password');

    actingAs(p12gAdmin());
    expect(fn () => app(RevokePortalInvitation::class)->handle($invitation->refresh()))
        ->toThrow(ValidationException::class);
});
