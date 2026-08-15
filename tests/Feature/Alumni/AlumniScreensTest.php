<?php

declare(strict_types=1);

use App\Modules\Alumni\Livewire\Index as AlumniIndex;
use App\Modules\Alumni\Livewire\Show as AlumniShow;
use App\Modules\Alumni\Models\AlumniEngagement;
use App\Modules\Alumni\Models\AlumnusRecord;
use App\Modules\Identity\Domain\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

require_once __DIR__.'/AlumniTestHelpers.php';

uses(RefreshDatabase::class);

// ── Index ──────────────────────────────────────────────────────────────────

it('renders the register with KPIs, rows and the status pill', function () {
    alumUserAs(Role::Registrar);

    $record = AlumnusRecord::factory()->reachable()->create([
        'graduation_year' => 2028,
        'final_class_group_name' => 'Upper Sixth Arts A',
        'current_occupation' => 'Engineer',
    ]);
    \Illuminate\Support\Facades\DB::table('students')->where('id', $record->student_id)
        ->update(['first_name' => 'Ngwa', 'last_name' => 'Fongum']);

    Livewire::test(AlumniIndex::class)
        ->assertSee(__('alumni.title'))
        ->assertSee(__('alumni.kpi_total'))
        ->assertSee(__('alumni.kpi_reachable'))
        ->assertSee('Fongum')
        ->assertSee('Upper Sixth Arts A')
        ->assertSee('Engineer')
        ->assertSee(__('alumni.status_reachable'));
});

it('filters by graduation year and by occupation', function () {
    alumUserAs(Role::Registrar);

    $a = AlumnusRecord::factory()->create(['graduation_year' => 2027, 'current_occupation' => 'Teacher']);
    $b = AlumnusRecord::factory()->create(['graduation_year' => 2030, 'current_occupation' => 'Farmer']);
    \Illuminate\Support\Facades\DB::table('students')->where('id', $a->student_id)
        ->update(['last_name' => 'Yearmarker27']);
    \Illuminate\Support\Facades\DB::table('students')->where('id', $b->student_id)
        ->update(['last_name' => 'Yearmarker30']);

    Livewire::test(AlumniIndex::class)
        ->assertSee('Yearmarker27')
        ->assertSee('Yearmarker30')
        ->set('year', '2027')
        ->assertSee('Yearmarker27')
        ->assertDontSee('Yearmarker30')
        ->set('year', '')
        ->set('occupation', 'Farmer')
        ->assertSee('Yearmarker30')
        ->assertDontSee('Yearmarker27');
});

it('refuses the list to a role without alumni.view', function () {
    alumUserAs(Role::Teacher);

    Livewire::test(AlumniIndex::class)->assertForbidden();
});

it('answers 403 over HTTP without alumni.view and 200 with it', function () {
    alumUserAs(Role::Teacher);
    get('/alumni')->assertForbidden();

    alumUserAs(Role::Registrar);
    get('/alumni')->assertOk();
});

it('lists unconverted graduates in the convert panel and converts the selected ones', function () {
    alumUserAs(Role::Registrar);

    $fixture = alumExitFixture('Upper Sixth Science C');
    $graduateId = alumGraduate($fixture);
    \Illuminate\Support\Facades\DB::table('students')->where('id', $graduateId)
        ->update(['first_name' => 'Convertme', 'last_name' => 'Graduate']);

    // A non-graduate must not appear in the panel.
    $activeId = alumActiveStudent();
    \Illuminate\Support\Facades\DB::table('students')->where('id', $activeId)
        ->update(['first_name' => 'Stillhere', 'last_name' => 'Active']);

    Livewire::test(AlumniIndex::class)
        ->call('toggleConvertPanel')
        ->assertSee('Convertme')
        ->assertDontSee('Stillhere')
        ->set('selectedStudentIds', [(string) $graduateId])
        ->call('convertSelected')
        ->assertHasNoErrors();

    $record = AlumnusRecord::query()->where('student_id', $graduateId)->firstOrFail();

    expect($record->final_class_group_name)->toBe('Upper Sixth Science C')
        ->and($record->graduation_year)->toBe(2030);
});

