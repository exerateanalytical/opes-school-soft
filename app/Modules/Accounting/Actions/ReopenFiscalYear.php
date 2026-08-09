<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Domain\AccountingPeriodStatus;
use App\Modules\Accounting\Domain\FiscalYearStatus;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 02-accounting §6 / 03-tax-procurement §7.5 - reopen a closing/closed
 * fiscal year (reason mandatory; the periods drop back from hard to soft
 * lock so the year-end Actions can correct and re-close).
 *
 * HARD DSF BLOCK (§7.5, §11.10): once `dsf_filed_at` is set the refusal
 * is UNCONDITIONAL. There is NO permission that overrides it and NO
 * `force` flag - do not add one. Reopening a year whose DSF has been
 * filed makes the filed statutory accounts differ from the books, which
 * is exactly the discrepancy a contrôle fiscal is designed to find. The
 * remedy for a genuine error is an amending declaration
 * (Tax\Actions\AmendTaxDeclaration) plus correcting entries in the
 * current open year - never a reopening. This is stated as an absolute
 * because the first support ticket will ask for the override.
 */
final class ReopenFiscalYear
{
    public const PERMISSION = Permission::LedgerConfigure->value;

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(int $fiscalYearId, string $reason, Actor $actor): FiscalYear
    {
        Gate::authorize(self::PERMISSION);

        if (trim($reason) === '') {
            throw new DomainException('Reopening a fiscal year requires a reason; it lands in the audit log.');
        }

        return DB::transaction(function () use ($fiscalYearId, $reason, $actor): FiscalYear {
            /** @var FiscalYear $fiscalYear */
            $fiscalYear = FiscalYear::query()->lockForUpdate()->findOrFail($fiscalYearId);

            // THE unconditional DSF guard - checked FIRST, before any
            // status logic, so no code path below can soften it.
            if ($fiscalYear->dsf_filed_at !== null) {
                throw new DomainException(sprintf(
                    'Fiscal year %s cannot be reopened: its DSF was filed on %s (ref %s). This refusal is unconditional - no permission or flag overrides it. File an AMENDING declaration and post correcting entries in the current open year instead (03-tax-procurement §7.5, §11.10).',
                    $fiscalYear->code,
                    $fiscalYear->dsf_filed_at->toDateString(),
                    (string) $fiscalYear->dsf_reference,
                ));
            }

            if (! in_array($fiscalYear->status, [FiscalYearStatus::Closing, FiscalYearStatus::Closed], true)) {
                throw new DomainException(sprintf(
                    'Fiscal year %s is %s; only a closing or closed year can be reopened.',
                    $fiscalYear->code,
                    $fiscalYear->status->value,
                ));
            }

            $before = ['status' => $fiscalYear->status->value];

            $fiscalYear->forceFill([
                'status' => FiscalYearStatus::Closing->value,
                'closed_at' => null,
                'closed_by' => null,
            ])->save();

            // Hard-locked periods drop back to soft lock: year-end Actions
            // (and elevated manual entries) can correct, operational
            // modules stay out (02-accounting §5.2).
            AccountingPeriod::query()
                ->where('fiscal_year_id', $fiscalYear->id)
                ->where('status', AccountingPeriodStatus::HardLocked->value)
                ->lockForUpdate()
                ->get()
                ->each(function (AccountingPeriod $period) use ($reason): void {
                    $period->forceFill([
                        'status' => AccountingPeriodStatus::SoftLocked->value,
                        'hard_locked_at' => null,
                        'hard_locked_by' => null,
                        'unlock_reason' => $reason,
                    ])->save();
                });

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Accounting',
                auditableType: FiscalYear::class,
                auditableId: (int) $fiscalYear->getKey(),
                before: $before,
                after: ['status' => FiscalYearStatus::Closing->value, 'reason' => trim($reason)],
                actor: $actor,
            );

            return $fiscalYear->refresh();
        });
    }
}
