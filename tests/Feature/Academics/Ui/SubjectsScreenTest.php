<?php

declare(strict_types=1);

use App\Modules\Academics\Livewire\Subjects\Index;
use App\Modules\Academics\Models\Subject;
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

    get('/subjects')->assertOk()->assertSee('OPES');
});

it('403s on the route for a role without academics.view', function () {
    actingAs(academicsUiUserAs(Role::Bursar));

    get('/subjects')->assertForbidden();
});

it('forbids reaching the component directly without permission', function () {
    actingAs(academicsUiUserAs(Role::Bursar));

    Livewire::test(Index::class)->assertForbidden();
});

it('lists a created subject', function () {
    actingAs(academicsUiUserAs(Role::Administrator));
    Subject::factory()->create(['code' => 'MATH101', 'name' => 'Mathematics']);

    Livewire::test(Index::class)
        ->assertSee('MATH101')
        ->assertSee('Mathematics');
});

it('is readable but not mutable with academics.view only', function () {
    // Teacher holds academics.view but not academics.manage: the list renders,
    // the mutation entry point refuses.
    actingAs(academicsUiUserAs(Role::Teacher));
    Subject::factory()->create(['name' => 'Mathematics']);

    Livewire::test(Index::class)
        ->assertSee('Mathematics')
        ->call('startCreate')
        ->assertForbidden();
});

it('creates a subject end-to-end through the component', function () {
    actingAs(academicsUiUserAs(Role::Administrator));

    Livewire::test(Index::class)
        ->call('startCreate')
        ->set('subjectCode', 'PHY101')
        ->set('subjectName', 'Physics')
        ->set('subjectNameFr', 'Physique')
        ->call('save')
        ->assertHasNoErrors();

    $subject = Subject::query()->where('code', 'PHY101')->firstOrFail();

    expect($subject->name)->toBe('Physics');
    expect($subject->name_fr)->toBe('Physique');
    expect($subject->is_active)->toBeTrue();
});

it('edits a subject through the component', function () {
    actingAs(academicsUiUserAs(Role::Administrator));
    $subject = Subject::factory()->create(['code' => 'CHE101', 'name' => 'Chemistry']);

    Livewire::test(Index::class)
        ->call('startEdit', $subject->id)
        ->assertSet('subjectCode', 'CHE101')
        ->set('subjectName', 'Chemistry (Advanced)')
        ->set('subjectActive', false)
        ->call('save')
        ->assertHasNoErrors();

    $subject->refresh();

    expect($subject->name)->toBe('Chemistry (Advanced)');
    expect($subject->is_active)->toBeFalse();
});

it('rejects a duplicate subject code', function () {
    actingAs(academicsUiUserAs(Role::Administrator));
    Subject::factory()->create(['code' => 'BIO101']);

    Livewire::test(Index::class)
        ->call('startCreate')
        ->set('subjectCode', 'BIO101')
        ->set('subjectName', 'Biology')
        ->call('save')
        ->assertHasErrors('subjectCode');
});

it('filters by search term', function () {
    actingAs(academicsUiUserAs(Role::Administrator));
    Subject::factory()->create(['code' => 'MATH101', 'name' => 'Mathematics']);
    Subject::factory()->create(['code' => 'HIS101', 'name' => 'History']);

    Livewire::test(Index::class)
        ->set('search', 'Math')
        ->assertSee('Mathematics')
        ->assertDontSee('History');
});

it('filters by status', function () {
    actingAs(academicsUiUserAs(Role::Administrator));
    Subject::factory()->create(['name' => 'Living Subject']);
    Subject::factory()->inactive()->create(['name' => 'Retired Subject']);

    Livewire::test(Index::class)
        ->set('status', 'inactive')
        ->assertSee('Retired Subject')
        ->assertDontSee('Living Subject');
});

it('paginates rather than loading every subject', function () {
    actingAs(academicsUiUserAs(Role::Administrator));
    Subject::factory()->count(30)->create();

    Livewire::test(Index::class)
        ->assertViewHas('subjects', fn ($subjects) => $subjects->perPage() <= 25 && $subjects->total() === 30);
});
