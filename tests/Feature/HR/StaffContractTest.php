<?php

declare(strict_types=1);

use App\Modules\HR\Actions\HireStaffMember;
use App\Modules\HR\Actions\OpenStaffContract;
use App\Modules\HR\Actions\TerminateContract;
use App\Modules\HR\Domain\CddLimitExceeded;
use App\Modules\HR\Domain\CnpsRegistrationStatus;
use App\Modules\HR\Domain\ContractType;
use App\Modules\HR\Domain\HrPermission;
use App\Modules\HR\Domain\TerminationReason;
use App\Modules\HR\Domain\WorkingTime;
use App\Modules\HR\Models\StaffContract;
use App\Modules\HR\Models\StaffMember;
use App\Modules\Identity\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/*
 * docs/specs/05-hr-payroll.md 3.3-3.5: hire, the CDD invariant, the
 * one-active-contract-per-role rule, MINTSS visas and branch exemptions.
 *
 * The staff.* permissions are granted DIRECTLY (Spatie rows), not through
 * RolePermissionSeeder: the Phase 11 wiring package (F5) owns the Permission
 * enum cases and role mapping, while these suites exercise the Actions' own
 * gates.
 */

if (! function_exists('p11hrUser')) {
    /** A signed-in user holding exactly the named HR abilities. */
    function p11hrUser(string ...$permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            \Spatie\Permission\Models\Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        $user = $user->fresh() ?? $user;
        actingAs($user);

        return $user;
    }
}

if (! function_exists('p11hrHire')) {
    function p11hrHire(
        ?string $hiredOn = null,
        ?string $nationalIdNumber = null,
        ?string $cnpsNumber = null,
        string $nationality = 'CM',
    ): StaffMember {
        return app(HireStaffMember::class)->handle(
            firstName: 'Ngwa',
            lastName: 'Bertrand',
            gender: 'male',
            dateOfBirth: '1988-04-12',
            phone: '+237650000001',
            hiredOn: $hiredOn,
            nationality: $nationality,
            nationalIdNumber: $nationalIdNumber,
            cnpsNumber: $cnpsNumber,
        );
    }
}

if (! function_exists('p11hrOpenContract')) {
    function p11hrOpenContract(
        StaffMember $staff,
        string $contractRole = 'teaching',
        ContractType $contractType = ContractType::Cdi,
        string $startsOn = '2026-01-05',
        ?string $endsOn = null,
        ?int $renewedFromContractId = null,
        ?string $mintssVisaRef = null,
        ?App\Modules\HR\Domain\SocialSecurityStatus $socialSecurityStatus = null,
    ): StaffContract {
        return app(OpenStaffContract::class)->handle(
            staffMemberId: $staff->id,
            contractRole: $contractRole,
            contractType: $contractType,
            workingTime: WorkingTime::FullTime,
            departmentId: \App\Modules\Academics\Models\Department::factory()->create()->id,
            positionId: \App\Modules\HR\Models\Position::factory()->create()->id,
            startsOn: $startsOn,
            endsOn: $endsOn,
            renewedFromContractId: $renewedFromContractId,
            mintssVisaRef: $mintssVisaRef,
            socialSecurityStatus: $socialSecurityStatus ?? App\Modules\HR\Domain\SocialSecurityStatus::AffilieCnps,
        );
    }
}

it('hires a staff member with the CNPS 8-day registration clock running', function () {
    p11hrUser(HrPermission::MANAGE);

    $staff = p11hrHire(hiredOn: '2026-09-01', nationalIdNumber: 'CM-1234-5678');

    expect($staff->staff_no)->toStartWith('STF/2026/');
    expect($staff->cnps_registration_status)->toBe(CnpsRegistrationStatus::Pending);
    expect($staff->cnps_registration_deadline?->toDateString())->toBe('2026-09-09');

    // 00-core 9.5: the national ID is encrypted at rest - the raw column
    // must NOT contain the plaintext - while the blind index is deterministic.
    $raw = DB::table('staff_members')->where('id', $staff->id)->first();
    expect((string) $raw?->national_id_number)->not->toContain('CM-1234-5678');
    expect($raw?->national_id_blind_index)->toBe(StaffMember::blindIndexFor('CM-1234-5678'));
});