it('hides the convert door from a view-only role', function () {
    alumUserAs(Role::Principal);

    Livewire::test(AlumniIndex::class)
        ->assertSee(__('alumni.title'))
        ->call('toggleConvertPanel')
        ->assertForbidden();
});

// ── Show ───────────────────────────────────────────────────────────────────

it('renders one alumnus file with the profile card and the engagement timeline', function () {
    alumUserAs(Role::Registrar);

    $record = AlumnusRecord::factory()->create([
        'graduation_year' => 2029,
        'final_class_group_name' => 'Terminale D2',
        'academic_year_name' => 'Academic Year 2028/2029',
        'current_occupation' => 'Journalist',
    ]);
    \Illuminate\Support\Facades\DB::table('students')->where('id', $record->student_id)
        ->update(['first_name' => 'Bih', 'last_name' => 'Anye']);

    AlumniEngagement::factory()->create([
        'alumnus_record_id' => $record->getKey(),
        'type' => 'talk',
        'note' => 'Spoke to the press club about journalism.',
    ]);

    Livewire::test(AlumniShow::class, ['alumnus' => (int) $record->getKey()])
        ->assertSee('Anye')
        ->assertSee('Terminale D2')
        ->assertSee('Academic Year 2028/2029')
        ->assertSee('Journalist')
        ->assertSee(__('alumni.engagement_type.talk'))
        ->assertSee('Spoke to the press club about journalism.');
});

it('records an engagement from the rail form', function () {
    alumUserAs(Role::Registrar);
    $record = AlumnusRecord::factory()->create();

    Livewire::test(AlumniShow::class, ['alumnus' => (int) $record->getKey()])
        ->set('engagementType', 'mentorship')
        ->set('engagedOn', '2031-02-01')
        ->set('engagementNote', 'Mentoring two Form 5 science students.')
        ->call('recordEngagement')
        ->assertHasNoErrors();

    $engagement = AlumniEngagement::query()->firstOrFail();

    expect($engagement->alumnus_record_id)->toBe((int) $record->getKey())
        ->and($engagement->type->value)->toBe('mentorship');
});

it('updates contact and marks deceased from the file, and the deceased action is one-way', function () {
    alumUserAs(Role::Registrar);
    $record = AlumnusRecord::factory()->create();

    Livewire::test(AlumniShow::class, ['alumnus' => (int) $record->getKey()])
        ->call('toggleContactForm')
        ->set('contactOccupation', 'Pharmacist')
        ->set('contactEmail', 'pharm@example.cm')
        ->call('saveContact')
        ->assertHasNoErrors()
        ->call('markDeceased')
        ->assertHasNoErrors()
        ->call('markDeceased')
        ->assertHasErrors('deceased');

    $fresh = $record->fresh();

    expect($fresh?->current_occupation)->toBe('Pharmacist')
        ->and($fresh?->contact_email)->toBe('pharm@example.cm')
        ->and($fresh?->is_deceased)->toBeTrue();
});

it('refuses the file to a role without alumni.view and 403s over HTTP', function () {
    $record = AlumnusRecord::factory()->create();

    alumUserAs(Role::Teacher);

    Livewire::test(AlumniShow::class, ['alumnus' => (int) $record->getKey()])->assertForbidden();
    get('/alumni/'.$record->getKey())->assertForbidden();
});

it('refuses a record that does not exist rather than rendering an empty file', function () {
    alumUserAs(Role::Registrar);

    expect(fn () => Livewire::test(AlumniShow::class, ['alumnus' => 999999]))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('lets a Principal view but hides the manage actions', function () {
    alumUserAs(Role::Principal);
    $record = AlumnusRecord::factory()->create();

    Livewire::test(AlumniShow::class, ['alumnus' => (int) $record->getKey()])
        ->assertOk()
        ->assertDontSee(__('alumni.mark_deceased'))
        ->call('markDeceased')
        ->assertForbidden();
});
