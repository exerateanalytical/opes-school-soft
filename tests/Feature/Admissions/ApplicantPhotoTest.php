<?php

declare(strict_types=1);

use App\Modules\Admissions\Actions\ConvertApplication;
use App\Modules\Admissions\Actions\SetApplicantPhoto;
use App\Modules\Admissions\Actions\SubmitApplication;
use App\Modules\Admissions\Livewire\Wizard;
use App\Modules\Identity\Domain\Role;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/*
 * admissionsUserAs(), admissionsFixture(), admissionsCompleteDraft() and
 * friends are declared in AdmissionFlowTest behind function_exists() guards;
 * Pest loads every file in the suite into one process, so they are already
 * defined by the time this file runs.
 */

/**
 * The photograph lives on the PRIVATE default disk, not `public` - see
 * SetApplicantPhoto. Faking the default disk is what makes these assertions
 * about the real storage path rather than about a disk nothing reads.
 */
function admissionsPhotoDisk(): string
{
    return (string) config('filesystems.default');
}

beforeEach(function (): void {
    // Both, so a test can assert the bytes did NOT land on the public one.
    Storage::fake(admissionsPhotoDisk());
    Storage::fake('public');
});

it('stores an applicant photo and puts the file on disk', function () {
    actingAs(admissionsUserAs(Role::Registrar));

    $application = admissionsCompleteDraft(admissionsFixture());

    $saved = app(SetApplicantPhoto::class)->handle(
        $application,
        UploadedFile::fake()->image('applicant.jpg', 400, 500),
    );

    expect($saved->photo_path)->toBeString()
        ->and($saved->photo_path)->toStartWith(SetApplicantPhoto::DIRECTORY.'/');

    Storage::disk(admissionsPhotoDisk())->assertExists((string) $saved->photo_path);

    // The column, not just the in-memory model.
    expect((string) DB::table('admission_applications')
        ->where('id', $application->getKey())
        ->value('photo_path'))->toBe((string) $saved->photo_path);
});

it('deletes the previous file when the photo is replaced', function () {
    actingAs(admissionsUserAs(Role::Registrar));

    $action = app(SetApplicantPhoto::class);
    $application = admissionsCompleteDraft(admissionsFixture());

    $first = (string) $action->handle(
        $application,
        UploadedFile::fake()->image('first.jpg', 400, 500),
    )->photo_path;

    // Different pixel dimensions, so different bytes, so - by content hash -
    // a different path. Replacing with IDENTICAL bytes would legitimately
    // yield the same path and forget() must not then delete it.
    $second = (string) $action->handle(
        admissionsReload((int) $application->getKey()),
        UploadedFile::fake()->image('second.jpg', 401, 501),
    )->photo_path;

    expect($second)->not->toBe($first);

    Storage::disk(admissionsPhotoDisk())->assertMissing($first);
    Storage::disk(admissionsPhotoDisk())->assertExists($second);
});

it('clears the column and the file when the photo is removed', function () {
    actingAs(admissionsUserAs(Role::Registrar));

    $action = app(SetApplicantPhoto::class);
    $application = admissionsCompleteDraft(admissionsFixture());

    $path = (string) $action->handle(
        $application,
        UploadedFile::fake()->image('applicant.jpg', 400, 500),
    )->photo_path;

    $action->handle(admissionsReload((int) $application->getKey()), null);

    expect(admissionsReload((int) $application->getKey())->photo_path)->toBeNull();
    Storage::disk(admissionsPhotoDisk())->assertMissing($path);
});

it('refuses to change the photo once the application has left draft', function () {
    actingAs(admissionsUserAs(Role::Registrar));

    $submitted = app(SubmitApplication::class)->handle(
        admissionsCompleteDraft(admissionsFixture()),
    );

    expect(fn () => app(SetApplicantPhoto::class)->handle(
        $submitted,
        UploadedFile::fake()->image('applicant.jpg', 400, 500),
    ))->toThrow(ValidationException::class);
});

it('denies the applicant photo to a role without admissions.manage', function () {
    actingAs(admissionsUserAs(Role::Registrar));
    $application = admissionsCompleteDraft(admissionsFixture());

    actingAs(admissionsUserAs(Role::Teacher));

    expect(fn () => app(SetApplicantPhoto::class)->handle(
        $application,
        UploadedFile::fake()->image('applicant.jpg', 400, 500),
    ))->toThrow(AuthorizationException::class);

    expect((string) DB::table('admission_applications')
        ->where('id', $application->getKey())
        ->value('photo_path'))->toBe('');
});

it('uploads and removes the photo from the wizard screen', function () {
    actingAs(admissionsUserAs(Role::Registrar));

    $application = admissionsCompleteDraft(admissionsFixture());

    $component = Livewire::test(Wizard::class, ['applicationId' => (string) $application->getKey()])
        ->set('photoUpload', UploadedFile::fake()->image('applicant.jpg', 400, 500))
        ->call('savePhoto')
        ->assertHasNoErrors();

    $path = (string) admissionsReload((int) $application->getKey())->photo_path;

    expect($path)->not->toBe('');
    Storage::disk(admissionsPhotoDisk())->assertExists($path);

    $component->call('removePhoto')->assertHasNoErrors();

    expect(admissionsReload((int) $application->getKey())->photo_path)->toBeNull();
    Storage::disk(admissionsPhotoDisk())->assertMissing($path);
});

