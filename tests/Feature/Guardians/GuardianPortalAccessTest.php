<?php

declare(strict_types=1);

// EnsureGuardianPortal + GuardianPortalPolicy: WHO gets through the portal
// door, and how the per-child decision reaches GuardianScopeMatrix
// (docs/plans/phase-12-13.md 12.2). The matrix itself is exhaustively proven
// in GuardianScopeMatrixTest; this file proves the plumbing around it - the
// middleware's conjunctive gates and the policy's link resolution - never
// widens what the matrix decided.

use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Http\Middleware\EnsureGuardianPortal;
use App\Modules\Guardians\Models\Guardian;
use App\Modules\Guardians\Models\StudentGuardian;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use App\Modules\Guardians\Support\PortalContext;
use App\Modules\Identity\Domain\Permission as OpesPermission;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

if (! function_exists('p12gPortalGuardian')) {
    /**
     * An activated portal principal: active user + guardian role holding
     * portal.access + active guardian row pointing back via portal_user_id.
     *
     * @return array{user: User, guardian: Guardian}
     */
    function p12gPortalGuardian(): array
    {
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
        $access = Permission::findOrCreate(OpesPermission::PortalAccess->value, 'web');
        SpatieRole::findOrCreate('guardian', 'web')->givePermissionTo($access);

        $user = User::factory()->create();
        $user->assignRole('guardian');

        $guardian = Guardian::factory()->create(['portal_user_id' => $user->getKey()]);

        return ['user' => $user->fresh() ?? $user, 'guardian' => $guardian];
    }
}

