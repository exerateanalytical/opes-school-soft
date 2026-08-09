<?php

declare(strict_types=1);

use App\Modules\HR\Actions\AccrueMonthlyLeave;
use App\Modules\HR\Actions\ApproveLeave;
use App\Modules\HR\Actions\RequestLeave;
use App\Modules\HR\Domain\HrPermission;
use App\Modules\HR\Domain\LeaveAccrualRateUnconfigured;
use App\Modules\HR\Domain\LeaveEntryType;
use App\Modules\HR\Domain\LeaveRequestStatus;
use App\Modules\HR\Models\LeaveAccrual;
use App\Modules\HR\Models\LeaveType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/P11F4TestHelpers.php';

uses(RefreshDatabase::class);

/*
 * docs/specs/05-hr-payroll.md 12 (H8): leave is an APPEND-ONLY signed-delta
 * ledger. Balance is always SUM; the accrual rate ships NULL and the system
 * refuses to accrue from an unverified figure.
 */

it('seeds the leave types WITHOUT statutory days or accrual rates', function () {
    $types = LeaveType::query()->get();

    expect($types)->not->toBeEmpty();

    foreach ($types as $type) {
        // 2.3 reference values are NEVER seed data (the 0 standing rule).
        expect($type->statutory_days)->toBeNull()
            ->and($type->monthly_accrual_days)->toBeNull();
    }

    expect(LeaveType::query()->where('code', 'conge_annuel')->exists())->toBeTrue();
});

it('rejects every UPDATE and DELETE on the ledger at the database', function () {
    p11declUser(HrPermission::MANAGE);
    $contract = p11declContract();
    $annual = LeaveType::query()->where('code', 'conge_annuel')->firstOrFail();

    $row = LeaveAccrual::query()->create([
        'staff_contract_id' => $contract->id,
        'leave_type_id' => $annual->id,
        'entry_type' => LeaveEntryType::Opening,
        'delta_days' => '10.00',
        'effective_on' => '2031-01-01',
        'created_by' => null,
    ]);

    expect(fn () => DB::table('leave_accruals')->where('id', $row->id)->update(['delta_days' => '99.00']))
        ->toThrow(QueryException::class, 'append-only');

    expect(fn () => DB::table('leave_accruals')->where('id', $row->id)->delete())
        ->toThrow(QueryException::class, 'never deleted');

    expect(LeaveAccrual::balance($contract->id, $annual->id))->toBe('10.00');
});

it('approves a request into one negative taken row and answers balance as SUM', function () {
    $approver = p11declUser(HrPermission::MANAGE, HrPermission::LEAVE_APPROVE);
    $contract = p11declContract();
    $annual = LeaveType::query()->where('code', 'conge_annuel')->firstOrFail();

    LeaveAccrual::query()->create([
        'staff_contract_id' => $contract->id,
        'leave_type_id' => $annual->id,
        'entry_type' => LeaveEntryType::Opening,
        'delta_days' => '18.00',
        'effective_on' => '2031-01-01',
        'created_by' => $approver->id,
    ]);

    $request = app(RequestLeave::class)->handle(
        staffContractId: $contract->id,
        leaveTypeCode: 'conge_annuel',
        startsOn: '2031-03-10',
        endsOn: '2031-03-16',
        workingDays: '5.00',
    );

    expect($request->status)->toBe(LeaveRequestStatus::Submitted);

    app(ApproveLeave::class)->approve($request->id, p11declActor($approver));

    $taken = LeaveAccrual::query()
        ->where('source_type', 'leave_request')
        ->where('source_id', $request->id)
        ->get();

    expect($taken)->toHaveCount(1)
        ->and($taken->first()?->entry_type)->toBe(LeaveEntryType::Taken)
        ->and($taken->first()?->delta_days)->toBe('-5.00')
        ->and(LeaveAccrual::balance($contract->id, $annual->id))->toBe('13.00')
        // ... and the balance BEFORE the leave started is still answerable.
        ->and(LeaveAccrual::balance($contract->id, $annual->id, '2031-02-01'))->toBe('18.00');
});

it('refuses overlapping approved leave for one contract', function () {
    $approver = p11declUser(HrPermission::MANAGE, HrPermission::LEAVE_APPROVE);
    $contract = p11declContract();

    $first = app(RequestLeave::class)->handle($contract->id, 'conge_annuel', '2031-03-10', '2031-03-16', '5.00');
    app(ApproveLeave::class)->approve($first->id, p11declActor($approver));

    $second = app(RequestLeave::class)->handle($contract->id, 'conge_annuel', '2031-03-14', '2031-03-20', '5.00');

    expect(fn () => app(ApproveLeave::class)->approve($second->id, p11declActor($approver)))
        ->toThrow(ValidationException::class);

    // A back-to-back request that does NOT overlap is fine.
    $third = app(RequestLeave::class)->handle($contract->id, 'conge_annuel', '2031-03-17', '2031-03-21', '4.00');
    app(ApproveLeave::class)->approve($third->id, p11declActor($approver));

    expect(LeaveAccrual::query()->where('entry_type', 'taken')->count())->toBe(2);
});

