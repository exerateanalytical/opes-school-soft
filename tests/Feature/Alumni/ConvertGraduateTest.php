<?php

declare(strict_types=1);

use App\Modules\Alumni\Actions\ConvertGraduateToAlumnus;
use App\Modules\Alumni\Models\AlumnusRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/AlumniTestHelpers.php';

uses(RefreshDatabase::class);

it('converts a graduate, freezing the year and the final class name label-at-time', function () {
    $user = alumManager();
    $fixture = alumExitFixture('Upper Sixth Arts B');
    $studentId = alumGraduate($fixture, '2030-06-15');

    $record = app(ConvertGraduateToAlumnus::class)->handle($studentId, alumActor($user));

    expect($record->student_id)->toBe($studentId)
        ->and($record->graduation_year)->toBe(2030)
        ->and($record->final_class_group_name)->toBe('Upper Sixth Arts B')
        ->and($record->academic_year_name)->toBe('Academic Year 2029/2030')
        ->and($record->is_deceased)->toBeFalse();
});

it('survives a later class-group rename - the denormalised label does not move', function () {
    $user = alumManager();
    $fixture = alumExitFixture('Upper Sixth Science A');
    $studentId = alumGraduate($fixture);

    $record = app(ConvertGraduateToAlumnus::class)->handle($studentId, alumActor($user));

    // The rename the label-at-time discipline exists to survive.
    \Illuminate\Support\Facades\DB::table('class_groups')
        ->where('id', $fixture['group_id'])
        ->update(['name' => 'Terminale S1 (renamed)']);

    expect($record->fresh()?->final_class_group_name)->toBe('Upper Sixth Science A');
});

it('seeds the alumnus contact from what the student roll already knows', function () {
    $user = alumManager();
    $fixture = alumExitFixture();
    $studentId = alumGraduate($fixture);

    \Illuminate\Support\Facades\DB::table('students')->where('id', $studentId)->update([
        'email' => 'grad@example.cm',
        'phone' => '+237677000001',
    ]);

    $record = app(ConvertGraduateToAlumnus::class)->handle($studentId, alumActor($user));

    expect($record->contact_email)->toBe('grad@example.cm')
        ->and($record->contact_phone)->toBe('+237677000001')
        ->and($record->isReachable())->toBeTrue();
});

it('refuses a student who is not graduated', function () {
    $user = alumManager();
    $studentId = alumActiveStudent();

    expect(fn () => app(ConvertGraduateToAlumnus::class)->handle($studentId, alumActor($user)))
        ->toThrow(DomainException::class, 'not graduated');

    expect(AlumnusRecord::query()->count())->toBe(0);
});

it('refuses a double conversion', function () {
    $user = alumManager();
    $fixture = alumExitFixture();
    $studentId = alumGraduate($fixture);

    app(ConvertGraduateToAlumnus::class)->handle($studentId, alumActor($user));

    expect(fn () => app(ConvertGraduateToAlumnus::class)->handle($studentId, alumActor($user)))
        ->toThrow(DomainException::class, 'already been converted');

    expect(AlumnusRecord::query()->where('student_id', $studentId)->count())->toBe(1);
});

it('refuses an unknown student', function () {
    $user = alumManager();

    expect(fn () => app(ConvertGraduateToAlumnus::class)->handle(999999, alumActor($user)))
        ->toThrow(DomainException::class, 'does not exist');
});

it('refuses the conversion door to a user without alumni.manage', function () {
    $viewer = alumUser(\App\Modules\Identity\Domain\Permission::AlumniView->value);
    $fixture = alumExitFixture();
    $studentId = alumGraduate($fixture);

    expect(fn () => app(ConvertGraduateToAlumnus::class)->handle($studentId, alumActor($viewer)))
        ->toThrow(AuthorizationException::class);
});
