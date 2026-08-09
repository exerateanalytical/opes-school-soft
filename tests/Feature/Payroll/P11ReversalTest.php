<?php

declare(strict_types=1);

use App\Modules\Accounting\Domain\AccountingPeriodStatus;
use App\Modules\Payroll\Actions\ApprovePayrollRun;
use App\Modules\Payroll\Actions\CalculatePayrollRun;
use App\Modules\Payroll\Actions\ReversePayrollRun;
use App\Modules\Payroll\Domain\RunStatus;
use App\Modules\Payroll\Domain\RunType;
use App\Modules\Payroll\Models\PayrollItem;
use App\Modules\Payroll\Models\PayrollRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

require_once __DIR__.'/P11RunHelpers.php';

/*
 * docs/specs/05-hr-payroll.md 8.7 - the reversal path, mirroring Fees'
 * VoidPayment: an approved run is never mutated. Reversal creates a NEW
 * run and contrepasses the original journal entry through Accounting's
 * ReverseJournalEntry.
 */

if (! function_exists('p11revApprovedRun')) {
    /**
     * @return array{run: PayrollRun, user: App\Modules\Identity\Models\User, approver: App\Modules\Identity\Models\User}
     */
    function p11revApprovedRun(): array
    {
        $ready = p11runReady();
        $approver = p11runActor();
        p11runStaff(300_000, $ready['user']);

        actingAs($ready['user']);
        $run = app(CalculatePayrollRun::class)->handle('2031-01-01', RunType::Regular, $ready['user']->toAuditActor());

        actingAs($approver);
        $run = app(ApprovePayrollRun::class)->handle((int) $run->getKey(), $approver->toAuditActor());

        return ['run' => $run, 'user' => $ready['user'], 'approver' => $approver];
    }
}

it('reverses an approved run: a new reversal run, the original cancelled, items freed for recalculation', function (): void {
    ['run' => $run] = p11revApprovedRun();

    $originalItemIds = PayrollItem::query()->where('payroll_run_id', $run->getKey())->pluck('id');
    expect($originalItemIds)->not->toBeEmpty();

    $reverser = p11runActor();
    actingAs($reverser);

    $reversal = app(ReversePayrollRun::class)->handle(
        (int) $run->getKey(),
        'Test fixture: reversing the January 2031 regular run.',
        $reverser->toAuditActor(),
    );

    expect($reversal->run_type)->toBe(RunType::Reversal)
        ->and($reversal->status)->toBe(RunStatus::Approved)
        ->and((int) $reversal->reverses_run_id)->toBe((int) $run->getKey())
        ->and($reversal->journal_entry_id)->not->toBeNull();

    $run->refresh();
    expect($run->status)->toBe(RunStatus::Cancelled)
        ->and($run->cancellation_reason)->toContain('reversing the January 2031')
        ->and($run->cancelled_by)->toBe($reverser->id);

    // Every original item is cancelled - active_month collapses to NULL,
    // freeing the month for recalculation.
    foreach (PayrollItem::query()->whereIn('id', $originalItemIds)->get() as $item) {
        expect($item->is_cancelled)->toBeTrue();
    }

    expect(DB::table('payroll_items')->whereIn('id', $originalItemIds)->whereNotNull('active_month')->count())
        ->toBe(0);

    // The reversal contrepasses the ORIGINAL journal entry.
    $originalEntry = DB::table('journal_entries')->where('id', $run->journal_entry_id)->firstOrFail();
    $reversalEntry = DB::table('journal_entries')->where('id', $reversal->journal_entry_id)->firstOrFail();

    expect((int) $reversalEntry->reverses_entry_id)->toBe((int) $originalEntry->id)
        ->and((int) $originalEntry->reversed_by_entry_id)->toBe((int) $reversalEntry->id);
});

