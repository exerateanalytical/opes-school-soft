<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Modules\Accounting\Actions\ReverseJournalEntry;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Payroll\Domain\PayrollPermission;
use App\Modules\Payroll\Domain\RunStatus;
use App\Modules\Payroll\Domain\RunType;
use App\Modules\Payroll\Models\PayrollItem;
use App\Modules\Payroll\Models\PayrollRun;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/05-hr-payroll.md 8.7 - THE correction path, mirroring Fees'
 * VoidPayment deliberately: an approved run is NEVER mutated. Reversal
 * creates a NEW run (`run_type = reversal`, `reverses_run_id` UNIQUE),
 * contrepasses the original journal entry through Accounting's
 * ReverseJournalEntry - which dates the reversal in the EARLIEST OPEN
 * period, never the original date (02-accounting C9) - and cancels the
 * original run. Its items' `active_month` collapses to NULL so the month
 * can be recalculated; its SNAPSHOTS remain readable forever (the
 * INSERT-only triggers would reject anything else).
 *
 * Reversing a reversal is refused, and the reverses_run_id UNIQUE makes a
 * second reversal of one run a constraint violation, not a race.
 */
final class ReversePayrollRun
{
    private const MIN_REASON_LENGTH = 10;

    public function __construct(
        private readonly ReverseJournalEntry $reverse,
        private readonly WriteAuditEntry $audit,
    ) {}

    public function handle(int $runId, string $reason, Actor $actor): PayrollRun
    {
        Gate::authorize(PayrollPermission::REVERSE);

        if (mb_strlen(trim($reason)) < self::MIN_REASON_LENGTH) {
            throw ValidationException::withMessages([
                'cancellation_reason' => sprintf(
                    'A reversal reason must be at least %d characters (05-hr-payroll 8.7).',
                    self::MIN_REASON_LENGTH,
                ),
            ]);
        }

        return DB::transaction(function () use ($runId, $reason, $actor): PayrollRun {
            /** @var PayrollRun $original */
            $original = PayrollRun::query()->lockForUpdate()->findOrFail($runId);

            if ($original->run_type === RunType::Reversal) {
                throw new DomainException('Reversing a reversal is forbidden (05-hr-payroll 8.7).');
            }

            if (! in_array($original->status, [RunStatus::Approved, RunStatus::Paid], true)) {
                throw new DomainException(sprintf(
                    'Only an approved or paid run can be reversed; run %d is %s. A calculated run is simply discarded (8.7).',
                    $runId,
                    $original->status->value,
                ));
            }

            if (PayrollRun::query()->where('reverses_run_id', $original->getKey())->exists()) {
                throw new DomainException(sprintf('Run %d has already been reversed (reverses_run_id is UNIQUE).', $runId));
            }

            // The contrepassation, through Accounting's one reversal door:
            // it lands in the earliest OPEN period and stamps
            // reverses_entry_id - never a hand-built contra entry.
            $reversalEntryId = null;
            $reversalPeriodId = $original->accounting_period_id;

            if ($original->journal_entry_id !== null) {
                $reversalEntry = $this->reverse->handle(
                    $original->journal_entry_id,
                    sprintf('Reversal of payroll run %d: %s', (int) $original->getKey(), $reason),
                    $actor,
                );

                $reversalEntryId = (int) $reversalEntry->getKey();
                $reversalPeriodId = (int) $reversalEntry->accounting_period_id;
            }

            // The reversal run row - the auditable event, holding the
            // contrepassation entry and pointing at what it undoes.
            /** @var PayrollRun $reversal */
            $reversal = PayrollRun::query()->create([
                'payroll_month' => $original->payroll_month->toDateString(),
                'run_type' => RunType::Reversal->value,
                'status' => RunStatus::Approved->value,
                'fiscal_year_id' => $original->fiscal_year_id,
                'academic_year_id' => $original->academic_year_id,
                'accounting_period_id' => $reversalPeriodId,
                'employer_profile_id' => $original->employer_profile_id,
                'reverses_run_id' => (int) $original->getKey(),
                'approved_by' => $actor->id,
                'approved_at' => Carbon::now(),
                'journal_entry_id' => $reversalEntryId,
            ]);

            // Cancel the original - conditional UPDATE, affected-rows
            // checked (00-core 10.4).
            $cancelled = PayrollRun::query()
                ->whereKey($original->getKey())
                ->whereIn('status', [RunStatus::Approved->value, RunStatus::Paid->value])
                ->update([
                    'status' => RunStatus::Cancelled->value,
                    'cancelled_by' => $actor->id,
                    'cancelled_at' => Carbon::now(),
                    'cancellation_reason' => $reason,
                ]);

            if ($cancelled !== 1) {
                throw new DomainException('Payroll run left the reversible state during reversal; aborting (00-core 10.4).');
            }

            // Free the month: the items' active_month generated column
            // collapses to NULL. The payslips they carried stay readable
            // through their immutable snapshots.
            PayrollItem::query()
                ->where('payroll_run_id', $original->getKey())
                ->update(['is_cancelled' => true]);

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Payroll',
                auditableType: PayrollRun::class,
                auditableId: (int) $original->getKey(),
                after: [
                    'status' => RunStatus::Cancelled->value,
                    'reversal_run_id' => (int) $reversal->getKey(),
                    'reversal_entry_id' => $reversalEntryId,
                    'reason' => $reason,
                ],
                actor: $actor,
            );

            return $reversal;
        });
    }
}