it('cancels approved leave with a compensating adjustment, never an edit', function () {
    $approver = p11declUser(HrPermission::MANAGE, HrPermission::LEAVE_APPROVE);
    $contract = p11declContract();
    $annual = LeaveType::query()->where('code', 'conge_annuel')->firstOrFail();

    $request = app(RequestLeave::class)->handle($contract->id, 'conge_annuel', '2031-03-10', '2031-03-16', '5.00');
    app(ApproveLeave::class)->approve($request->id, p11declActor($approver));

    expect(LeaveAccrual::balance($contract->id, $annual->id))->toBe('-5.00');

    app(ApproveLeave::class)->cancel($request->id, 'School reopened early', p11declActor($approver));

    // Two rows - the taken and its compensation - never one mutated row.
    expect(LeaveAccrual::query()->where('source_id', $request->id)->count())->toBe(2)
        ->and(LeaveAccrual::balance($contract->id, $annual->id))->toBe('0.00')
        ->and($request->refresh()->status)->toBe(LeaveRequestStatus::Cancelled);
});

it('requires the medical certificate where the type demands one', function () {
    p11declUser(HrPermission::MANAGE);
    $contract = p11declContract();

    expect(fn () => app(RequestLeave::class)->handle($contract->id, 'conge_maladie', '2031-03-10', '2031-03-12', '3.00'))
        ->toThrow(ValidationException::class, 'medical certificate');
});

it('requires leave.approve to decide a request', function () {
    $user = p11declUser(HrPermission::MANAGE);
    $contract = p11declContract();

    $request = app(RequestLeave::class)->handle($contract->id, 'conge_annuel', '2031-03-10', '2031-03-12', '3.00');

    expect(fn () => app(ApproveLeave::class)->approve($request->id, p11declActor($user)))
        ->toThrow(AuthorizationException::class);
});

it('refuses to accrue while the statutory rate is unconfigured', function () {
    p11declContract();

    expect(fn () => app(AccrueMonthlyLeave::class)->handle('2031-03-01'))
        ->toThrow(LeaveAccrualRateUnconfigured::class);

    expect(LeaveAccrual::query()->count())->toBe(0);
});

it('accrues one idempotent monthly row per eligible contract once configured', function () {
    p11declConfigureAccrualRate('1.50');

    $contract = p11declContract(null, ['starts_on' => '2030-09-01', 'seniority_reference_date' => '2030-09-01']);
    // Joined mid-month: not a full month of effective service in March.
    p11declContract(null, ['starts_on' => '2031-03-15', 'seniority_reference_date' => '2031-03-15']);

    $written = app(AccrueMonthlyLeave::class)->handle('2031-03-01');

    expect($written)->toBe(1);

    $rows = LeaveAccrual::query()->where('entry_type', 'accrual')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()?->staff_contract_id)->toBe($contract->id)
        ->and($rows->first()?->delta_days)->toBe('1.50')
        ->and($rows->first()?->effective_on->toDateString())->toBe('2031-03-31');

    // Idempotent: the schema's generated UNIQUE absorbs the second run.
    expect(app(AccrueMonthlyLeave::class)->handle('2031-03-01'))->toBe(0)
        ->and(LeaveAccrual::query()->where('entry_type', 'accrual')->count())->toBe(1);

    // The next month accrues again.
    expect(app(AccrueMonthlyLeave::class)->handle('2031-04-01'))->toBe(1);
});

it('accrues nothing for a month spent wholly on non-effective-service leave', function () {
    $approver = p11declUser(HrPermission::MANAGE, HrPermission::LEAVE_APPROVE);
    p11declConfigureAccrualRate('1.50');

    $contract = p11declContract(null, ['starts_on' => '2030-09-01', 'seniority_reference_date' => '2030-09-01']);

    // The whole of March on unpaid leave (counts_as_effective_service = false).
    $request = app(RequestLeave::class)->handle($contract->id, 'sans_solde', '2031-02-20', '2031-04-10', '35.00');
    app(ApproveLeave::class)->approve($request->id, p11declActor($approver));

    expect(app(AccrueMonthlyLeave::class)->handle('2031-03-01'))->toBe(0)
        ->and(LeaveAccrual::query()->where('entry_type', 'accrual')->count())->toBe(0);
});