it('frees the month: after reversal, the SAME month can be recalculated', function (): void {
    ['run' => $run, 'user' => $user] = p11revApprovedRun();

    $reverser = p11runActor();
    actingAs($reverser);
    app(ReversePayrollRun::class)->handle(
        (int) $run->getKey(),
        'Test fixture: freeing January 2031 for recalculation.',
        $reverser->toAuditActor(),
    );

    actingAs($user);
    $recalculated = app(CalculatePayrollRun::class)->handle('2031-01-01', RunType::Regular, $user->toAuditActor());

    expect($recalculated->getKey())->not->toBe($run->getKey())
        ->and($recalculated->status)->toBe(RunStatus::Calculated)
        ->and(PayrollItem::query()->where('payroll_run_id', $recalculated->getKey())->where('is_cancelled', false)->count())
        ->toBe(1);
});

it('reversing a reversal is forbidden', function (): void {
    ['run' => $run] = p11revApprovedRun();

    $reverser = p11runActor();
    actingAs($reverser);

    $reversal = app(ReversePayrollRun::class)->handle(
        (int) $run->getKey(), 'Test fixture: first reversal.', $reverser->toAuditActor(),
    );

    expect(fn () => app(ReversePayrollRun::class)->handle(
        (int) $reversal->getKey(), 'Test fixture: reversing a reversal, must fail.', $reverser->toAuditActor(),
    ))->toThrow(DomainException::class, 'Reversing a reversal is forbidden');
});

it('a run may not be reversed twice (reverses_run_id is UNIQUE)', function (): void {
    ['run' => $run] = p11revApprovedRun();

    $reverser = p11runActor();
    actingAs($reverser);

    app(ReversePayrollRun::class)->handle(
        (int) $run->getKey(), 'Test fixture: first reversal.', $reverser->toAuditActor(),
    );

    // The original is now `cancelled`; a second reversal attempt is
    // refused by the status guard before it ever reaches the UNIQUE check.
    expect(fn () => app(ReversePayrollRun::class)->handle(
        (int) $run->getKey(), 'Test fixture: second reversal attempt, must fail.', $reverser->toAuditActor(),
    ))->toThrow(DomainException::class);
});

it('only an approved or paid run can be reversed - a merely calculated run cannot', function (): void {
    $ready = p11runReady();
    p11runStaff(300_000, $ready['user']);

    actingAs($ready['user']);
    $run = app(CalculatePayrollRun::class)->handle('2031-01-01', RunType::Regular, $ready['user']->toAuditActor());

    expect(fn () => app(ReversePayrollRun::class)->handle(
        (int) $run->getKey(), 'Test fixture: reversing a merely calculated run, must fail.', $ready['user']->toAuditActor(),
    ))->toThrow(DomainException::class, 'calculated');
});

it('a reversal reason under 10 characters is rejected', function (): void {
    ['run' => $run] = p11revApprovedRun();

    $reverser = p11runActor();
    actingAs($reverser);

    expect(fn () => app(ReversePayrollRun::class)->handle(
        (int) $run->getKey(), 'too short', $reverser->toAuditActor(),
    ))->toThrow(ValidationException::class);
});

it('contrepasses into the EARLIEST OPEN period when the original run\'s own period has since closed', function (): void {
    ['run' => $run] = p11revApprovedRun();

    // Close January 2031 - the run's own period - after approval.
    DB::table('accounting_periods')
        ->where('period_month', '2031-01-01')
        ->update(['status' => AccountingPeriodStatus::HardLocked->value]);

    $reverser = p11runActor();
    actingAs($reverser);

    $reversal = app(ReversePayrollRun::class)->handle(
        (int) $run->getKey(),
        'Test fixture: original period closed before reversal.',
        $reverser->toAuditActor(),
    );

    $februaryPeriodId = DB::table('accounting_periods')->where('period_month', '2031-02-01')->value('id');

    expect((int) $reversal->accounting_period_id)->toBe((int) $februaryPeriodId)
        ->and((int) $reversal->accounting_period_id)->not->toBe((int) $run->accounting_period_id);
});
