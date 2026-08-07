<?php

declare(strict_types=1);

use App\Modules\Guardians\Actions\CreateGuardian;
use App\Modules\Guardians\Actions\FindDuplicateGuardians;
use App\Modules\Guardians\Actions\LinkGuardian;
use App\Modules\Guardians\Actions\UnlinkGuardian;
use App\Modules\Guardians\Domain\GuardianRelationship;
use App\Modules\Guardians\Domain\PhoneNumber;
use App\Modules\Guardians\Models\Guardian;
use App\Modules\Guardians\Models\StudentGuardian;
use App\Modules\Identity\Domain\Permission as OpesPermission;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Support\Clock\BusinessDate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

if (! function_exists('guardiansUserAs')) {
    /**
     * Local helper, deliberately not the Identity or Academics suite's own:
     * Pest test files share one global function namespace, so re-declaring
     * `userAs` here would collide. Tests are not bound by module boundaries -
     * only app/ code is - so creating the User directly is fine.
     */
    function guardiansUserAs(bool $withPermission = true): User
    {
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate(OpesPermission::GuardiansManage->value, 'web');

        $user = User::factory()->create();

        if ($withPermission) {
            $user->givePermissionTo(OpesPermission::GuardiansManage->value);
        }

        return $user->fresh() ?? $user;
    }
}

