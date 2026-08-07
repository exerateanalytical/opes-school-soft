<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Permission as PermissionEnum;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Students\Actions\CreateStudent;
use App\Modules\Students\Actions\LogStudentActivity;
use App\Modules\Students\Actions\UpdateStudent;
use App\Modules\Students\Domain\Gender;
use App\Modules\Students\Domain\StudentActivityEvent;
use App\Modules\Students\Domain\StudentStatus;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Models\StudentActivityLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

if (! function_exists('studentsUserAs')) {
    /**
     * Local helper, deliberately NOT the Identity or Academics suite's
     * equivalents: Pest test files share one global function namespace, so
     * re-declaring a name another file already owns collides at collection
     * time. Guarded with function_exists because MatriculeTest declares the
     * same helper and either file may be loaded first.
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

it('creates a student with a temporary matricule and an admission number from the sequences', function () {
    actingAs(studentsUserAs());

    $student = app(CreateStudent::class)->handle(
        firstName: 'Bela',
        lastName: 'Merceline',
        dateOfBirth: '2012-04-18',
        gender: Gender::Female,
        schoolSectionId: 1,
        firstAdmissionDate: '2026-09-05',
    );

    // TMP is the fallback template. The prefix is load bearing: 6.4 permits
    // exactly one replacement, and a temporary number that does not look
    // temporary gets printed on a certificate.
    expect($student->matricule)->toBe('TMP/2026/00001');
    expect($student->matricule_is_official)->toBeFalse();
    expect($student->admission_no)->toBe('ADM/2026/00001');
    expect($student->status)->toBe(StudentStatus::Prospective);

    // Both counters advanced by exactly one, and they are separate series.
    expect(DB::table('sequences')->where('series', 'matricule.2026.SEC1')->value('next_value'))->toEqual(2);
    expect(DB::table('sequences')->where('series', 'admission_no.2026')->value('next_value'))->toEqual(2);
});

it('never reissues a matricule to a second student in the same section and year', function () {
    actingAs(studentsUserAs());

    $create = app(CreateStudent::class);

    $one = $create->handle('A', 'One', '2012-01-01', Gender::Male, schoolSectionId: 1, firstAdmissionDate: '2026-09-05');
    $two = $create->handle('B', 'Two', '2012-01-01', Gender::Male, schoolSectionId: 1, firstAdmissionDate: '2026-09-05');

    expect($one->matricule)->toBe('TMP/2026/00001');
    expect($two->matricule)->toBe('TMP/2026/00002');
});

it('renders the section matricule format when the caller supplies one', function () {
    actingAs(studentsUserAs());

    $student = app(CreateStudent::class)->handle(
        firstName: 'Nkeng',
        lastName: 'Ayuk',
        dateOfBirth: '2011-02-02',
        gender: Gender::Male,
        schoolSectionId: 3,
        matriculeFormat: 'HA{year}-{seq:5}',
        firstAdmissionDate: '2026-09-05',
    );

    expect($student->matricule)->toBe('HA2026-00001');
    // Still temporary. The template only decides what the number LOOKS like;
    // the official government number does not exist yet at admission.
    expect($student->matricule_is_official)->toBeFalse();
});

it('enforces matricule uniqueness globally, not per section or per year', function () {
    // 00-core 12. The UNIQUE index is the guarantee; the sequence is only what
    // stops the index from being hit in normal operation.
    Student::factory()->create(['matricule' => 'HA2026-00045']);

    Student::factory()->create(['matricule' => 'HA2026-00045']);
})->throws(UniqueConstraintViolationException::class);

it('treats matricule case as significant', function () {
    // The column is utf8mb4_0900_as_cs, not the connection default ai_ci.
    // Under ai_ci these two would collide and the second insert would be
    // rejected as a duplicate that a human reading the numbers can see is not
    // one.
    Student::factory()->create(['matricule' => 'HA2026-00045a']);
    Student::factory()->create(['matricule' => 'HA2026-00045A']);

    expect(Student::query()->count())->toBe(2);
});

it('writes an activity-log entry and an audit entry when a student is created', function () {
    $user = studentsUserAs();
    actingAs($user);

    $student = app(CreateStudent::class)->handle(
        'Bela', 'Merceline', '2012-04-18', Gender::Female, firstAdmissionDate: '2026-09-05',
    );

    $entry = StudentActivityLog::query()->forStudent((int) $student->getKey())->firstOrFail();

    expect($entry->event)->toBe(StudentActivityEvent::Admitted);
    expect($entry->actor_id)->toBe((int) $user->getKey());
    expect($entry->actor_name_at_time)->toBe($user->name);

    expect(AuditLog::query()->where('module', 'Students')->where('action', 'created')->count())->toBe(1);
});

it('keeps the date of birth and encrypted fields out of the audit payload', function () {
    // 00-core 9.5: audit payloads for encrypted fields are themselves
    // encrypted and excluded from exports and logs. The cheapest way to honour
    // that is not to put them in the payload at all.
    actingAs(studentsUserAs());

    app(CreateStudent::class)->handle(
        'Bela', 'Merceline', '2012-04-18', Gender::Female,
        genotype: 'AS', bloodGroup: 'O+', religion: 'Catholic',
    );

    $entry = AuditLog::query()->where('module', 'Students')->firstOrFail();
    $payload = json_encode($entry->after);

    expect($payload)->not->toContain('2012-04-18');
    expect($payload)->not->toContain('AS');
    expect($payload)->not->toContain('Catholic');
});

it('stores religion, blood group and genotype as ciphertext', function () {
    actingAs(studentsUserAs());

    $student = app(CreateStudent::class)->handle(
        'Bela', 'Merceline', '2012-04-18', Gender::Female,
        genotype: 'AS', bloodGroup: 'O+', religion: 'Catholic',
    );

    $raw = DB::table('students')->where('id', $student->getKey())->first();

    expect($raw)->not->toBeNull();
    // In Cameroon genotype is sickle-cell status - health data about a child,
    // which v1 kept in a plain column.
    expect($raw?->genotype)->not->toBe('AS');
    expect($student->fresh()?->genotype)->toBe('AS');
    expect($student->fresh()?->religion)->toBe('Catholic');
});

it('refuses a second student registered on the same national id number', function () {
    actingAs(studentsUserAs());

    $create = app(CreateStudent::class);
    $create->handle('A', 'One', '2005-01-01', Gender::Male, nationalIdNumber: '  ab-123456 ');

    // Normalised before hashing, so a whitespace-and-case variant is still the
    // same identity. A blind index that misses the duplicate it exists to
    // catch is worse than none, because it looks like it worked.
    expect(fn () => $create->handle('B', 'Two', '2004-01-01', Gender::Male, nationalIdNumber: 'AB-123456'))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

it('rejects student creation without students.manage', function () {
    actingAs(studentsUserAs(permissions: []));

    app(CreateStudent::class)->handle('A', 'One', '2012-01-01', Gender::Male);
})->throws(AuthorizationException::class);

it('updates biographical fields and records the diff', function () {
    actingAs(studentsUserAs());
    $student = Student::factory()->create(['city' => 'Buea', 'phone' => null]);

    $updated = app(UpdateStudent::class)->handle((int) $student->getKey(), [
        'city' => 'Douala',
        'phone' => '+237600000000',
    ]);

    expect($updated->city)->toBe('Douala');
    expect($updated->phone)->toBe('+237600000000');

    $entry = AuditLog::query()->where('action', 'updated')->firstOrFail();
    expect($entry->before)->toBe(['city' => 'Buea', 'phone' => null]);
});

it('cannot change the matricule, the admission number or the status through UpdateStudent', function () {
    // The three fields that make this Action safe to grant broadly. 6.4 owns
    // the matricule, Admissions owns the admission number, and 3.2 makes the
    // status a derived cache - hand-editing it is what let v1's two lifecycles
    // diverge inside a term.
    actingAs(studentsUserAs());

    $student = Student::factory()->create([
        'matricule' => 'TMP/2026/00007',
        'admission_no' => 'ADM/2026/00007',
        'status' => StudentStatus::Prospective,
    ]);

    $updated = app(UpdateStudent::class)->handle((int) $student->getKey(), [
        'matricule' => 'HACKED',
        'admission_no' => 'HACKED',
        'status' => StudentStatus::Graduated->value,
        'first_name' => 'Renamed',
    ]);

    expect($updated->matricule)->toBe('TMP/2026/00007');
    expect($updated->admission_no)->toBe('ADM/2026/00007');
    expect($updated->status)->toBe(StudentStatus::Prospective);
    expect($updated->first_name)->toBe('Renamed');
});

it('rejects a student update without students.manage', function () {
    actingAs(studentsUserAs(permissions: []));
    $student = Student::factory()->create();

    app(UpdateStudent::class)->handle((int) $student->getKey(), ['city' => 'Douala']);
})->throws(AuthorizationException::class);

it('refuses a direct write to the derived status', function () {
    $student = Student::factory()->create();

    $student->status = StudentStatus::Graduated;
    $student->save();
})->throws(RuntimeException::class, 'derived from enrollment history');

it('accepts the derived status through the guarded setter', function () {
    // The path RecomputeStudentStatus and every enrollment Action take. It
    // exists instead of a from -> to transition method because 3.2 makes the
    // status a recomputed cache, and a transition table here would fight the
    // derivation rather than describe it.
    $student = Student::factory()->create();

    expect($student->applyDerivedStatus(StudentStatus::Active))->toBeTrue();
    expect($student->fresh()?->status)->toBe(StudentStatus::Active);

    // The guard closes again afterwards - it is not a latch.
    $student->status = StudentStatus::Withdrawn;
    expect(fn () => $student->save())->toThrow(RuntimeException::class);
});

it('writes an activity entry any module can call for', function () {
    // 8.3: Fees, Assessment and Welfare all write here, and none of them may
    // touch this module's Models (00-core 6.2 rule 2), so the Action is the
    // published entry point.
    $student = Student::factory()->create();

    $entry = app(LogStudentActivity::class)->handle(
        studentId: (int) $student->getKey(),
        event: StudentActivityEvent::InvoiceIssued,
        summary: 'Invoice INV/2026/0031 issued for Term 1.',
        relatedType: 'Invoice',
        relatedId: 31,
    );

    expect($entry->event)->toBe(StudentActivityEvent::InvoiceIssued);
    expect($entry->related_id)->toBe(31);
    // Unattended callers (queued jobs, scheduled tasks) are attributed to the
    // system actor rather than to nobody.
    expect($entry->actor_name_at_time)->toBe('system');
    expect($entry->actor_id)->toBeNull();
});

it('keeps the activity log append-only', function () {
    $student = Student::factory()->create();

    $entry = app(LogStudentActivity::class)->handle(
        studentId: (int) $student->getKey(),
        event: StudentActivityEvent::Enrolled,
        summary: 'Enrolled in Form 2A.',
    );

    expect(fn () => $entry->update(['summary' => 'rewritten']))->toThrow(RuntimeException::class);
    expect(fn () => $entry->delete())->toThrow(RuntimeException::class);
});
