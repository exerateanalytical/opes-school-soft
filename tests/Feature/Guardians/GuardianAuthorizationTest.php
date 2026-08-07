<?php

declare(strict_types=1);

use App\Modules\Guardians\Actions\SetGuardianAuthorization;
use App\Modules\Guardians\Domain\GuardianCapability;
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
     * Guarded because GuardianTest.php declares the same helper and Pest test
     * files share one global function namespace; whichever file is included
     * first wins, and both bodies are identical.
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

/** The five flag columns the 7.5 matrix reads. */
const GUARDIAN_MATRIX_FLAGS = [
    'has_custody',
    'receives_reports',
    'receives_invoices',
    'is_fee_payer',
    'is_emergency_contact',
];

/**
 * @param  list<string>  $on
 * @return array<string, bool>
 */
function guardianFlagSet(array $on): array
{
    $flags = [];

    foreach (GUARDIAN_MATRIX_FLAGS as $flag) {
        $flags[$flag] = in_array($flag, $on, true);
    }

    return $flags;
}

/**
 * @param  array<string, bool>  $flags
 * @param  array<string, mixed>  $overrides
 */
function guardianLink(array $flags = [], array $overrides = [], bool $guardianActive = true): StudentGuardian
{
    static $seq = 0;
    $seq++;

    $guardian = $guardianActive
        ? Guardian::factory()->create()
        : Guardian::factory()->inactive()->create();

    /** @var StudentGuardian $link */
    $link = StudentGuardian::factory()->create(array_merge([
        'student_id' => guardiansStudentId('S'.$seq),
        'guardian_id' => $guardian->getKey(),
    ], $flags, $overrides));

    return $link->load('guardian');
}

// ---------------------------------------------------------------------------
// 7.3 - the validity predicate, one case per clause of
//
//     valid_from <= business_date()
//       AND (valid_to IS NULL OR valid_to >= business_date())
//
// Asserted TWICE against the same fixture: once through the query scope and
// once through the in-memory method. The rule is spelled in two places, so a
// divergence between them has to be able to fail something.
// ---------------------------------------------------------------------------

dataset('validity truth table', function () {
    $today = BusinessDate::today();
    $yesterday = Carbon::parse($today)->subDay()->toDateString();
    $tomorrow = Carbon::parse($today)->addDay()->toDateString();
    $lastMonth = Carbon::parse($today)->subMonth()->toDateString();

    return [
        // clause 1: valid_from <= today
        'starts tomorrow, grants nothing' => [$tomorrow, null, false],
        'starts today' => [$today, null, true],
        'started last month' => [$lastMonth, null, true],
        // clause 2a: valid_to IS NULL
        'open-ended' => [$lastMonth, null, true],
        // clause 2b: valid_to >= today
        'ends yesterday' => [$lastMonth, $yesterday, false],
        'ends today, still valid today' => [$lastMonth, $today, true],
        'ends tomorrow' => [$lastMonth, $tomorrow, true],
        // both clauses failing at once must not accidentally cancel out
        'starts tomorrow and ended yesterday is impossible, so: future window' => [$tomorrow, $tomorrow, false],
    ];
});

it('resolves the 7.3 validity predicate identically in SQL and in PHP', function (
    string $validFrom,
    ?string $validTo,
    bool $expected,
) {
    $link = guardianLink([], ['valid_from' => $validFrom, 'valid_to' => $validTo]);

    expect($link->isValid())->toBe($expected);

    $matchedInSql = StudentGuardian::query()
        ->validOn(BusinessDate::today())
        ->whereKey($link->getKey())
        ->exists();

    expect($matchedInSql)->toBe($expected);
})->with('validity truth table');

it('evaluates the predicate against the date it is handed, not the wall clock', function () {
    // 7.3: business_date() is resolved once at transaction start and passed
    // down, so a request spanning midnight cannot see two different answers.
    $today = BusinessDate::today();
    $link = guardianLink([], [
        'valid_from' => Carbon::parse($today)->subMonth()->toDateString(),
        'valid_to' => $today,
    ]);

    expect($link->isValid($today))->toBeTrue()
        ->and($link->isValid(Carbon::parse($today)->addDay()->toDateString()))->toBeFalse()
        ->and($link->authorises(GuardianCapability::R01ViewChildIdentity->value, $today))->toBeTrue()
        ->and($link->authorises(
            GuardianCapability::R01ViewChildIdentity->value,
            Carbon::parse($today)->addDay()->toDateString()
        ))->toBeFalse();
});

