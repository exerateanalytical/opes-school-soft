<?php

declare(strict_types=1);

// The Subjects screen could EDIT a subject allocation's coefficient but had
// no control that created one: AllocateSubject existed and nothing reached
// it, so the allocation panel could only ever show "no allocations".

use App\Modules\Academics\Livewire\Subjects\Index;
use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Academics\Models\ClassLevel;
use App\Modules\Academics\Models\SchoolSection;
use App\Modules\Academics\Models\Stream;
use App\Modules\Academics\Models\Subject;
use App\Modules\Academics\Models\SubjectAllocation;
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

it('allocates a subject to a class level through the screen', function () {
    actingAs(academicsUiUserAs(Role::Administrator));

    $year = AcademicYear::factory()->current()->create();
    $level = ClassLevel::factory()->create();
    $subject = Subject::factory()->create();

    Livewire::test(Index::class)
        ->call('toggleAllocations', $subject->getKey())
        ->call('toggleNewAllocationForm')
        ->assertSet('showNewAllocationForm', true)
        ->set('newAllocClassLevelId', (string) $level->getKey())
        ->set('newAllocCoefficient', '3')
        ->set('newAllocIsOptional', true)
        ->set('newAllocCountsTowardAverage', false)
        ->call('saveNewAllocation')
        ->assertHasNoErrors();

    $allocation = SubjectAllocation::query()->where('subject_id', $subject->getKey())->firstOrFail();

    expect($allocation->academic_year_id)->toBe($year->id)
        ->and($allocation->class_level_id)->toBe($level->id)
        // A null stream is stored as the 0 sentinel, never NULL.
        ->and($allocation->stream_id)->toBe(SubjectAllocation::STREAM_NONE)
        ->and((float) $allocation->coefficient)->toBe(3.0)
        ->and($allocation->is_optional)->toBeTrue()
        ->and($allocation->counts_toward_average)->toBeFalse();
});

it('allocates against a specific stream when one is chosen', function () {
    actingAs(academicsUiUserAs(Role::Administrator));

    AcademicYear::factory()->current()->create();
    $section = SchoolSection::factory()->create();
    $level = ClassLevel::factory()->create(['school_section_id' => $section->getKey()]);
    $stream = Stream::factory()->create(['school_section_id' => $section->getKey()]);
    $subject = Subject::factory()->create();

    Livewire::test(Index::class)
        ->call('toggleAllocations', $subject->getKey())
        ->call('toggleNewAllocationForm')
        ->set('newAllocClassLevelId', (string) $level->getKey())
        ->set('newAllocStreamId', (string) $stream->getKey())
        ->set('newAllocCoefficient', '2')
        ->call('saveNewAllocation')
        ->assertHasNoErrors();

    expect(SubjectAllocation::query()->firstOrFail()->stream_id)->toBe($stream->id);
});

it('surfaces the duplicate-allocation refusal inline instead of crashing', function () {
    actingAs(academicsUiUserAs(Role::Administrator));

    AcademicYear::factory()->current()->create();
    $level = ClassLevel::factory()->create();
    $subject = Subject::factory()->create();

    $allocate = function () use ($subject, $level) {
        return Livewire::test(Index::class)
            ->call('toggleAllocations', $subject->getKey())
            ->call('toggleNewAllocationForm')
            ->set('newAllocClassLevelId', (string) $level->getKey())
            ->set('newAllocCoefficient', '2')
            ->call('saveNewAllocation');
    };

    $allocate()->assertHasNoErrors();
    $allocate()->assertHasErrors('newAllocCoefficient');

    expect(SubjectAllocation::query()->count())->toBe(1);
});

it('explains itself rather than writing when no current year is set', function () {
    actingAs(academicsUiUserAs(Role::Administrator));

    // A year exists but none is current - there is no year to allocate to.
    AcademicYear::factory()->create();
    $level = ClassLevel::factory()->create();
    $subject = Subject::factory()->create();

    Livewire::test(Index::class)
        ->call('toggleAllocations', $subject->getKey())
        ->call('toggleNewAllocationForm')
        ->set('newAllocClassLevelId', (string) $level->getKey())
        ->set('newAllocCoefficient', '2')
        ->call('saveNewAllocation')
        ->assertHasErrors('newAllocClassLevelId');

    expect(SubjectAllocation::query()->count())->toBe(0);
});

it('refuses allocation without academics.manage', function () {
    actingAs(academicsUiUserAs(Role::Teacher));

    AcademicYear::factory()->current()->create();

    Livewire::test(Index::class)->call('toggleNewAllocationForm')->assertForbidden();
});