if (! function_exists('p12gPortalStudentId')) {
    /** A student row for the link FK; query builder per the module's own convention. */
    function p12gPortalStudentId(string $suffix = 'A'): int
    {
        return (int) DB::table('students')->insertGetId([
            'matricule' => 'PP2026-'.$suffix,
            'admission_no' => 'ADM/2026/PP'.$suffix,
            'first_name' => 'Portal',
            'last_name' => 'Child',
            'date_of_birth' => '2013-02-01',
            'gender' => 'female',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

beforeEach(function () {
    // A throwaway route wearing the production stack: session auth, then the
    // portal door. The real /portal routes are registered by the portal
    // screens workstream; the middleware contract is what is under test here.
    Route::middleware(['web', 'auth', EnsureGuardianPortal::class])
        ->get('/p12g-portal-probe', fn () => response('p12g-ok'));
});

// ── The door ───────────────────────────────────────────────────────────────

it('admits an active guardian portal principal', function () {
    ['user' => $user] = p12gPortalGuardian();

    actingAs($user);

    get('/p12g-portal-probe')->assertOk()->assertSee('p12g-ok');
});

it('redirects a guest to login', function () {
    get('/p12g-portal-probe')->assertRedirect('/login');
});

it('refuses a staff user without portal.access', function () {
    app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate(OpesPermission::StudentsView->value, 'web');

    $staff = User::factory()->create();
    $staff->givePermissionTo(OpesPermission::StudentsView->value);

    actingAs($staff->fresh() ?? $staff);

    get('/p12g-portal-probe')->assertForbidden();
});

it('refuses a user holding portal.access with no guardian row behind it', function () {
    app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
    $access = Permission::findOrCreate(OpesPermission::PortalAccess->value, 'web');

    $user = User::factory()->create();
    $user->givePermissionTo($access);

    actingAs($user->fresh() ?? $user);

    get('/p12g-portal-probe')->assertForbidden();
});

it('refuses when the guardian has been deactivated or archived', function () {
    ['user' => $user, 'guardian' => $guardian] = p12gPortalGuardian();
    actingAs($user);

    $guardian->status = \App\Modules\Guardians\Domain\GuardianStatus::Inactive;
    $guardian->save();
    get('/p12g-portal-probe')->assertForbidden();

    $guardian->status = \App\Modules\Guardians\Domain\GuardianStatus::Active;
    $guardian->is_archived = true;
    $guardian->save();
    get('/p12g-portal-probe')->assertForbidden();
});

it('refuses a suspended user even though the guardian row is fine', function () {
    ['user' => $user] = p12gPortalGuardian();
    $user->status = 'suspended';
    $user->save();

    actingAs($user);

    get('/p12g-portal-probe')->assertForbidden();
});

// ── The per-child decision ─────────────────────────────────────────────────

it('grants per the link flags and denies across scopes, through the policy', function () {
    ['user' => $user, 'guardian' => $guardian] = p12gPortalGuardian();
    $studentId = p12gPortalStudentId();

    StudentGuardian::factory()->create([
        'guardian_id' => $guardian->getKey(),
        'student_id' => $studentId,
        'receives_reports' => true,
    ]);

    actingAs($user);
    $policy = app(GuardianPortalPolicy::class);

    // receives_reports grants the Results rows...
    expect($policy->allows(GuardianCapability::R05ViewReportCard, $studentId))->toBeTrue()
        ->and($policy->allows(GuardianCapability::R07ViewPublishedMarks, $studentId))->toBeTrue()
        // ...and row 1 (any valid link)...
        ->and($policy->allows(GuardianCapability::R01ViewChildIdentity, $studentId))->toBeTrue()
        // ...but nothing from the custody or fees scopes.
        ->and($policy->allows(GuardianCapability::R02ViewChildProfileDetail, $studentId))->toBeFalse()
        ->and($policy->allows(GuardianCapability::R13ViewInvoices, $studentId))->toBeFalse()
        ->and($policy->allows(GuardianCapability::R18InitiatePayment, $studentId))->toBeFalse()
        // Row 8: never, no matter the flags.
        ->and($policy->allows(GuardianCapability::R08ViewUnpublishedMarks, $studentId))->toBeFalse();
});

it('denies everything for a child the guardian is not linked to (row 32)', function () {
    ['user' => $user] = p12gPortalGuardian();
    $unlinkedStudentId = p12gPortalStudentId('B');

    actingAs($user);
    $policy = app(GuardianPortalPolicy::class);

    foreach (GuardianCapability::cases() as $capability) {
        expect($policy->allows($capability, $unlinkedStudentId))
            ->toBeFalse("Row {$capability->matrixRow()} leaked for an unlinked child.");
    }
});

it('denies everything on an expired link - historic access does not survive revocation', function () {
    ['user' => $user, 'guardian' => $guardian] = p12gPortalGuardian();
    $studentId = p12gPortalStudentId('C');

    StudentGuardian::factory()->expired()->create([
        'guardian_id' => $guardian->getKey(),
        'student_id' => $studentId,
        'has_custody' => true,
        'receives_reports' => true,
        'receives_invoices' => true,
        'is_fee_payer' => true,
        'is_emergency_contact' => true,
    ]);

    actingAs($user);
    $policy = app(GuardianPortalPolicy::class);

    foreach (GuardianCapability::cases() as $capability) {
        expect($policy->allows($capability, $studentId))->toBeFalse();
    }

    // And the child-less grants are gone with the last valid link.
    expect($policy->allowsForAnyChild(GuardianCapability::R16ViewOwnPayments))->toBeFalse()
        ->and($policy->allowsForAnyChild(GuardianCapability::R26ViewTimetableAndAnnouncements))->toBeFalse();
});

it('grants the any-valid-link capabilities through allowsForAnyChild (rows 16, 26, 29)', function () {
    ['user' => $user, 'guardian' => $guardian] = p12gPortalGuardian();
    $studentId = p12gPortalStudentId('D');

    // Every flag off: the weakest possible valid link still carries rows 16,
    // 26 and 29 - and nothing else.
    StudentGuardian::factory()->create([
        'guardian_id' => $guardian->getKey(),
        'student_id' => $studentId,
    ]);

    actingAs($user);
    $policy = app(GuardianPortalPolicy::class);

    expect($policy->allowsForAnyChild(GuardianCapability::R16ViewOwnPayments))->toBeTrue()
        ->and($policy->allowsForAnyChild(GuardianCapability::R26ViewTimetableAndAnnouncements))->toBeTrue()
        ->and($policy->allowsForAnyChild(GuardianCapability::R29EditOwnContactDetails))->toBeTrue()
        ->and($policy->allowsForAnyChild(GuardianCapability::R18InitiatePayment))->toBeFalse()
        ->and($policy->allowsForAnyChild(GuardianCapability::R05ViewReportCard))->toBeFalse();
});

it('answers deny, not crash, when no portal principal is authenticated', function () {
    $policy = app(GuardianPortalPolicy::class);

    expect($policy->allows(GuardianCapability::R01ViewChildIdentity, 1))->toBeFalse()
        ->and($policy->allowsForAnyChild(GuardianCapability::R26ViewTimetableAndAnnouncements))->toBeFalse();
});

it('fixes the business date once per request and reuses the same context object', function () {
    ['user' => $user, 'guardian' => $guardian] = p12gPortalGuardian();
    actingAs($user);

    $first = PortalContext::current();
    $second = PortalContext::current();

    expect($first)->not->toBeNull()
        ->and($second)->toBe($first)
        ->and($first?->guardian->getKey())->toBe($guardian->getKey())
        ->and($first?->asOf)->toBe(\App\Support\Clock\BusinessDate::today());
});