if (! function_exists('guardiansStudentId')) {
    /**
     * A prerequisite student row, inserted with the query builder rather than
     * the Students module's factory. That module is another agent's in this
     * phase and importing its factory would couple this suite to code that may
     * still be moving; the FK only needs an id to point at.
     */
    function guardiansStudentId(string $suffix = 'A'): int
    {
        return (int) DB::table('students')->insertGetId([
            'matricule' => 'HA2026-'.$suffix,
            'admission_no' => 'ADM/2026/'.$suffix,
            'first_name' => 'Test',
            'last_name' => 'Student',
            'date_of_birth' => '2012-04-01',
            'gender' => 'male',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function guardiansAttributes(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Merceline',
        'last_name' => 'Bela',
        'date_of_birth' => '1985-06-12',
        'gender' => 'female',
        'phone' => '677 12 34 56',
    ], $overrides);
}

it('creates a guardian, allocates a number and normalises the phone to E.164', function () {
    $user = guardiansUserAs();
    actingAs($user);

    $result = app(CreateGuardian::class)->handle(guardiansAttributes());

    expect($result['guardian']->exists)->toBeTrue()
        ->and($result['guardian']->guardian_no)->toBe('GRD-000001')
        // 7.7 tier 2 is an EXACT match on this column, so the four ways a
        // Cameroonian handset is written on paper must collapse to one string
        // on the way in or the tier never fires.
        ->and($result['guardian']->phone)->toBe('+237677123456')
        ->and($result['duplicates'])->toBe([]);

    expect(AuditLog::query()->where('module', 'Guardians')->where('action', 'created')->count())->toBe(1);
});

it('normalises every spelling of one Cameroonian number to the same E.164 string', function () {
    // The whole of tier-2 duplicate detection rests on this.
    expect(PhoneNumber::normalise('677123456'))->toBe('+237677123456')
        ->and(PhoneNumber::normalise('0677123456'))->toBe('+237677123456')
        ->and(PhoneNumber::normalise('+237 677 12 34 56'))->toBe('+237677123456')
        ->and(PhoneNumber::normalise('00237-677-123-456'))->toBe('+237677123456')
        ->and(PhoneNumber::normalise('237677123456'))->toBe('+237677123456')
        ->and(PhoneNumber::normalise('   '))->toBeNull()
        ->and(PhoneNumber::normalise(null))->toBeNull();
});

it('refuses to create a guardian without the guardians.manage permission', function () {
    actingAs(guardiansUserAs(withPermission: false));

    expect(fn () => app(CreateGuardian::class)->handle(guardiansAttributes()))
        ->toThrow(AuthorizationException::class);

    expect(Guardian::query()->count())->toBe(0);
});

it('surfaces a phone-tier duplicate WITHOUT blocking the creation', function () {
    // 7.7: a match presents "Link to existing guardian Bela Merceline?" and
    // never silently merges - but a shared household phone is not evidence
    // enough to refuse a second real person.
    $user = guardiansUserAs();
    actingAs($user);

    $create = app(CreateGuardian::class);
    $first = $create->handle(guardiansAttributes());

    $second = $create->handle(guardiansAttributes([
        'first_name' => 'Paul',
        'last_name' => 'Bela',
        'date_of_birth' => '1980-01-01',
    ]));

    expect($second['guardian']->exists)->toBeTrue()
        ->and($second['duplicate_tier'])->toBe(FindDuplicateGuardians::TIER_PHONE)
        ->and($second['duplicates'])->toHaveCount(1)
        ->and((int) $second['duplicates'][0]->getKey())->toBe((int) $first['guardian']->getKey());

    expect(Guardian::query()->count())->toBe(2);
});

it('blocks a second guardian bearing the same identity document, naming the existing record', function () {
    // The one tier that does block, because guardians.id_number_blind_index is
    // UNIQUE (7.1) and this Action does not get to overrule the schema.
    $user = guardiansUserAs();
    actingAs($user);

    $create = app(CreateGuardian::class);
    $existing = $create->handle(guardiansAttributes(['id_number' => 'CM-1234567']));

    expect(fn () => $create->handle(guardiansAttributes([
        'first_name' => 'Imposter',
        'phone' => '699887766',
        'id_number' => 'cm 1234567',   // same card, transcribed by another clerk
    ])))->toThrow(ValidationException::class, $existing['guardian']->guardian_no);

    expect(Guardian::query()->count())->toBe(1);
});

it('reports no duplicate for an unrelated guardian', function () {
    $user = guardiansUserAs();
    actingAs($user);

    app(CreateGuardian::class)->handle(guardiansAttributes());

    $result = app(FindDuplicateGuardians::class)->handle(
        idNumber: 'CM-9999999',
        phone: '699000111',
        lastName: 'Nkomo',
        firstName: 'Jean',
        dateOfBirth: '1979-02-02',
    );

    expect($result['tier'])->toBeNull()->and($result['candidates'])->toBe([]);
});

it('matches on name plus date of birth, but never on the name alone', function () {
    $user = guardiansUserAs();
    actingAs($user);

    $existing = Guardian::factory()->create([
        'first_name' => 'Merceline',
        'last_name' => 'Bela',
        'date_of_birth' => '1985-06-12',
    ]);

    $find = app(FindDuplicateGuardians::class);

    $hit = $find->handle(lastName: 'Bela', firstName: 'Merceline', dateOfBirth: '1985-06-12');
    expect($hit['tier'])->toBe(FindDuplicateGuardians::TIER_NAME_AND_DOB)
        ->and((int) $hit['candidates'][0]->getKey())->toBe((int) $existing->getKey());

    // Same name, different person. 7.7 calls a silent merge here a data
    // protection incident, and in Cameroon a shared surname is ordinary.
    $miss = $find->handle(lastName: 'Bela', firstName: 'Merceline', dateOfBirth: '1961-11-30');
    expect($miss['tier'])->toBeNull()->and($miss['candidates'])->toBe([]);

    // And with no date of birth to corroborate, tier 3 must not fire at all.
    $noDob = $find->handle(lastName: 'Bela', firstName: 'Merceline');
    expect($noDob['tier'])->toBeNull();
});

it('links a guardian to a student with an initial flag set', function () {
    $user = guardiansUserAs();
    actingAs($user);

    $studentId = guardiansStudentId();
    $guardian = Guardian::factory()->create();

    $link = app(LinkGuardian::class)->handle(
        studentId: $studentId,
        guardianId: (int) $guardian->getKey(),
        relationship: GuardianRelationship::Mother,
        isPrimary: true,
        hasCustody: true,
        receivesReports: true,
    );

    expect($link->exists)->toBeTrue()
        ->and($link->is_primary)->toBeTrue()
        ->and($link->valid_from->toDateString())->toBe(BusinessDate::today())
        ->and($link->valid_to)->toBeNull()
        ->and($link->created_by)->toBe((int) $user->getKey());

    expect(AuditLog::query()
        ->where('auditable_type', StudentGuardian::class)
        ->where('action', 'created')
        ->count())->toBe(1);
});

it('rejects a primary link that does not also hold custody', function () {
    // 7.2, stated as an implication and enforced as one - the primary guardian
    // is the default addressee on printed documents, and quietly granting
    // custody to make that work is the conflation the matrix exists to prevent.
    actingAs(guardiansUserAs());

    $studentId = guardiansStudentId();
    $guardian = Guardian::factory()->create();

    expect(fn () => app(LinkGuardian::class)->handle(
        studentId: $studentId,
        guardianId: (int) $guardian->getKey(),
        relationship: GuardianRelationship::Father,
        isPrimary: true,
        hasCustody: false,
    ))->toThrow(ValidationException::class);

    expect(StudentGuardian::query()->count())->toBe(0);
});

it('permits at most one open primary guardian per student', function () {
    actingAs(guardiansUserAs());

    $studentId = guardiansStudentId();
    $mother = Guardian::factory()->create();
    $father = Guardian::factory()->create();
    $link = app(LinkGuardian::class);

    $link->handle(
        studentId: $studentId,
        guardianId: (int) $mother->getKey(),
        relationship: GuardianRelationship::Mother,
        isPrimary: true,
        hasCustody: true,
    );

    expect(fn () => $link->handle(
        studentId: $studentId,
        guardianId: (int) $father->getKey(),
        relationship: GuardianRelationship::Father,
        isPrimary: true,
        hasCustody: true,
    ))->toThrow(ValidationException::class);

    expect(StudentGuardian::query()->where('is_primary', true)->count())->toBe(1);
});

it('allows a second student to have their own primary guardian', function () {
    // The generated column keys on student_id, so uq_primary_guardian must not
    // make the whole school share one primary guardian.
    actingAs(guardiansUserAs());

    $link = app(LinkGuardian::class);
    $guardian = Guardian::factory()->create();

    foreach (['A', 'B'] as $suffix) {
        $link->handle(
            studentId: guardiansStudentId($suffix),
            guardianId: (int) Guardian::factory()->create()->getKey(),
            relationship: GuardianRelationship::Mother,
            isPrimary: true,
            hasCustody: true,
        );
    }

    expect(StudentGuardian::query()->where('is_primary', true)->count())->toBe(2)
        ->and($guardian->exists)->toBeTrue();
});

it('requires free text when the relationship is recorded as other', function () {
    actingAs(guardiansUserAs());

    expect(fn () => app(LinkGuardian::class)->handle(
        studentId: guardiansStudentId(),
        guardianId: (int) Guardian::factory()->create()->getKey(),
        relationship: GuardianRelationship::Other,
    ))->toThrow(ValidationException::class);
});

it('refuses a second open link between the same student and guardian', function () {
    actingAs(guardiansUserAs());

    $studentId = guardiansStudentId();
    $guardian = Guardian::factory()->create();
    $link = app(LinkGuardian::class);

    $link->handle($studentId, (int) $guardian->getKey(), GuardianRelationship::Mother);

    expect(fn () => $link->handle($studentId, (int) $guardian->getKey(), GuardianRelationship::Mother))
        ->toThrow(ValidationException::class);

    expect(StudentGuardian::query()->count())->toBe(1);
});

it('end-dates a link on unlink instead of deleting it, and demands a reason', function () {
    // 7.2: "Unlink is valid_to = business_date() + revocation_reason. There is
    // no hard delete." History has to survive the custody dispute that caused
    // the unlink in the first place.
    $user = guardiansUserAs();
    actingAs($user);

    $link = app(LinkGuardian::class)->handle(
        studentId: guardiansStudentId(),
        guardianId: (int) Guardian::factory()->create()->getKey(),
        relationship: GuardianRelationship::Mother,
        hasCustody: true,
    );

    $unlink = app(UnlinkGuardian::class);

    expect(fn () => $unlink->handle($link, '   '))->toThrow(ValidationException::class);

    $revoked = $unlink->handle($link, 'Custody transferred by court order.');

    expect(StudentGuardian::query()->count())->toBe(1)
        ->and($revoked->valid_to?->toDateString())->toBe(BusinessDate::today())
        ->and($revoked->revocation_reason)->toBe('Custody transferred by court order.')
        // Still valid TODAY under the 7.3 predicate; it stops tomorrow.
        ->and($revoked->isValid())->toBeTrue()
        ->and($revoked->isValid(Carbon::parse(BusinessDate::today())->addDay()->toDateString()))->toBeFalse();

    expect(fn () => $unlink->handle($revoked, 'again'))->toThrow(ValidationException::class);
});

it('refuses to unlink without the guardians.manage permission', function () {
    actingAs(guardiansUserAs());

    $link = app(LinkGuardian::class)->handle(
        studentId: guardiansStudentId(),
        guardianId: (int) Guardian::factory()->create()->getKey(),
        relationship: GuardianRelationship::Mother,
    );

    actingAs(guardiansUserAs(withPermission: false));

    expect(fn () => app(UnlinkGuardian::class)->handle($link, 'no rights'))
        ->toThrow(AuthorizationException::class);

    expect($link->refresh()->valid_to)->toBeNull();
});
