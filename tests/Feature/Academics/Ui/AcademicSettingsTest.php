<?php

declare(strict_types=1);

use App\Modules\Academics\Livewire\Settings\AcademicSettings;
use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Academics\Models\AssessmentPeriod;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/*
 * Named academicsUiUserAs (not userAs) because Pest test functions share ONE
 * global namespace across every loaded file, and Identity's
 * UserManagementTest already declares userAs(). The function_exists guard
 * lets the three Academics UI files (which run together) share this helper.
 */
if (! function_exists('academicsUiUserAs')) {
    function academicsUiUserAs(Role $role): User
    {
        (new \Database\Seeders\RolePermissionSeeder())->run();
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user->fresh() ?? $user;
    }
}

it('renders through the real route inside the shell', function () {
    actingAs(academicsUiUserAs(Role::Administrator));

    get('/academics/settings')->assertOk()->assertSee('OPES');
});

it('403s on the route for a role without academics.manage', function () {
    // Teacher holds academics.view - view is NOT enough for the settings
    // screen, which shapes the structure everyone else reads.
    actingAs(academicsUiUserAs(Role::Teacher));

    get('/academics/settings')->assertForbidden();
});

it('forbids reaching the component directly without permission', function () {
    // A Livewire component is reachable without its route, so mount() must
    // authorise on its own.
    actingAs(academicsUiUserAs(Role::Teacher));

    Livewire::test(AcademicSettings::class)->assertForbidden();
});

it('shows the empty state before any year exists', function () {
    actingAs(academicsUiUserAs(Role::Administrator));

    Livewire::test(AcademicSettings::class)
        ->assertSee(__('opes.academics.no_year'));
});

it('creates the first academic year end-to-end through the component', function () {
    actingAs(academicsUiUserAs(Role::Administrator));

    Livewire::test(AcademicSettings::class)
        ->set('code', '2026-2027')
        ->set('name', 'Academic Year 2026/2027')
        ->set('startsOn', '2026-09-01')
        ->set('endsOn', '2027-08-31')
        ->call('createYear')
        ->assertHasNoErrors();

    $year = AcademicYear::query()->where('code', '2026-2027')->firstOrFail();

    expect($year->name)->toBe('Academic Year 2026/2027');
    expect($year->starts_on->toDateString())->toBe('2026-09-01');
    expect($year->ends_on->toDateString())->toBe('2027-08-31');
});

it('surfaces the gap-year rejection as a readable inline error, not a 500', function () {
    actingAs(academicsUiUserAs(Role::Administrator));

    AcademicYear::factory()->create([
        'code' => '2026-2027',
        'starts_on' => '2026-09-01',
        'ends_on' => '2027-08-31',
    ]);

    Livewire::test(AcademicSettings::class)
        ->set('code', '2027-2028')
        ->set('name', 'Academic Year 2027/2028')
        // A one-week gap: the domain demands 2027-09-01.
        ->set('startsOn', '2027-09-08')
        ->set('endsOn', '2028-08-31')
        ->call('createYear')
        ->assertHasErrors('startsOn')
        // The DomainException's own wording, rendered inline under the field.
        ->assertSee('contiguous and gapless')
        ->assertSee('2027-09-01');

    expect(AcademicYear::query()->count())->toBe(1);
});

it('sets a year as the current session', function () {
    actingAs(academicsUiUserAs(Role::Administrator));

    $year = AcademicYear::factory()->create([
        'code' => '2026-2027',
        'starts_on' => '2026-09-01',
        'ends_on' => '2027-08-31',
    ]);

    Livewire::test(AcademicSettings::class)
        ->call('setCurrent', $year->id);

    expect($year->refresh()->is_current)->toBeTrue();
});

it('defines a three-term structure through the component', function () {
    actingAs(academicsUiUserAs(Role::Administrator));

    AcademicYear::factory()->current()->create([
        'code' => '2026-2027',
        'starts_on' => '2026-09-01',
        'ends_on' => '2027-08-31',
    ]);

    Livewire::test(AcademicSettings::class)
        ->set('termCount', 3)
        ->set('termDates', [
            ['starts_on' => '2026-09-01', 'ends_on' => '2026-12-20'],
            ['starts_on' => '2026-12-21', 'ends_on' => '2027-03-28'],
            ['starts_on' => '2027-03-29', 'ends_on' => '2027-08-31'],
        ])
        ->call('saveTerms')
        ->assertHasNoErrors();

    // One year-root node plus the three term children.
    expect(AssessmentPeriod::query()->count())->toBe(4);
    expect(AssessmentPeriod::query()->whereNotNull('parent_id')->count())->toBe(3);
});

it('surfaces a term gap as a readable inline error', function () {
    actingAs(academicsUiUserAs(Role::Administrator));

    AcademicYear::factory()->current()->create([
        'code' => '2026-2027',
        'starts_on' => '2026-09-01',
        'ends_on' => '2027-08-31',
    ]);

    Livewire::test(AcademicSettings::class)
        ->set('termCount', 2)
        ->set('termDates', [
            ['starts_on' => '2026-09-01', 'ends_on' => '2026-12-20'],
            // Gap: Term 2 must start 2026-12-21.
            ['starts_on' => '2027-01-05', 'ends_on' => '2027-08-31'],
        ])
        ->call('saveTerms')
        ->assertHasErrors('termDates')
        ->assertSee('contiguous and non-overlapping');

    expect(AssessmentPeriod::query()->count())->toBe(0);
});

it('explains that terms need a year before one exists', function () {
    actingAs(academicsUiUserAs(Role::Administrator));

    Livewire::test(AcademicSettings::class)
        ->set('termDates', [
            ['starts_on' => '2026-09-01', 'ends_on' => '2026-12-20'],
            ['starts_on' => '2026-12-21', 'ends_on' => '2027-08-31'],
        ])
        ->set('termCount', 2)
        ->call('saveTerms')
        ->assertHasErrors('termDates');
});
