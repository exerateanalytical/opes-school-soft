<?php

declare(strict_types=1);

// The Classes screen's component has always carried startCreateLevel /
// startEditLevel / saveLevel and toggleStreamForm / saveStream, but the Blade
// rendered no control that reached any of them: CreateClassLevel,
// UpdateClassLevel and CreateStream were unreachable from the UI (and the
// success messages they flash had no lang keys at all). These tests pin the
// controls to their Actions.

use App\Modules\Academics\Livewire\ClassGroups\Index;
use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Academics\Models\ClassLevel;
use App\Modules\Academics\Models\SchoolSection;
use App\Modules\Academics\Models\Stream;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

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

it('renders the levels and streams controls for a manager', function () {
    actingAs(academicsUiUserAs(Role::Administrator));
    AcademicYear::factory()->current()->create();

    Livewire::test(Index::class)
        ->assertSee(__('opes.classes_screen.structure_heading'))
        ->assertSee(__('opes.classes_screen.add_level'))
        ->assertSee(__('opes.classes_screen.add_stream'));
});

it('creates a class level through the screen', function () {
    actingAs(academicsUiUserAs(Role::Administrator));
    AcademicYear::factory()->current()->create();
    $section = SchoolSection::factory()->create();

    Livewire::test(Index::class)
        ->call('startCreateLevel')
        ->assertSet('showLevelForm', true)
        ->set('levelSectionId', (string) $section->getKey())
        ->set('levelCode', 'F1')
        ->set('levelName', 'Form One')
        ->set('levelNameFr', 'Sixième')
        ->set('levelOrderIndex', '1')
        ->set('levelIsExamClass', false)
        ->call('saveLevel')
        ->assertHasNoErrors();

    $level = ClassLevel::query()->where('code', 'F1')->firstOrFail();

    expect($level->school_section_id)->toBe($section->id)
        ->and($level->name)->toBe('Form One')
        ->and($level->name_fr)->toBe('Sixième')
        ->and($level->order_index)->toBe(1);
});

it('edits an existing class level through the screen', function () {
    actingAs(academicsUiUserAs(Role::Administrator));
    AcademicYear::factory()->current()->create();
    $level = ClassLevel::factory()->create(['name' => 'Form One']);

    Livewire::test(Index::class)
        ->call('startEditLevel', $level->getKey())
        ->assertSet('levelName', 'Form One')
        ->set('levelName', 'Form One Renamed')
        ->call('saveLevel')
        ->assertHasNoErrors();

    expect($level->refresh()->name)->toBe('Form One Renamed');
});

it('creates a stream with its subject basket through the screen', function () {
    actingAs(academicsUiUserAs(Role::Administrator));
    AcademicYear::factory()->current()->create();
    $section = SchoolSection::factory()->create();

    Livewire::test(Index::class)
        ->call('toggleStreamForm')
        ->assertSet('showStreamForm', true)
        ->set('streamSectionId', (string) $section->getKey())
        ->set('streamCode', 'SCI')
        ->set('streamName', 'Science')
        ->set('streamNameFr', 'Scientifique')
        ->set('streamSubjectBasket', 'MATH101, PHY101 ,, CHEM101')
        ->call('saveStream')
        ->assertHasNoErrors();

    $stream = Stream::query()->where('code', 'SCI')->firstOrFail();

    expect($stream->school_section_id)->toBe($section->id)
        // Blanks trimmed out - the field is a comma-separated code list.
        ->and($stream->subject_basket)->toBe(['MATH101', 'PHY101', 'CHEM101']);
});

it('refuses level and stream management without academics.manage', function () {
    actingAs(academicsUiUserAs(Role::Teacher));
    AcademicYear::factory()->current()->create();

    Livewire::test(Index::class)->call('startCreateLevel')->assertForbidden();
    Livewire::test(Index::class)->call('toggleStreamForm')->assertForbidden();
});