// ---------------------------------------------------------------------------
// 7.5 - the matrix, row by row.
//
// Each row is asserted in BOTH directions, which is what 7.5 demands: the
// granting flags each grant on their own, and every flag OUTSIDE the grant
// rule, all switched on together, still denies. That second direction is the
// one that catches a widened cell.
// ---------------------------------------------------------------------------

dataset('authorization matrix', function () {
    // 'any'    -> any valid link
    // 'nobody' -> denied under every flag combination
    // list     -> OR over the named flags
    return [
        'row 1 child identity' => [GuardianCapability::R01ViewChildIdentity, 'any'],
        'row 2 profile detail' => [GuardianCapability::R02ViewChildProfileDetail, ['has_custody']],
        'row 3 emergency medical' => [GuardianCapability::R03ViewChildEmergencyMedical, ['has_custody', 'is_emergency_contact']],
        'row 4 full medical' => [GuardianCapability::R04ViewChildFullMedical, ['has_custody']],
        'row 5 report card view' => [GuardianCapability::R05ViewReportCard, ['receives_reports']],
        'row 6 report card download' => [GuardianCapability::R06DownloadReportCard, ['receives_reports']],
        'row 7 published marks' => [GuardianCapability::R07ViewPublishedMarks, ['receives_reports']],
        'row 8 unpublished marks' => [GuardianCapability::R08ViewUnpublishedMarks, 'nobody'],
        'row 9 rank and class mean' => [GuardianCapability::R09ViewRankAndClassMean, ['receives_reports']],
        'row 10 annual average' => [GuardianCapability::R10ViewAnnualAverageAndPromotion, ['receives_reports']],
        'row 11 attendance summary' => [GuardianCapability::R11ViewAttendanceSummary, ['has_custody', 'receives_reports']],
        'row 12 attendance detail' => [GuardianCapability::R12ViewAttendanceDetail, ['has_custody', 'receives_reports']],
        'row 13 invoices' => [GuardianCapability::R13ViewInvoices, ['receives_invoices', 'is_fee_payer']],
        'row 14 fee statement' => [GuardianCapability::R14ViewFeeStatement, ['receives_invoices', 'is_fee_payer']],
        'row 15 receipts' => [GuardianCapability::R15ViewReceipts, ['receives_invoices', 'is_fee_payer']],
        'row 16 own payments' => [GuardianCapability::R16ViewOwnPayments, 'any'],
        'row 17 other guardian payments' => [GuardianCapability::R17ViewOtherGuardianPayments, ['receives_invoices', 'is_fee_payer']],
        'row 18 initiate payment' => [GuardianCapability::R18InitiatePayment, ['is_fee_payer']],
        'row 19 discipline list' => [GuardianCapability::R19ViewDisciplineList, ['has_custody']],
        'row 20 discipline narrative' => [GuardianCapability::R20ViewDisciplineNarrative, ['has_custody']],
        'row 21 acknowledge sanction' => [GuardianCapability::R21AcknowledgeSanction, ['has_custody']],
        'row 22 school issued documents' => [GuardianCapability::R22ViewSchoolIssuedDocuments, ['has_custody', 'receives_reports']],
        'row 23 guardian supplied documents' => [GuardianCapability::R23ViewGuardianSuppliedDocuments, ['has_custody']],
        'row 24 upload document' => [GuardianCapability::R24UploadDocument, ['has_custody']],
        'row 25 delete document' => [GuardianCapability::R25DeleteDocument, 'nobody'],
        'row 26 timetable and announcements' => [GuardianCapability::R26ViewTimetableAndAnnouncements, 'any'],
        'row 27 request meeting' => [GuardianCapability::R27RequestGuardianMeeting, ['has_custody']],
        'row 28 edit child record' => [GuardianCapability::R28EditChildRecord, 'nobody'],
        'row 29 edit own contact details' => [GuardianCapability::R29EditOwnContactDetails, 'any'],
        'row 30 edit authorization flag' => [GuardianCapability::R30EditAuthorizationFlag, 'nobody'],
        'row 31 other guardians of the child' => [GuardianCapability::R31ViewOtherGuardiansOfChild, ['has_custody']],
        'row 32 unlinked child' => [GuardianCapability::R32AnythingForAnUnlinkedChild, 'nobody'],
    ];
});

