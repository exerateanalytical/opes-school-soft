<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Students\Actions\Import\CommitImportBatch;
use App\Modules\Students\Actions\Import\StageImportBatch;
use App\Modules\Students\Actions\Import\ValidateImportBatch;
use App\Modules\Students\Domain\ImportKind;
use App\Modules\Students\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

/*
 * 00-core §15 Phase 2 - the data import suite.
 *
 * The property under test throughout is that stage and validate touch NO
 * domain table. That separation is what lets a school see which of its 1 200
 * rows would fail before any student exists.
 */

function importActor(): User
{
    (new \Database\Seeders\RolePermissionSeeder())->run();

    $user = User::factory()->create();
    $user->assignRole(Role::SuperAdmin->value);
    Auth::setUser($user);

    return $user;
}

const GOOD_AND_BAD_CSV = "first_name,last_name,date_of_birth,gender\n"
    ."Amina,Nkemta,2011-04-02,female\n"
    .",Fotso,not-a-date,martian\n";

it('stages every data row and creates no students', function (): void {
    importActor();

    $csv = "first_name,last_name,date_of_birth,gender\n"
        ."Amina,Nkemta,2011-04-02,female\n"
        ."Brice,Fotso,2010-09-15,male\n";

    $before = Student::count();

    $batch = app(StageImportBatch::class)->handle(ImportKind::Students, 'students.csv', $csv);

    expect($batch->row_count)->toBe(2)
        ->and($batch->rows()->count())->toBe(2)
        ->and(Student::count())->toBe($before);
});

it('refuses a file missing a required column, naming it', function (): void {
    importActor();

    $csv = "first_name,last_name\nAmina,Nkemta\n";

    expect(fn () => app(StageImportBatch::class)->handle(ImportKind::Students, 'bad.csv', $csv))
        ->toThrow(DomainException::class);
});

it('marks bad rows invalid with per-field errors and leaves good rows valid', function (): void {
    importActor();

    $batch = app(StageImportBatch::class)->handle(ImportKind::Students, 'students.csv', GOOD_AND_BAD_CSV);
    $batch = app(ValidateImportBatch::class)->handle((int) $batch->getKey());

    expect($batch->valid_count)->toBe(1)
        ->and($batch->invalid_count)->toBe(1);

    $bad = $batch->rows()->where('row_no', 2)->firstOrFail();

    expect($bad->status->value)->toBe('invalid')
        ->and(array_keys($bad->errors))->toContain('first_name')
        ->and(array_keys($bad->errors))->toContain('date_of_birth')
        ->and(array_keys($bad->errors))->toContain('gender');
});

it('validates without creating any student', function (): void {
    importActor();

    $before = Student::count();

    $batch = app(StageImportBatch::class)->handle(ImportKind::Students, 'students.csv', GOOD_AND_BAD_CSV);
    app(ValidateImportBatch::class)->handle((int) $batch->getKey());

    expect(Student::count())->toBe($before);
});

it('creates only the valid rows and is safe to run twice', function (): void {
    importActor();

    $batch = app(StageImportBatch::class)->handle(ImportKind::Students, 'students.csv', GOOD_AND_BAD_CSV);
    app(ValidateImportBatch::class)->handle((int) $batch->getKey());

    $before = Student::count();

    $batch = app(CommitImportBatch::class)->handle((int) $batch->getKey());

    expect(Student::count())->toBe($before + 1)
        ->and($batch->imported_count)->toBe(1);

    // Re-running must not create a second Amina: only rows still marked
    // `valid` are processed, and the first run marked hers `imported`.
    $batch = app(CommitImportBatch::class)->handle((int) $batch->getKey());

    expect(Student::count())->toBe($before + 1)
        ->and($batch->imported_count)->toBe(1);
});

it('records which student each imported row created', function (): void {
    importActor();

    $batch = app(StageImportBatch::class)->handle(ImportKind::Students, 'students.csv', GOOD_AND_BAD_CSV);
    app(ValidateImportBatch::class)->handle((int) $batch->getKey());
    $batch = app(CommitImportBatch::class)->handle((int) $batch->getKey());

    $imported = $batch->rows()->where('status', 'imported')->firstOrFail();

    expect($imported->imported_record_type)->toBe(Student::class)
        ->and($imported->imported_record_id)->toBeGreaterThan(0)
        ->and(Student::find($imported->imported_record_id))->not->toBeNull();
});
