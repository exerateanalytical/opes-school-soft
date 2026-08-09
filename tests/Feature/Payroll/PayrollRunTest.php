<?php

declare(strict_types=1);

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Payroll\Actions\ApprovePayrollRun;
use App\Modules\Payroll\Actions\CalculatePayrollRun;
use App\Modules\Payroll\Domain\RunStatus;
use App\Modules\Payroll\Domain\RunType;
use App\Modules\Payroll\Models\PayrollItem;
use App\Modules\Payroll\Models\PayrollRun;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

require_once __DIR__.'/P11RunHelpers.php';

/*
 * docs/specs/05-hr-payroll.md 8 - the run lifecycle: calculate, approve,
 * the invariants each enforces. p11runReady() builds the whole happy-path
 * fixture (calendar, employer profile, verified reference rates, ledger
 * accounts, the school's own payroll.approved posting rule).
 */

it('calculates a run whose every item satisfies gross - deductions = net exactly', function (): void {
    $ready = p11runReady();
    p11runStaff(300_000, $ready['user']);
    p11runStaff(600_000, $ready['user']);

    $run = app(CalculatePayrollRun::class)->handle('2031-01-01', RunType::Regular, $ready['user']->toAuditActor());

    expect($run->status)->toBe(RunStatus::Calculated)
        ->and($run->inputs_hash)->not->toBeNull()
        ->and($run->calculated_by)->toBe($ready['user']->id);

    $items = PayrollItem::query()->where('payroll_run_id', $run->getKey())->get();

    expect($items)->toHaveCount(2);

    foreach ($items as $item) {
        expect($item->gross - $item->total_employee_deductions)->toBe($item->net);

        // The engine's own assertion, re-checked black-box: nothing about
        // the persisted item's net figure comes from anywhere but that
        // identity (05-hr-payroll 8.6 - no tolerance).
        $lineTotal = (int) DB::table('payroll_lines')->where('payroll_item_id', $item->id)->sum('amount');
        expect($lineTotal)->toBeGreaterThan(0);
    }
});

it('recalculation REPLACES a draft run\'s items rather than accumulating them', function (): void {
    $ready = p11runReady();
    p11runStaff(300_000, $ready['user']);

    $first = app(CalculatePayrollRun::class)->handle('2031-01-01', RunType::Regular, $ready['user']->toAuditActor());
    expect(PayrollItem::query()->where('payroll_run_id', $first->getKey())->count())->toBe(1);

    // A second staff member joins before the run is recalculated.
    p11runStaff(400_000, $ready['user']);

    $second = app(CalculatePayrollRun::class)->handle('2031-01-01', RunType::Regular, $ready['user']->toAuditActor());

    expect($second->getKey())->toBe($first->getKey())
        ->and(PayrollItem::query()->where('payroll_run_id', $second->getKey())->count())->toBe(2);
});

it('an idempotency key returns the SAME run on retry rather than creating a second one', function (): void {
    $ready = p11runReady();
    p11runStaff(300_000, $ready['user']);

    $first = app(CalculatePayrollRun::class)->handle(
        '2031-01-01', RunType::Regular, $ready['user']->toAuditActor(), idempotencyKey: 'p11-retry-key',
    );
    $second = app(CalculatePayrollRun::class)->handle(
        '2031-01-01', RunType::Regular, $ready['user']->toAuditActor(), idempotencyKey: 'p11-retry-key',
    );

    expect($second->getKey())->toBe($first->getKey())
        ->and(PayrollRun::query()->count())->toBe(1);
});

it('only a CALCULATED run can be approved', function (): void {
    $ready = p11runReady();
    p11runStaff(300_000, $ready['user']);

    // A draft run - never calculated.
    $draft = PayrollRun::query()->create([
        'payroll_month' => '2031-02-01',
        'run_type' => RunType::Regular->value,
        'status' => RunStatus::Draft->value,
        'fiscal_year_id' => DB::table('fiscal_years')->value('id'),
        'academic_year_id' => DB::table('academic_years')->value('id'),
        'accounting_period_id' => DB::table('accounting_periods')->where('period_month', '2031-02-01')->value('id'),
        'employer_profile_id' => $ready['profile']->getKey(),
    ]);

    expect(fn () => app(ApprovePayrollRun::class)->handle((int) $draft->getKey(), $ready['user']->toAuditActor()))
        ->toThrow(DomainException::class, 'draft');
});

it('segregation of duties: the actor who calculated a run cannot approve it', function (): void {
    $ready = p11runReady();
    p11runStaff(300_000, $ready['user']);

    actingAs($ready['user']);
    $run = app(CalculatePayrollRun::class)->handle('2031-01-01', RunType::Regular, $ready['user']->toAuditActor());

    expect(fn () => app(ApprovePayrollRun::class)->handle((int) $run->getKey(), $ready['user']->toAuditActor()))
        ->toThrow(DomainException::class, 'segregation of duties');

    $run->refresh();
    expect($run->status)->toBe(RunStatus::Calculated);
});

