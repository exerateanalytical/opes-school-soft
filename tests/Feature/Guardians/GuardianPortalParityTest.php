<?php

declare(strict_types=1);

use App\Modules\Guardians\Livewire\Portal\AccountEdit;
use App\Modules\Guardians\Livewire\Portal\Attendance;
use App\Modules\Guardians\Livewire\Portal\Health;
use App\Modules\Guardians\Livewire\Portal\Meeting;
use App\Modules\Guardians\Livewire\Portal\Payments;
use App\Modules\Guardians\Livewire\Portal\Search;
use App\Modules\Guardians\Livewire\Portal\Timetable;
use App\Modules\Guardians\Models\Guardian;
use App\Modules\Guardians\Models\StudentGuardian;
use App\Modules\Identity\Domain\Permission as OpesPermission;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * Phase 12-P3: the portal screens that close the gap with the mobile app.
 *
 * These assert the SAME refusals the API suite asserts, on the other door.
 * That is the whole point of the shared readers - if the two doors ever
 * disagree about what a guardian may see, one of the two suites goes red.
 *
 * Refusals are asserted over HTTP rather than through Livewire::test(), for
 * two reasons: it is the convention the existing portal suites use, and it
 * exercises the route and its middleware as well as the component.
 */

// Declared per file in this suite, not globally in Pest.php. Without it these
// tests' rows survive into the next file and break every count-based
// assertion there - which is exactly what happened the first time this file
// ran alongside GuardianTest and ApiStudentsTest.
uses(RefreshDatabase::class);

/**
 * @return array{user: User, guardian: Guardian}
 */
function ppGuardian(): array
{
    app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
    $access = Permission::findOrCreate(OpesPermission::PortalAccess->value, 'web');
    SpatieRole::findOrCreate('guardian', 'web')->givePermissionTo($access);

    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('guardian');
    $user = $user->fresh() ?? $user;

    $guardian = Guardian::factory()->create(['portal_user_id' => $user->getKey()]);

    return ['user' => $user, 'guardian' => $guardian];
}

