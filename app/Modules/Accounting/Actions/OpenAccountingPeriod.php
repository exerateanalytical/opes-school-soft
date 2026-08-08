<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Domain\AccountingPeriodStatus;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\Role;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 02-accounting §5.2, unlocking:
 *
 *   soft_locked -> open          requires ledger.configure, a reason.
 *   hard_locked -> soft_locked   requires Super Admin, a reason, and is
 *                                REFUSED if the fiscal year has
 *                                `dsf_filed_at` set.
 *
 * Quarterly closures rendered definitive under Art. 22 are hard locks and
 * are described by §5.2 as "never reopened" in the ordinary course; the
 * Super-Admin path exists for the documented emergency case (a filing
 * error caught before DSF submission) and is the reason it is gated so
 * much harder than the soft-unlock.
 */
final class OpenAccountingPeriod
{
    public const PERMISSION = Permission::LedgerConfigure->value;

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(int $periodId, string $reason, Actor $actor): AccountingPeriod
    {
        Gate::authorize(self::PERMISSION);

        if (trim($reason) === '') {
            throw new DomainException('Unlocking an accounting period requires a reason.');
        }

        return DB::transaction(function () use ($periodId, $reason, $actor): AccountingPeriod {
            /** @var AccountingPeriod $period */
            $period = AccountingPeriod::query()->whereKey($periodId)->lockForUpdate()->firstOrFail();

            $before = ['status' => $period->status->value];

            if ($period->status === AccountingPeriodStatus::SoftLocked) {
                $period->status = AccountingPeriodStatus::Open;
                $period->soft_locked_at = null;
                $period->soft_locked_by = null;
            } elseif ($period->status === AccountingPeriodStatus::HardLocked) {
                // Actor (App\Support\Audit\Actor) is an id+name value object
                // with no role information by design - it exists so Actions
                // never have to import Identity's User model across a module
                // boundary. This one check genuinely needs a real role, so
                // it is the one place in this Action that reaches for the
                // authenticated user directly rather than trusting $actor.
                $user = Auth::user();

                if ($user === null || ! $user->hasRole(Role::SuperAdmin->value)) {
                    throw new AuthorizationException('Only Super Admin may unlock a hard-locked accounting period.');
                }

                /** @var FiscalYear $fiscalYear */
                $fiscalYear = FiscalYear::query()->findOrFail($period->fiscal_year_id);

                if ($fiscalYear->dsf_filed_at !== null) {
                    throw new DomainException(
                        'This period cannot be unlocked: its fiscal year has already been filed with the DSF (dsf_filed_at is set).'
                    );
                }

                $period->status = AccountingPeriodStatus::SoftLocked;
                $period->hard_locked_at = null;
                $period->hard_locked_by = null;
            } else {
                throw new DomainException(sprintf(
                    'AccountingPeriod %d is already open.',
                    $periodId,
                ));
            }

            $period->unlock_reason = $reason;
            $period->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Accounting',
                auditableType: AccountingPeriod::class,
                auditableId: (int) $period->getKey(),
                before: $before,
                after: ['status' => $period->status->value, 'unlock_reason' => $reason],
                actor: $actor,
            );

            return $period;
        });
    }
}
