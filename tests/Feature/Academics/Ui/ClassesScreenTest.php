<?php

declare(strict_types=1);

use App\Modules\Academics\Livewire\ClassGroups\Index;
use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Academics\Models\ClassGroup;
use App\Modules\Academics\Models\ClassLevel;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/* Shared with the other Academics UI files - see AcademicSettingsTest. */
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

    get('/classes')->assertOk()->assertSee('OPES');
});

it('403s on the route for a role without academics.view', function () {
    actingAs(academicsUiUserAs(Role::Bursar));

    get('/classes')->assertForbidden();
});

it('forbids reaching the component directly without permission', function () {
    actingAs(academicsUiUserAs(Role::Bursar));

    Livewire::test(Index::class)->assertForbidden();
});

it('explains itself instead of crashing when no current year exists', function () {
    // Class groups are per-year; a planned year that is not current is not
    // enough. The screen must say so helpfully, not render a bare table.
    actingAs(academicsUiUserAs(Role::Administrator));
    AcademicYear::factory()->create();

    Livewire::test(Index::class)
        ->assertSee(__('opes.classes_screen.no_year'));

    get('/classes')->assertOk();
});

it('lists class groups of the current year only', function () {
    actingAs(academicsUiUserAs(Role::Administrator));

    $current = AcademicYear::factory()->current()->create();
    $other = AcademicYear::factory()->create();

    ClassGroup::factory()->create(['academic_year_id' => $current->id, 'name' => 'Form 1 Alpha']);
    ClassGroup::factory()->create(['academic_year_id' => $other->id, 'name' => 'Form 1 Ghost']);

    Livewire::test(Index::class)
        ->assertSee('Form 1 Alpha')
        ->assertDontSee('Form 1 Ghost');
});

it('creates a class group end-to-end through the component', function () {
    actingAs(academicsUiUserAs(Role::Administrator));

    $year = AcademicYear::factory()->current()->create();
    $level = ClassLevel::factory()->create();

    Livewire::test(Index::class)
        ->call('startCreate')
        ->set('className', 'Form 1 B')
        ->set('classLevelId', (string) $level->id)
        ->set('capacity', '60')
        ->call('save')
        ->assertHasNoErrors();

    $classGroup = ClassGroup::query()->where('name', 'Form 1 B')->firstOrFail();

    expect($classGroup->academic_year_id)->toBe($year->id);
    expect($classGroup->class_level_id)->toBe($level->id);
    expect($classGroup->capacity)->toBe(60);
});

it('is readable but not mutable with academics.view only', function () {
    actingAs(academicsUiUserAs(Role::Teacher));

    AcademicYear::factory()->current()->create();

    Livewire::test(Index::class)
        ->call('startCreate')
        ->assertForbidden();
});

it('filters by search term', function () {
    actingAs(academicsUiUserAs(Role::Administrator));

    $year = AcademicYear::factory()->current()->create();
    ClassGroup::factory()->create(['academic_year_id' => $year->id, 'name' => 'Form 1 Alpha']);
    ClassGroup::factory()->create(['academic_year_id' => $year->id, 'name' => 'Form 2 Beta']);

    Livewire::test(Index::class)
        ->set('search', 'Alpha')
        ->assertSee('Form 1 Alpha')
        ->assertDontSee('Form 2 Beta');
});