it('approval succeeds when a DIFFERENT actor approves, and posts through PostFromEvent exactly once', function (): void {
    $ready = p11runReady();
    $approver = p11runActor();

    p11runStaff(300_000, $ready['user']);
    p11runStaff(500_000, $ready['user']);

    actingAs($ready['user']);
    $run = app(CalculatePayrollRun::class)->handle('2031-01-01', RunType::Regular, $ready['user']->toAuditActor());

    actingAs($approver);
    $approved = app(ApprovePayrollRun::class)->handle((int) $run->getKey(), $approver->toAuditActor());

    expect($approved->status)->toBe(RunStatus::Approved)
        ->and($approved->approved_by)->toBe($approver->id)
        ->and($approved->journal_entry_id)->not->toBeNull();

    // Exactly one journal entry, balanced, and no second posting path
    // anywhere in this module (05-hr-payroll 8.7).
    expect(JournalEntry::query()->count())->toBe(1);

    /** @var JournalEntry $entry */
    $entry = JournalEntry::query()->findOrFail($approved->journal_entry_id);
    $totalDebit = (int) $entry->lines()->sum('debit');
    $totalCredit = (int) $entry->lines()->sum('credit');

    expect($totalDebit)->toBeGreaterThan(0)
        ->and($totalDebit)->toBe($totalCredit);

    // Every referenced rate is now locked (4.4): append-only from here.
    $lockedCount = DB::table('payroll_lines')
        ->join('payroll_items', 'payroll_items.id', '=', 'payroll_lines.payroll_item_id')
        ->join('statutory_rates', 'statutory_rates.id', '=', 'payroll_lines.statutory_rate_id')
        ->where('payroll_items.payroll_run_id', $run->getKey())
        ->where('statutory_rates.locked', false)
        ->count();

    expect($lockedCount)->toBe(0);

    // Snapshots exist, one per item (10.2/10.3 - SnapshotTest covers them
    // in depth).
    $itemIds = PayrollItem::query()->where('payroll_run_id', $run->getKey())->pluck('id');
    expect(DB::table('payroll_item_snapshots')->whereIn('payroll_item_id', $itemIds)->count())->toBe(2);
});

it('approval re-verifies inputs_hash and refuses a run whose inputs moved since calculate', function (): void {
    $ready = p11runReady();
    $approver = p11runActor();
    $staff = p11runStaff(300_000, $ready['user']);

    actingAs($ready['user']);
    $run = app(CalculatePayrollRun::class)->handle('2031-01-01', RunType::Regular, $ready['user']->toAuditActor());

    // An input moves: a new grant lands on the same contract, in force for
    // the same period end, AFTER the hash was computed.
    DB::table('staff_compensations')->insert([
        'staff_contract_id' => $staff['contract']->id, 'component_code' => 'SENIORITY', 'amount' => null,
        'rate_bp' => 2_000, 'effective_from' => '2030-01-01', 'effective_to' => null,
        'retroactive_from' => null, 'granted_by' => $ready['user']->id, 'grant_reason' => 'Test fixture',
        'document_id' => null, 'version' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    actingAs($approver);

    expect(fn () => app(ApprovePayrollRun::class)->handle((int) $run->getKey(), $approver->toAuditActor()))
        ->toThrow(DomainException::class, 'Inputs changed');

    $run->refresh();
    expect($run->status)->toBe(RunStatus::Calculated);
});

it('the cross-run UNIQUE makes paying one person twice for one month a constraint violation', function (): void {
    $ready = p11runReady();
    $staff = p11runStaff(300_000, $ready['user']);

    actingAs($ready['user']);
    $run = app(CalculatePayrollRun::class)->handle('2031-01-01', RunType::Regular, $ready['user']->toAuditActor());

    $item = PayrollItem::query()->where('payroll_run_id', $run->getKey())->firstOrFail();

    // A second run for a DIFFERENT run_type, same month/profile, so it does
    // not collide with `uq_payroll_runs_active`.
    $otherRun = PayrollRun::query()->create([
        'payroll_month' => '2031-01-01',
        'run_type' => RunType::ThirteenthMonth->value,
        'status' => RunStatus::Draft->value,
        'fiscal_year_id' => $run->fiscal_year_id,
        'academic_year_id' => $run->academic_year_id,
        'accounting_period_id' => $run->accounting_period_id,
        'employer_profile_id' => $run->employer_profile_id,
    ]);

    expect(fn () => PayrollItem::query()->create([
        'payroll_run_id' => $otherRun->getKey(),
        'staff_member_id' => $item->staff_member_id,
        'staff_contract_id' => $item->staff_contract_id,
        'payroll_month' => '2031-01-01',
        'is_cancelled' => false,
        'days_worked' => $item->days_worked,
        'days_in_period' => $item->days_in_period,
        'hours_validated' => null,
        'gross' => $item->gross,
        'sbt' => $item->sbt,
        'cnps_capped_base' => $item->cnps_capped_base,
        'cnps_uncapped_base' => $item->cnps_uncapped_base,
        'taxable_base' => $item->taxable_base,
        'irpp_amount' => $item->irpp_amount,
        'total_employee_deductions' => $item->total_employee_deductions,
        'total_employer_charges' => $item->total_employer_charges,
        'net' => $item->net,
        'ytd_sbt' => $item->ytd_sbt,
        'ytd_irpp_withheld' => $item->ytd_irpp_withheld,
        'exception_flags' => [],
    ]))->toThrow(QueryException::class);
});
