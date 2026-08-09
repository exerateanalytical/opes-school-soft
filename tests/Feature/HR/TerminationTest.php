<?php

declare(strict_types=1);

use App\Modules\HR\Actions\ComputeTerminationSettlement;
use App\Modules\HR\Actions\TerminateContract;
use App\Modules\HR\Domain\HrPermission;
use App\Modules\HR\Domain\LeaveEntryType;
use App\Modules\HR\Domain\SettlementStatus;
use App\Modules\HR\Domain\TerminationReason;
use App\Modules\HR\Models\LeaveAccrual;
use App\Modules\HR\Models\LeaveType;
use App\Modules\HR\Models\TerminationSettlement;
use App\Modules\Payroll\Actions\GenerateStatutoryDeclarations;
use App\Modules\Payroll\Models\StatutoryDeclaration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/P11F4TestHelpers.php';

uses(RefreshDatabase::class);

/*
 * docs/specs/05-hr-payroll.md 13 (H9): one settlement per contract, computed
 * seniority, ledger-sourced leave days - and severance that REFUSES to be
 * computed while the Arrêté 016/MTPS schedule is unverified.
 */

it('drafts the settlement with computed seniority and the ledger leave balance', function () {
    $user = p11declUser(HrPermission::MANAGE);

    $staff = p11declStaff();
    $contract = p11declContract($staff, [
        'starts_on' => '2026-01-01',
        'seniority_reference_date' => '2026-01-01',
    ]);

    $annual = LeaveType::query()->where('code', 'conge_annuel')->firstOrFail();
    LeaveAccrual::query()->create([
        'staff_contract_id' => $contract->id,
        'leave_type_id' => $annual->id,
        'entry_type' => LeaveEntryType::Opening,
        'delta_days' => '12.00',
        'effective_on' => '2030-01-01',
        'created_by' => $user->id,
    ]);

    app(TerminateContract::class)->handle($contract->id, TerminationReason::Licenciement, '2031-03-30');

    $settlement = app(ComputeTerminationSettlement::class)->handle(
        staffContractId: $contract->id,
        actor: p11declActor($user),
        indemniteLicenciement: 425000,
        indemniteBasisNote: 'Manual per union agreement pending Arrêté 016/MTPS verification',
        leaveCompensation: 60000,
    );

    expect($settlement->status)->toBe(SettlementStatus::Draft)
        ->and($settlement->termination_type)->toBe(TerminationReason::Licenciement)
        ->and($settlement->last_working_day->toDateString())->toBe('2031-03-30')
        // 2026-01-01 → 2031-03-30 is about 5.24 years.
        ->and((float) $settlement->seniority_years)->toEqualWithDelta(5.24, 0.02)
        ->and($settlement->other_amounts)->toHaveKey('leave_days_balance', '12.00')
        ->and($settlement->indemnite_licenciement)->toBe(425000);
});

it('refuses a settlement for a contract that is not terminated', function () {
    $user = p11declUser(HrPermission::MANAGE);
    $contract = p11declContract();

    expect(fn () => app(ComputeTerminationSettlement::class)->handle($contract->id, p11declActor($user)))
        ->toThrow(ValidationException::class, 'terminate it first');
});

it('gives a contract exactly one settlement, forever', function () {
    $user = p11declUser(HrPermission::MANAGE);
    $contract = p11declContract();

    app(TerminateContract::class)->handle($contract->id, TerminationReason::Resignation, '2031-03-30');
    app(ComputeTerminationSettlement::class)->handle($contract->id, p11declActor($user));

    expect(fn () => app(ComputeTerminationSettlement::class)->handle($contract->id, p11declActor($user)))
        ->toThrow(ValidationException::class, 'exactly one');

    expect(TerminationSettlement::query()->where('staff_contract_id', $contract->id)->count())->toBe(1);
});

it('refuses manual severance without a basis note - the schedule is unverified', function () {
    $user = p11declUser(HrPermission::MANAGE);
    $contract = p11declContract();

    app(TerminateContract::class)->handle($contract->id, TerminationReason::Licenciement, '2031-03-30');

    expect(fn () => app(ComputeTerminationSettlement::class)->handle(
        staffContractId: $contract->id,
        actor: p11declActor($user),
        indemniteLicenciement: 425000,
    ))->toThrow(ValidationException::class, 'basis note');
});

it('takes the severance IRPP split as both portions or neither', function () {
    $user = p11declUser(HrPermission::MANAGE);
    $contract = p11declContract();

    app(TerminateContract::class)->handle($contract->id, TerminationReason::Licenciement, '2031-03-30');

    expect(fn () => app(ComputeTerminationSettlement::class)->handle(
        staffContractId: $contract->id,
        actor: p11declActor($user),
        exemptPortion: 100000,
    ))->toThrow(ValidationException::class, 'BOTH portions or neither');
});

it('feeds the CNPS departure declaration for the departed worker', function () {
    p11declUser(HrPermission::MANAGE);

    $staff = p11declStaff();
    $staff->forceFill(['cnps_registration_status' => 'registered'])->save();
    $contract = p11declContract($staff);

    app(TerminateContract::class)->handle($contract->id, TerminationReason::Retirement, '2031-03-30');

    expect($staff->refresh()->cnps_registration_status->value)->toBe('declared_departed');

    // The compliance job materialises the 11.5 filing row - once.
    app(GenerateStatutoryDeclarations::class)->handle('2031-03-01');
    app(GenerateStatutoryDeclarations::class)->handle('2031-04-01');

    $rows = StatutoryDeclaration::query()
        ->where('type', 'staff_departure')
        ->where('staff_member_id', $staff->id)
        ->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()?->payee)->toBe('CNPS')
        // Departure filing deadline is NEEDS VERIFICATION: no fabricated date.
        ->and($rows->first()?->due_date)->toBeNull();
});