it('grants and denies each matrix row exactly as 7.5 states it', function (
    GuardianCapability $capability,
    array|string $rule,
) {
    if ($rule === 'any') {
        // Granted with every flag off: the link's existence is the grant.
        expect(guardianLink(guardianFlagSet([]))->authorises($capability->value))->toBeTrue();

        return;
    }

    if ($rule === 'nobody') {
        // Denied with every flag on. There is no combination that opens it.
        expect(guardianLink(guardianFlagSet(GUARDIAN_MATRIX_FLAGS))->authorises($capability->value))->toBeFalse();

        return;
    }

    /** @var list<string> $rule */
    foreach ($rule as $granting) {
        expect(guardianLink(guardianFlagSet([$granting]))->authorises($capability->value))
            ->toBeTrue("{$granting} alone should grant {$capability->value}");
    }

    // The other direction: everything the rule does NOT name, switched on.
    $others = array_values(array_diff(GUARDIAN_MATRIX_FLAGS, $rule));

    expect(guardianLink(guardianFlagSet($others))->authorises($capability->value))
        ->toBeFalse("no flag outside the rule may grant {$capability->value}");
})->with('authorization matrix');

it('grants nothing at all on an expired link, not even row 1', function () {
    // 7.5: "A guardian whose link has expired retains no portal access,
    // including to periods when the link was valid." Row 16 is the sole
    // exception and lives on the payment query, not on link scope.
    $link = guardianLink(guardianFlagSet(GUARDIAN_MATRIX_FLAGS), [
        'valid_from' => Carbon::parse(BusinessDate::today())->subMonth()->toDateString(),
        'valid_to' => Carbon::parse(BusinessDate::today())->subDay()->toDateString(),
    ]);

    foreach (GuardianCapability::cases() as $capability) {
        expect($link->authorises($capability->value))->toBeFalse($capability->value);
    }
});

it('grants nothing on a link that has not taken effect yet', function () {
    $link = guardianLink(guardianFlagSet(GUARDIAN_MATRIX_FLAGS), [
        'valid_from' => Carbon::parse(BusinessDate::today())->addDay()->toDateString(),
    ]);

    expect($link->authorises(GuardianCapability::R01ViewChildIdentity->value))->toBeFalse();
});

it('grants nothing when the guardian record itself is inactive', function () {
    // The conjunctive gate 7.5 attaches to every row - deactivating a guardian
    // closes the portal across all their children without touching a link.
    $link = guardianLink(guardianFlagSet(GUARDIAN_MATRIX_FLAGS), [], guardianActive: false);

    expect($link->isValid())->toBeTrue()
        ->and($link->authorises(GuardianCapability::R01ViewChildIdentity->value))->toBeFalse()
        ->and($link->authorises(GuardianCapability::R13ViewInvoices->value))->toBeFalse();
});

it('denies an unrecognised capability string rather than throwing', function () {
    // Deny by default: a typo in a policy must fail closed.
    expect(guardianLink(guardianFlagSet(GUARDIAN_MATRIX_FLAGS))->authorises('results.everything.view'))
        ->toBeFalse();
});

it('never lets is_primary widen a scope', function () {
    // 7.5: "is_primary grants nothing on its own." It is not even an input to
    // GuardianAuthorizationFlags, and this is the test that says so out loud.
    $primary = guardianLink(guardianFlagSet(['has_custody']), ['is_primary' => true]);
    $plain = guardianLink(guardianFlagSet(['has_custody']));

    foreach (GuardianCapability::cases() as $capability) {
        expect($primary->authorises($capability->value))
            ->toBe($plain->authorises($capability->value), $capability->value);
    }
});

// ---------------------------------------------------------------------------
// 7.6 - changing a flag
// ---------------------------------------------------------------------------