it('refuses a non-image upload on the wizard screen', function () {
    actingAs(admissionsUserAs(Role::Registrar));

    $application = admissionsCompleteDraft(admissionsFixture());

    Livewire::test(Wizard::class, ['applicationId' => (string) $application->getKey()])
        ->set('photoUpload', UploadedFile::fake()->create('payload.pdf', 10, 'application/pdf'))
        ->call('savePhoto')
        ->assertHasErrors('photoUpload');

    expect(admissionsReload((int) $application->getKey())->photo_path)->toBeNull();
});

it('carries the applicant photo onto the student at enrolment', function () {
    actingAs(admissionsUserAs(Role::Registrar));

    $fixture = admissionsFixture();
    $application = admissionsCompleteDraft($fixture);

    $path = (string) app(SetApplicantPhoto::class)->handle(
        $application,
        UploadedFile::fake()->image('applicant.jpg', 400, 500),
    )->photo_path;

    $submitted = app(SubmitApplication::class)->handle(
        admissionsReload((int) $application->getKey()),
    );

    $result = app(ConvertApplication::class)->handle($submitted, $fixture['group']);

    // `students` belongs to another module - read it through the query
    // builder, per tests/Architecture/ModuleBoundaryTest.php.
    expect((string) DB::table('students')
        ->where('id', $result['student_id'])
        ->value('photo_path'))->toBe($path);

    // The file the student's row now points at is still there: conversion
    // hands the reference on, it does not move or re-write the file.
    Storage::disk(admissionsPhotoDisk())->assertExists($path);
});

it('leaves the student photo empty when the applicant had none', function () {
    actingAs(admissionsUserAs(Role::Registrar));

    $fixture = admissionsFixture();
    $submitted = app(SubmitApplication::class)->handle(admissionsCompleteDraft($fixture));

    $result = app(ConvertApplication::class)->handle($submitted, $fixture['group']);

    expect(DB::table('students')->where('id', $result['student_id'])->value('photo_path'))->toBeNull();
});

it('keeps an application photo out of the branding directory', function () {
    actingAs(admissionsUserAs(Role::Registrar));

    // The school crest and a child's photograph share StoredImage but must
    // never share a directory: 6.5 pseudonymises a rejected applicant's photo
    // away, and a purge walking `branding/` would take the crest with it.
    $saved = app(SetApplicantPhoto::class)->handle(
        admissionsCompleteDraft(admissionsFixture()),
        UploadedFile::fake()->image('applicant.jpg', 400, 500),
    );

    expect((string) $saved->photo_path)->not->toStartWith('branding/');
});

/*
 * ── Privacy: the bytes are not on a publicly served disk ────────────────────
 *
 * The `public` disk is symlinked into the web root and served with no
 * authentication. A content-hashed filename is obscurity, not access control,
 * and this is a photograph of a CHILD.
 */

it('never writes an applicant photo to the public disk', function () {
    actingAs(admissionsUserAs(Role::Registrar));

    $saved = app(SetApplicantPhoto::class)->handle(
        admissionsCompleteDraft(admissionsFixture()),
        UploadedFile::fake()->image('applicant.jpg', 400, 500),
    );

    $path = (string) $saved->photo_path;

    Storage::disk(admissionsPhotoDisk())->assertExists($path);
    Storage::disk('public')->assertMissing($path);

    // Nothing at all on the public disk, whatever the path happens to be.
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('refuses the applicant photo endpoint to an unauthenticated request', function () {
    actingAs(admissionsUserAs(Role::Registrar));

    $application = admissionsCompleteDraft(admissionsFixture());

    app(SetApplicantPhoto::class)->handle(
        $application,
        UploadedFile::fake()->image('applicant.jpg', 400, 500),
    );

    auth()->logout();

    $this->get(route('admissions.photo', ['application' => $application->getKey()]))
        ->assertRedirect('/login');
});

it('refuses the applicant photo endpoint to a user without admissions.manage', function () {
    actingAs(admissionsUserAs(Role::Registrar));

    $application = admissionsCompleteDraft(admissionsFixture());

    app(SetApplicantPhoto::class)->handle(
        $application,
        UploadedFile::fake()->image('applicant.jpg', 400, 500),
    );

    actingAs(admissionsUserAs(Role::Teacher));

    $this->get(route('admissions.photo', ['application' => $application->getKey()]))
        ->assertForbidden();
});

it('streams the applicant photo to an authorised user', function () {
    actingAs(admissionsUserAs(Role::Registrar));

    $application = admissionsCompleteDraft(admissionsFixture());

    app(SetApplicantPhoto::class)->handle(
        $application,
        UploadedFile::fake()->image('applicant.jpg', 400, 500),
    );

    $response = $this->get(route('admissions.photo', ['application' => $application->getKey()]));

    $response->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});
