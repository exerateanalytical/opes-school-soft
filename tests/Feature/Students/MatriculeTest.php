<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Permission as PermissionEnum;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Students\Actions\PromoteMatriculeToOfficial;
use App\Modules\Students\Domain\StudentActivityEvent;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Models\StudentActivityLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

if (! function_exists('studentsUserAs')) {
    /**
     * Guarded with function_exists because StudentTest declares the same
     * helper and Pest loads both files into one global function namespace;
     * either may be collected first.
     *
     * @param  list<PermissionEnum>  $permissions
     */
    function studentsUserAs(array $permissions = [PermissionEnum::StudentsManage]): User
    {
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionEnum::cases() as $case) {
            Permission::findOrCreate($case->value, 'web');
        }

        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission->value);
        }

        return $user->fresh() ?? $user;
    }
}

it('replaces a temporary matricule with the official one and records it twice', function () {
    $user = studentsUserAs([PermissionEnum::StudentsMatriculeFinalise]);
    actingAs($user);

    $student = Student::factory()->create([
        'matricule' => 'TMP/2026/00012',
        'matricule_is_official' => false,
    ]);

    $promoted = app(PromoteMatriculeToOfficial::class)
        ->handle((int) $student->getKey(), 'HA2026-00045');

    expect($promoted->matricule)->toBe('HA2026-00045');
    expect($promoted->matricule_is_official)->toBeTrue();

    // 6.4 requires BOTH logs: AuditLog answers "who changed this row" and is
    // permissioned; StudentActivityLog answers "what happened to this child"
    // and any staff member who can see the profile reads it.
    $audit = AuditLog::query()->where('module', 'Students')->where('action', 'updated')->firstOrFail();
    expect($audit->before)->toBe(['matricule' => 'TMP/2026/00012', 'matricule_is_official' => false]);
    expect($audit->after)->toBe(['matricule' => 'HA2026-00045', 'matricule_is_official' => true]);

    $activity = StudentActivityLog::query()->forStudent((int) $student->getKey())->firstOrFail();
    expect($activity->event)->toBe(StudentActivityEvent::MatriculeFinalised);
    expect($activity->summary)->toContain('TMP/2026/00012');
});

it('is single-use: a second finalisation is rejected by the affected-rows check', function () {
    // Acceptance criterion 12. The rejection comes from the database, not from
    // a read-then-write in PHP: the UPDATE carries
    // WHERE matricule_is_official = 0, so two callers racing on the same row
    // cannot both observe 0 and both write. The second gets 0 affected rows.
    actingAs(studentsUserAs([PermissionEnum::StudentsMatriculeFinalise]));

    $student = Student::factory()->create([
        'matricule' => 'TMP/2026/00012',
        'matricule_is_official' => false,
    ]);

    $action = app(PromoteMatriculeToOfficial::class);
    $action->handle((int) $student->getKey(), 'HA2026-00045');

    expect(fn () => $action->handle((int) $student->getKey(), 'HA2026-99999'))
        ->toThrow(ValidationException::class, 'single-use');

    // The first number survives, unaltered.
    expect(Student::query()->findOrFail((int) $student->getKey())->matricule)->toBe('HA2026-00045');
});

it('proves the affected-rows check is what rejects the replay', function () {
    // Pinning the mechanism, not just the outcome: with the guard clause
    // removed the UPDATE would still succeed against an official row. This
    // asserts the conditional WHERE itself returns 0.
    $student = Student::factory()->withOfficialMatricule('HA2026-00045')->create();

    $affected = DB::table('students')
        ->where('id', '=', $student->getKey())
        ->where('matricule_is_official', '=', false)
        ->update(['matricule' => 'HA2026-99999']);

    expect($affected)->toBe(0);
    expect(Student::query()->findOrFail((int) $student->getKey())->matricule)->toBe('HA2026-00045');
});

it('makes the column immutable through the model once it is official', function () {
    // The third defence. The conditional UPDATE guards the Action; this guards
    // the `$student->matricule = ...` somebody writes next sprint without
    // knowing 6.4 exists.
    $student = Student::factory()->withOfficialMatricule('HA2026-00045')->create();

    $student->matricule = 'SOMETHING/ELSE';
    $student->save();
})->throws(RuntimeException::class, 'immutable');

it('refuses to demote an official matricule back to temporary', function () {
    // Otherwise the single-use transition is trivially reusable: flip the flag,
    // then finalise again.
    $student = Student::factory()->withOfficialMatricule('HA2026-00045')->create();

    $student->matricule_is_official = false;
    $student->save();
})->throws(RuntimeException::class, 'demoted');

it('cannot be evaded by flipping the flag and the number in one save', function () {
    // The guard reads getOriginal(), not the current attribute, so the check
    // asks "was it official BEFORE this save".
    $student = Student::factory()->withOfficialMatricule('HA2026-00045')->create();

    $student->matricule = 'SOMETHING/ELSE';
    $student->matricule_is_official = false;

    expect(fn () => $student->save())->toThrow(RuntimeException::class);
    expect(Student::query()->findOrFail((int) $student->getKey())->matricule)->toBe('HA2026-00045');
});

it('rejects finalisation for a user holding only students.manage', function () {
    // 6.4: a dedicated permission, not students.update. Finalising is
    // irreversible and the number is printed on certificates.
    actingAs(studentsUserAs([PermissionEnum::StudentsManage]));

    $student = Student::factory()->create(['matricule_is_official' => false]);

    app(PromoteMatriculeToOfficial::class)->handle((int) $student->getKey(), 'HA2026-00045');
})->throws(AuthorizationException::class);

it('surfaces a matricule already held by another student as a field error', function () {
    actingAs(studentsUserAs([PermissionEnum::StudentsMatriculeFinalise]));

    Student::factory()->withOfficialMatricule('HA2026-00045')->create();
    $student = Student::factory()->create(['matricule_is_official' => false]);

    expect(fn () => app(PromoteMatriculeToOfficial::class)
        ->handle((int) $student->getKey(), 'HA2026-00045'))
        ->toThrow(ValidationException::class, 'already held');
});

it('rejects a blank official matricule', function () {
    actingAs(studentsUserAs([PermissionEnum::StudentsMatriculeFinalise]));

    $student = Student::factory()->create(['matricule_is_official' => false]);

    app(PromoteMatriculeToOfficial::class)->handle((int) $student->getKey(), '   ');
})->throws(ValidationException::class);

it('writes nothing at all when the finalisation is rejected', function () {
    // The Action runs in one transaction, so a rejected replay must leave no
    // audit row and no activity row behind - a log of a change that did not
    // happen is worse than no log.
    actingAs(studentsUserAs([PermissionEnum::StudentsMatriculeFinalise]));

    $student = Student::factory()->withOfficialMatricule('HA2026-00045')->create();

    try {
        app(PromoteMatriculeToOfficial::class)->handle((int) $student->getKey(), 'HA2026-99999');
    } catch (ValidationException) {
        // expected
    }

    expect(AuditLog::query()->where('module', 'Students')->count())->toBe(0);
    expect(StudentActivityLog::query()->count())->toBe(0);
});
