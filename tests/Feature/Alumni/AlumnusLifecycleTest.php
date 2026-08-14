<?php

declare(strict_types=1);

use App\Modules\Alumni\Actions\MarkDeceased;
use App\Modules\Alumni\Actions\RecordEngagement;
use App\Modules\Alumni\Actions\UpdateAlumnusContact;
use App\Modules\Alumni\Domain\EngagementType;
use App\Modules\Alumni\Models\AlumniEngagement;
use App\Modules\Alumni\Models\AlumnusRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/AlumniTestHelpers.php';

uses(RefreshDatabase::class);

// ── UpdateAlumnusContact ───────────────────────────────────────────────────

it('updates the mutable contact half and leaves the frozen graduation facts alone', function () {
    $user = alumManager();
    $record = AlumnusRecord::factory()->create();

    $updated = app(UpdateAlumnusContact::class)->handle((int) $record->getKey(), [
        'current_occupation' => 'Nurse',
        'current_organisation' => 'Mbingo Baptist Hospital',
        'contact_email' => 'nurse@example.cm',
        'contact_phone' => '',
        'notes' => 'Keen to speak at careers day.',
    ], alumActor($user));

    expect($updated->current_occupation)->toBe('Nurse')
        ->and($updated->current_organisation)->toBe('Mbingo Baptist Hospital')
        ->and($updated->contact_email)->toBe('nurse@example.cm')
        ->and($updated->contact_phone)->toBeNull()
        ->and($updated->graduation_year)->toBe($record->graduation_year)
        ->and($updated->final_class_group_name)->toBe($record->final_class_group_name);
});

it('treats an empty string as clearing the field', function () {
    $user = alumManager();
    $record = AlumnusRecord::factory()->reachable()->create();

    $updated = app(UpdateAlumnusContact::class)->handle((int) $record->getKey(), [
        'contact_email' => '',
    ], alumActor($user));

    expect($updated->contact_email)->toBeNull()
        ->and($updated->isReachable())->toBeFalse();
});

// ── RecordEngagement ───────────────────────────────────────────────────────

it('appends an engagement to the log', function () {
    $user = alumManager();
    $record = AlumnusRecord::factory()->create();

    $engagement = app(RecordEngagement::class)->handle((int) $record->getKey(), [
        'type' => 'donation',
        'engaged_on' => '2031-03-01',
        'note' => 'Donated 50 chairs for the assembly hall.',
    ], alumActor($user));

    expect($engagement->type)->toBe(EngagementType::Donation)
        ->and($engagement->engaged_on->toDateString())->toBe('2031-03-01')
        ->and($engagement->alumnus_record_id)->toBe((int) $record->getKey());

    expect(AlumniEngagement::query()->count())->toBe(1);
});

it('refuses an engagement with a blank note or a future date', function () {
    $user = alumManager();
    $record = AlumnusRecord::factory()->create();

    expect(fn () => app(RecordEngagement::class)->handle((int) $record->getKey(), [
        'type' => 'visit',
        'engaged_on' => '2031-03-01',
        'note' => '   ',
    ], alumActor($user)))->toThrow(DomainException::class, 'note');

    expect(fn () => app(RecordEngagement::class)->handle((int) $record->getKey(), [
        'type' => 'visit',
        'engaged_on' => \Illuminate\Support\Carbon::now()->addYear()->toDateString(),
        'note' => 'Time traveller.',
    ], alumActor($user)))->toThrow(DomainException::class, 'future');

    expect(AlumniEngagement::query()->count())->toBe(0);
});

// ── MarkDeceased ───────────────────────────────────────────────────────────

it('marks an alumnus deceased exactly once - the flag is one-way', function () {
    $user = alumManager();
    $record = AlumnusRecord::factory()->create();

    $marked = app(MarkDeceased::class)->handle((int) $record->getKey(), alumActor($user));

    expect($marked->is_deceased)->toBeTrue();

    expect(fn () => app(MarkDeceased::class)->handle((int) $record->getKey(), alumActor($user)))
        ->toThrow(DomainException::class, 'one-way');

    expect($record->fresh()?->is_deceased)->toBeTrue();
});

// ── Authorisation ──────────────────────────────────────────────────────────

it('refuses every write door to a view-only user', function () {
    $viewer = alumUser(\App\Modules\Identity\Domain\Permission::AlumniView->value);
    $record = AlumnusRecord::factory()->create();
    $id = (int) $record->getKey();

    expect(fn () => app(UpdateAlumnusContact::class)->handle($id, ['notes' => 'x'], alumActor($viewer)))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(RecordEngagement::class)->handle($id, [
        'type' => 'visit', 'engaged_on' => '2031-01-01', 'note' => 'x',
    ], alumActor($viewer)))->toThrow(AuthorizationException::class);
    expect(fn () => app(MarkDeceased::class)->handle($id, alumActor($viewer)))
        ->toThrow(AuthorizationException::class);
});