it('closes the current link and opens a successor, auditing before AND after', function () {
    $user = guardiansUserAs();
    actingAs($user);

    $link = guardianLink(guardianFlagSet(['has_custody', 'receives_reports']));

    $successor = app(SetGuardianAuthorization::class)->handle(
        link: $link,
        flags: ['has_custody' => false, 'is_fee_payer' => true],
        reason: 'Court order of 12 November removes custody.',
    );

    $closed = $link->refresh();

    expect($closed->valid_to?->toDateString())->toBe(BusinessDate::today())
        ->and($closed->revocation_reason)->toBe('Court order of 12 November removes custody.')
        ->and($closed->has_custody)->toBeTrue()
        ->and($successor->getKey())->not->toBe($closed->getKey())
        ->and($successor->valid_from->toDateString())
        ->toBe(Carbon::parse(BusinessDate::today())->addDay()->toDateString())
        ->and($successor->has_custody)->toBeFalse()
        ->and($successor->is_fee_payer)->toBeTrue()
        // Flags not named in the change carry over untouched.
        ->and($successor->receives_reports)->toBeTrue();

    /** @var AuditLog $entry */
    $entry = AuditLog::query()
        ->where('auditable_type', StudentGuardian::class)
        ->where('action', 'updated')
        ->latest('id')
        ->firstOrFail();

    expect($entry->before)->toMatchArray(['has_custody' => true, 'is_fee_payer' => false])
        ->and($entry->after)->toMatchArray(['has_custody' => false, 'is_fee_payer' => true])
        // The FULL flag set, both sides - not just the deltas.
        ->and($entry->before)->toHaveKeys(StudentGuardian::AUDITED_SCOPE_COLUMNS)
        ->and($entry->after)->toHaveKeys(StudentGuardian::AUDITED_SCOPE_COLUMNS);
});

it('leaves exactly one link in force on every calendar day across the change', function () {
    // The close-today / start-tomorrow pair is not a gap: under the 7.3
    // predicate the closed row is still valid today and the successor is not
    // valid until tomorrow.
    actingAs(guardiansUserAs());

    $link = guardianLink(guardianFlagSet(['receives_reports']));

    app(SetGuardianAuthorization::class)->handle($link, ['is_fee_payer' => true], 'Fee payer changed.');

    $today = BusinessDate::today();

    foreach ([$today, Carbon::parse($today)->addDay()->toDateString()] as $day) {
        $inForce = StudentGuardian::query()
            ->where('student_id', '=', $link->student_id)
            ->where('guardian_id', '=', $link->guardian_id)
            ->validOn($day)
            ->count();

        expect($inForce)->toBe(1, "exactly one link in force on {$day}");
    }
});

it('refuses an authorization change without the permission, and refuses a no-op', function () {
    actingAs(guardiansUserAs());
    $link = guardianLink(guardianFlagSet(['has_custody']));

    expect(fn () => app(SetGuardianAuthorization::class)->handle($link, ['has_custody' => true], 'nothing changes'))
        ->toThrow(ValidationException::class);

    expect(fn () => app(SetGuardianAuthorization::class)->handle($link, ['not_a_flag' => true], 'typo'))
        ->toThrow(ValidationException::class);

    actingAs(guardiansUserAs(withPermission: false));

    expect(fn () => app(SetGuardianAuthorization::class)->handle($link, ['has_custody' => false], 'no rights'))
        ->toThrow(AuthorizationException::class);

    expect($link->refresh()->valid_to)->toBeNull();
});

it('takes effect on the successor row, and only from tomorrow', function () {
    actingAs(guardiansUserAs());

    $link = guardianLink(guardianFlagSet(['has_custody']));
    $today = BusinessDate::today();
    $tomorrow = Carbon::parse($today)->addDay()->toDateString();

    $successor = app(SetGuardianAuthorization::class)
        ->handle($link, ['has_custody' => false], 'Custody removed.')
        ->load('guardian');

    // Today the OLD scope still applies - which is the honest answer, and the
    // reason 7.6 also demands session revocation for the urgent case.
    expect($link->refresh()->load('guardian')->authorises(GuardianCapability::R19ViewDisciplineList->value, $today))
        ->toBeTrue()
        ->and($successor->authorises(GuardianCapability::R19ViewDisciplineList->value, $today))->toBeFalse()
        ->and($successor->authorises(GuardianCapability::R19ViewDisciplineList->value, $tomorrow))->toBeFalse();
});