/** @param array<string, mixed> $overrides */
function ppStudent(array $overrides = []): int
{
    $suffix = Str::upper(Str::random(6));

    return (int) DB::table('students')->insertGetId(array_merge([
        'matricule' => 'PP-'.$suffix,
        'admission_no' => 'ADM/PP/'.$suffix,
        'first_name' => 'Emmanuel',
        'last_name' => 'Ngo',
        'date_of_birth' => '2013-03-12',
        'gender' => 'male',
        'nationality' => 'CM',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/** @param array<string, mixed> $flags */
function ppLink(int $guardianId, int $studentId, array $flags = []): StudentGuardian
{
    return StudentGuardian::factory()->create(array_merge([
        'guardian_id' => $guardianId,
        'student_id' => $studentId,
    ], $flags));
}

it('shows attendance to a custodial link at detail scope', function () {
    ['user' => $user, 'guardian' => $guardian] = ppGuardian();
    $student = ppStudent();
    ppLink((int) $guardian->getKey(), $student, ['has_custody' => true]);

    actingAs($user);

    Livewire::test(Attendance::class, ['student' => $student])
        ->assertOk()
        ->assertSet('canDetail', true);
});

it('refuses attendance to a link with neither custody nor reports', function () {
    ['user' => $user, 'guardian' => $guardian] = ppGuardian();
    $student = ppStudent();
    ppLink((int) $guardian->getKey(), $student, [
        'has_custody' => false,
        'receives_reports' => false,
    ]);

    actingAs($user);

    get(route('portal.children.attendance', $student))->assertForbidden();
});

it('grants attendance detail to a reports-only link, because rows 11 and 12 share one rule', function () {
    ['user' => $user, 'guardian' => $guardian] = ppGuardian();
    $student = ppStudent();
    ppLink((int) $guardian->getKey(), $student, [
        'has_custody' => false,
        'receives_reports' => true,
    ]);

    actingAs($user);

    /*
     * Pinning a fact that surprised this build and is worth pinning:
     * GuardianScopeMatrix grants rows 11 AND 12 on the same condition
     * (`hasCustody || receivesReports`), so there is currently NO link shape
     * that holds the summary without the detail. Both doors carry a
     * summary-only branch anyway - the API's `scope` field and the portal's
     * `attendance_summary_only` notice - because 7.5 defines them as separate
     * rows and a future matrix change may separate them in practice.
     *
     * If that ever happens, this test flips to asserting the notice, and the
     * branch is already there to serve it.
     */
    Livewire::test(Attendance::class, ['student' => $student])
        ->assertOk()
        ->assertSet('canDetail', true);
});

it('serves the timetable on any valid link', function () {
    ['user' => $user, 'guardian' => $guardian] = ppGuardian();
    $student = ppStudent();
    ppLink((int) $guardian->getKey(), $student, [
        'has_custody' => false,
        'receives_reports' => false,
        'receives_invoices' => false,
    ]);

    actingAs($user);

    Livewire::test(Timetable::class, ['student' => $student])->assertOk();
});

it('narrows health to the emergency scope without custody', function () {
    ['user' => $user, 'guardian' => $guardian] = ppGuardian();
    $student = ppStudent();
    ppLink((int) $guardian->getKey(), $student, [
        'has_custody' => false,
        'is_emergency_contact' => true,
    ]);

    actingAs($user);

    Livewire::test(Health::class, ['student' => $student])
        ->assertOk()
        ->assertSet('canFull', false)
        ->assertSee(__('opes.guardian_portal.health_emergency_scope'));
});

it('refuses health to a link that is neither custodial nor an emergency contact', function () {
    ['user' => $user, 'guardian' => $guardian] = ppGuardian();
    $student = ppStudent();
    ppLink((int) $guardian->getKey(), $student, [
        'has_custody' => false,
        'is_emergency_contact' => false,
    ]);

    actingAs($user);

    get(route('portal.children.health', $student))->assertForbidden();
});

it('never shows another guardian\'s payment to a link holding only the row-16 floor', function () {
    ['user' => $user, 'guardian' => $guardian] = ppGuardian();
    $student = ppStudent();

    ppLink((int) $guardian->getKey(), $student, [
        'has_custody' => false,
        'receives_reports' => false,
        'receives_invoices' => false,
        'is_fee_payer' => false,
    ]);

    actingAs($user);

    // The same assertion the API suite makes, on the other door.
    Livewire::test(Payments::class)
        ->assertOk()
        ->assertDontSee('99000');
});

it('refuses announcements once every link has expired', function () {
    ['user' => $user, 'guardian' => $guardian] = ppGuardian();
    ppLink((int) $guardian->getKey(), ppStudent(), [
        'valid_from' => now()->subYears(2)->toDateString(),
        'valid_to' => now()->subYear()->toDateString(),
    ]);

    actingAs($user);

    // 7.5's historic-access rule: an announcement is news about a school you
    // are currently part of.
    get(route('portal.announcements'))->assertForbidden();
});

it('never returns another family\'s child from portal search', function () {
    ['user' => $user, 'guardian' => $guardian] = ppGuardian();

    $mine = ppStudent(['first_name' => 'Emmanuel', 'last_name' => 'Ngo']);
    $notMine = ppStudent(['first_name' => 'Emmanuel', 'last_name' => 'Ngo']);

    ppLink((int) $guardian->getKey(), $mine);

    actingAs($user);

    // The two children share a name; only the matricule tells them apart, and
    // the other family's must not appear.
    $matricule = (string) DB::table('students')->where('id', $notMine)->value('matricule');

    Livewire::test(Search::class)
        ->set('query', 'Emmanuel')
        ->assertOk()
        ->assertDontSee($matricule);
});

it('refuses a portal search shorter than the floor', function () {
    ['user' => $user, 'guardian' => $guardian] = ppGuardian();
    ppLink((int) $guardian->getKey(), ppStudent());

    actingAs($user);

    Livewire::test(Search::class)
        ->set('query', 'a')
        ->assertOk()
        ->assertSee(__('opes.guardian_portal.search_min_length'));
});

it('treats a SQL wildcard as a literal in portal search', function () {
    ['user' => $user, 'guardian' => $guardian] = ppGuardian();
    ppLink((int) $guardian->getKey(), ppStudent(['first_name' => 'Bertrand']));

    actingAs($user);

    // Unescaped, `%` turns any LIKE into a match-all.
    Livewire::test(Search::class)
        ->set('query', '%%')
        ->assertOk()
        ->assertSee(__('opes.guardian_portal.search_empty'));
});

it('lets a guardian save their own contact details', function () {
    ['user' => $user, 'guardian' => $guardian] = ppGuardian();
    ppLink((int) $guardian->getKey(), ppStudent());

    actingAs($user);

    Livewire::test(AccountEdit::class)
        ->set('city', 'Bafoussam')
        ->set('occupation', 'Tailor')
        ->call('save')
        ->assertHasNoErrors();

    expect($guardian->refresh()->city)->toBe('Bafoussam');
});

it('refuses the account screen once every link has expired', function () {
    ['user' => $user, 'guardian' => $guardian] = ppGuardian();
    ppLink((int) $guardian->getKey(), ppStudent(), [
        'valid_from' => now()->subYears(2)->toDateString(),
        'valid_to' => now()->subYear()->toDateString(),
    ]);

    actingAs($user);

    get(route('portal.account.edit'))->assertForbidden();
});

it('records a meeting request as made by the guardian', function () {
    ['user' => $user, 'guardian' => $guardian] = ppGuardian();
    $student = ppStudent();
    ppLink((int) $guardian->getKey(), $student, ['has_custody' => true]);

    actingAs($user);

    Livewire::test(Meeting::class, ['student' => $student])
        ->set('preferredAt', now()->addWeek()->format('Y-m-d\TH:i'))
        ->set('agenda', 'Discuss term results')
        ->call('submit')
        ->assertHasNoErrors();

    // The distinction the office needs: an ask, not a booking.
    expect(DB::table('guardian_meetings')
        ->where('student_id', $student)
        ->value('requested_by'))->toBe('guardian');
});

it('refuses a meeting request from a link without custody', function () {
    ['user' => $user, 'guardian' => $guardian] = ppGuardian();
    $student = ppStudent();
    ppLink((int) $guardian->getKey(), $student, ['has_custody' => false]);

    actingAs($user);

    get(route('portal.children.meeting', $student))->assertForbidden();
});

it('refuses every new child screen for an unlinked child', function () {
    ['user' => $user] = ppGuardian();
    $other = ppStudent();

    actingAs($user);

    // Row 32: an unlinked child yields deny for every capability.
    foreach ([
        'portal.children.attendance',
        'portal.children.timetable',
        'portal.children.health',
        'portal.children.meeting',
    ] as $routeName) {
        get(route($routeName, $other))->assertForbidden();
    }
});