it('marks a worker arriving with a CNPS number as already registered', function () {
    p11hrUser(HrPermission::MANAGE);

    $staff = p11hrHire(hiredOn: '2026-09-01', cnpsNumber: 'CNPS-778899');

    expect($staff->cnps_registration_status)->toBe(CnpsRegistrationStatus::Registered);
    expect($staff->cnps_registration_deadline)->toBeNull();
});

it('refuses to hire the same national identity twice', function () {
    p11hrUser(HrPermission::MANAGE);

    p11hrHire(nationalIdNumber: 'CM 999 000');

    // Same card, different clerk transcription: the blind index canonicalises.
    expect(fn () => p11hrHire(nationalIdNumber: 'cm-999000'))
        ->toThrow(ValidationException::class);
});

it('refuses hiring without the staff.manage permission', function () {
    p11hrUser(HrPermission::VIEW);

    expect(fn () => p11hrHire())
        ->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
});

it('opens a contract and refuses an overlapping contract in the same role', function () {
    p11hrUser(HrPermission::MANAGE);
    $staff = p11hrHire();

    $contract = p11hrOpenContract($staff);

    // active_role_key is a stored generated column: MySQL computes it, so
    // the freshly-saved model only sees it after a refresh.
    $contract->refresh();
    expect($contract->active_role_key)->toBe('teaching');

    expect(fn () => p11hrOpenContract($staff, startsOn: '2026-03-01'))
        ->toThrow(ValidationException::class);
});

it('allows concurrent contracts in different roles', function () {
    p11hrUser(HrPermission::MANAGE);
    $staff = p11hrHire();

    p11hrOpenContract($staff, contractRole: 'teaching');
    $boarding = p11hrOpenContract($staff, contractRole: 'boarding');

    expect($boarding->exists)->toBeTrue();
    expect(StaffContract::query()->where('staff_member_id', $staff->id)->count())->toBe(2);
});

it('enforces one open-ended contract per role at the database as well', function () {
    // The uq_active_contract_role generated-column key must hold even for
    // writes that bypass the Action.
    $first = StaffContract::factory()->create(['contract_role' => 'teaching']);

    expect(fn () => StaffContract::factory()->create([
        'staff_member_id' => $first->staff_member_id,
        'contract_role' => 'teaching',
        'starts_on' => '2027-01-01',
        'department_id' => $first->department_id,
        'position_id' => $first->position_id,
    ]))->toThrow(QueryException::class);
});

it('refuses a CDD without an end date', function () {
    p11hrUser(HrPermission::MANAGE);
    $staff = p11hrHire();

    expect(fn () => p11hrOpenContract($staff, contractType: ContractType::Cdd))
        ->toThrow(ValidationException::class);
});

it('rejects a CDD without an end date at the database CHECK too', function () {
    expect(fn () => StaffContract::factory()->create([
        'contract_type' => 'cdd',
        'ends_on' => null,
    ]))->toThrow(QueryException::class);
});

it('allows one CDD renewal and refuses the second', function () {
    p11hrUser(HrPermission::MANAGE);
    $staff = p11hrHire();

    $first = p11hrOpenContract($staff, contractType: ContractType::Cdd, startsOn: '2026-01-01', endsOn: '2026-09-01');

    $renewal = p11hrOpenContract($staff, contractType: ContractType::Cdd, startsOn: '2026-09-01', endsOn: '2027-05-01', renewedFromContractId: $first->id);

    expect($renewal->renewal_count)->toBe(1);
    // The renewal keeps the chain's seniority clock, not its own start.
    expect($renewal->seniority_reference_date->toDateString())->toBe('2026-01-01');

    expect(fn () => p11hrOpenContract($staff, contractType: ContractType::Cdd, startsOn: '2027-05-01', endsOn: '2027-12-01', renewedFromContractId: $renewal->id))
        ->toThrow(CddLimitExceeded::class);
});

it('refuses a CDD chain that exceeds two years of total duration', function () {
    p11hrUser(HrPermission::MANAGE);
    $staff = p11hrHire();

    $first = p11hrOpenContract($staff, contractType: ContractType::Cdd, startsOn: '2026-01-01', endsOn: '2027-06-01');

    // One renewal - permitted - but 2026-01-01 + 18 more months crosses the
    // two-year chain ceiling counted from the FIRST contract's start.
    expect(fn () => p11hrOpenContract($staff, contractType: ContractType::Cdd, startsOn: '2027-06-01', endsOn: '2028-06-01', renewedFromContractId: $first->id))
        ->toThrow(CddLimitExceeded::class);
});

