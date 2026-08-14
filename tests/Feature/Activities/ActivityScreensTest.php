<?php

declare(strict_types=1);

use App\Modules\Activities\Domain\ActivityPermission;
use App\Modules\Activities\Livewire\Index as ActivitiesIndex;
use App\Modules\Activities\Livewire\Show as ActivityShow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\get;

require_once __DIR__.'/ActivityTestHelpers.php';

uses(RefreshDatabase::class);

// ── The Activities list screen ──────────────────────────────────────────

it('renders the activities screen for an activity.view holder', function () {
    $user = actvManager();
    actvActivity($user, ['name' => 'Chess Club']);
    actvExcursion($user, ['name' => 'Limbe Trip']);

    Livewire::test(ActivitiesIndex::class)
        ->assertSee('Extra-Curricular Activities')
        ->assertSee('Active Activities')
        ->assertSee('Total Members')
        ->assertSee('Sessions This Week')
        ->assertSee('Pending Consents')
        ->assertSee('Chess Club')
        ->assertSee('Limbe Trip');
});

it('forbids the activities screen without activity.view', function () {
    actvUser(); // signed in, no abilities

    Livewire::test(ActivitiesIndex::class)->assertForbidden();
});

it('serves /activities to a view holder and 403s a role without it', function () {
    actvUser(ActivityPermission::VIEW);
    get('/activities')->assertOk();

    actvUser(); // no abilities
    get('/activities')->assertForbidden();
});

it('links each row to its detail page - the row click goes somewhere', function () {
    $user = actvManager();
    $activity = actvActivity($user, ['name' => 'Drama Club']);

    Livewire::test(ActivitiesIndex::class)
        ->assertSeeHtml('/activities/'.$activity->getKey());
});

it('creates an activity from the rail form', function () {
    actvManager();

    Livewire::test(ActivitiesIndex::class)
        ->call('toggleCreateForm')
        ->set('createFormName', 'Football First XI')
        ->set('createFormType', 'sport')
        ->set('createFormVenue', 'Main Field')
        ->call('saveActivity')
        ->assertHasNoErrors()
        ->assertSee('Football First XI');
});

it('surfaces the excursion refusal on the rail form', function () {
    actvManager();

    Livewire::test(ActivitiesIndex::class)
        ->call('toggleCreateForm')
        ->set('createFormName', 'Tripless Trip')
        ->set('createFormType', 'excursion')
        ->call('saveActivity')
        ->assertHasErrors(['createFormDestination']);
});

// ── The activity detail screen ──────────────────────────────────────────

it('renders the detail page with members for a view holder', function () {
    $user = actvManager();
    $activity = actvActivity($user, ['name' => 'Science Club']);
    $membership = actvMembership($user, $activity);
    actvSession($user, $activity);

    /** @var object{first_name: string, last_name: string} $student */
    $student = \Illuminate\Support\Facades\DB::table('students')
        ->where('id', $membership->student_id)
        ->first(['first_name', 'last_name']);

    Livewire::test(ActivityShow::class, ['activity' => (int) $activity->getKey()])
        ->assertSee('Science Club')
        ->assertSee('Active Members')
        ->assertSee($student->first_name)
        ->call('selectTab', 'sessions')
        ->assertSee('Sports Field');
});

it('shows the consent tab only on an excursion', function () {
    $user = actvManager();
    $excursion = actvExcursion($user, ['name' => 'Kribi Trip']);
    actvMembership($user, $excursion);

    Livewire::test(ActivityShow::class, ['activity' => (int) $excursion->getKey()])
        ->assertSee('Consent')
        ->assertSee('Limbe Wildlife Centre')
        ->call('selectTab', 'consent')
        ->assertSet('tab', 'consent')
        ->assertSee('Pending');

    $club = actvActivity($user, ['name' => 'Plain Club']);

    // On a club the consent tab does not exist and cannot be selected.
    Livewire::test(ActivityShow::class, ['activity' => (int) $club->getKey()])
        ->call('selectTab', 'consent')
        ->assertSet('tab', 'members');
});

it('forbids the detail page without activity.view', function () {
    $manager = actvManager();
    $activity = actvActivity($manager);

    actvUser(); // signed in, no abilities

    Livewire::test(ActivityShow::class, ['activity' => (int) $activity->getKey()])
        ->assertForbidden();
});

it('serves /activities/{id} and 403s without the permission', function () {
    $manager = actvManager();
    $activity = actvActivity($manager);

    get('/activities/'.$activity->getKey())->assertOk();

    actvUser();
    get('/activities/'.$activity->getKey())->assertForbidden();
});

it('records the register from the attendance tab', function () {
    $user = actvManager();
    $activity = actvActivity($user);
    $membership = actvMembership($user, $activity);
    $session = actvSession($user, $activity);

    Livewire::test(ActivityShow::class, ['activity' => (int) $activity->getKey()])
        ->call('selectTab', 'attendance')
        ->set('sessionId', (string) $session->getKey())
        ->set('attendanceMarks.'.$membership->getKey(), 'present')
        ->call('saveAttendance')
        ->assertHasNoErrors();

    expect(
        \Illuminate\Support\Facades\DB::table('activity_attendance')
            ->where('session_id', $session->getKey())
            ->where('membership_id', $membership->getKey())
            ->value('status')
    )->toBe('present');
});

it('records consent from the consent tab through the real gate', function () {
    $user = actvManager();
    $studentId = actvStudentId();
    $guardianId = actvLinkedGuardianId($studentId);
    $excursion = actvExcursion($user);
    $membership = actvMembership($user, $excursion, $studentId);

    Livewire::test(ActivityShow::class, ['activity' => (int) $excursion->getKey()])
        ->call('selectTab', 'consent')
        ->set('consentFormMembershipId', (string) $membership->getKey())
        ->set('consentFormGuardianId', (string) $guardianId)
        ->set('consentFormDecision', 'granted')
        ->call('recordConsent')
        ->assertHasNoErrors();

    expect($membership->refresh()->consent_status?->value)->toBe('granted');
});