it('requires a MINTSS visa reference for non-Cameroonian staff', function () {
    p11hrUser(HrPermission::MANAGE);
    $staff = p11hrHire(nationality: 'NG');

    expect(fn () => p11hrOpenContract($staff))
        ->toThrow(ValidationException::class);

    $contract = p11hrOpenContract($staff, mintssVisaRef: 'MINTSS/2026/00123');
    expect($contract->mintss_visa_ref)->toBe('MINTSS/2026/00123');
});

it('stores branch exemptions uniquely and never without evidence', function () {
    p11hrUser(HrPermission::MANAGE);
    $staff = p11hrHire();
    $contract = p11hrOpenContract($staff);
    $approver = User::factory()->create();

    $contract->exemptions()->create([
        'branch' => 'PVID',
        'effective_from' => '2026-01-05',
        'exemption_document_ref' => 'CNPS/DET/2026/17',
        'approved_by' => $approver->id,
    ]);

    // Same branch, same effective date: uq_contract_exemption.
    expect(fn () => $contract->exemptions()->create([
        'branch' => 'PVID',
        'effective_from' => '2026-01-05',
        'exemption_document_ref' => 'CNPS/DET/2026/18',
        'approved_by' => $approver->id,
    ]))->toThrow(QueryException::class);

    // An exemption is a claim the inspector will test: no document, no row.
    expect(fn () => DB::table('staff_contract_exemptions')->insert([
        'staff_contract_id' => $contract->id,
        'branch' => 'RP',
        'effective_from' => '2026-01-05',
        'exemption_document_ref' => null,
        'approved_by' => $approver->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('requires an evidencing document for an exempt social-security status', function () {
    p11hrUser(HrPermission::MANAGE);
    $staff = p11hrHire();

    expect(fn () => p11hrOpenContract($staff, socialSecurityStatus: App\Modules\HR\Domain\SocialSecurityStatus::ConventionBilaterale))
        ->toThrow(ValidationException::class);
});

it('terminates the last contract and declares the CNPS departure', function () {
    p11hrUser(HrPermission::MANAGE);
    $staff = p11hrHire(hiredOn: '2026-01-05', cnpsNumber: 'CNPS-1122');
    $contract = p11hrOpenContract($staff);

    $terminated = app(TerminateContract::class)->handle(
        contractId: $contract->id,
        reason: TerminationReason::Resignation,
        lastWorkingDay: '2026-06-30',
    );

    // ends_on is exclusive: the day after the last working day.
    expect($terminated->ends_on?->toDateString())->toBe('2026-07-01');
    expect($terminated->termination_reason)->toBe(TerminationReason::Resignation);

    // The generated active_role_key clears once ends_on is set - re-read
    // from MySQL, which owns the column.
    $terminated->refresh();
    expect($terminated->active_role_key)->toBeNull();

    $staff->refresh();
    expect($staff->status)->toBe('terminated');
    expect($staff->cnps_registration_status)->toBe(CnpsRegistrationStatus::DeclaredDeparted);
});

it('keeps the person active while another contract remains in force', function () {
    p11hrUser(HrPermission::MANAGE);
    $staff = p11hrHire();
    $teaching = p11hrOpenContract($staff, contractRole: 'teaching');
    p11hrOpenContract($staff, contractRole: 'boarding');

    app(TerminateContract::class)->handle(
        contractId: $teaching->id,
        reason: TerminationReason::Mutual,
        lastWorkingDay: Carbon::parse('2026-06-30')->toDateString(),
    );

    $staff->refresh();
    expect($staff->status)->toBe('active');
    expect($staff->cnps_registration_status)->not->toBe(CnpsRegistrationStatus::DeclaredDeparted);
});

it('refuses to terminate a contract twice', function () {
    p11hrUser(HrPermission::MANAGE);
    $staff = p11hrHire();
    $contract = p11hrOpenContract($staff);

    app(TerminateContract::class)->handle($contract->id, TerminationReason::Resignation, '2026-06-30');

    expect(fn () => app(TerminateContract::class)->handle($contract->id, TerminationReason::Resignation, '2026-07-31'))
        ->toThrow(ValidationException::class);
});
