<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Domain\AccountingPeriodStatus;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 02-accounting §5.2, the two-stage lock (C8):
 *
 *   open -> soft_locked   the ordinary "close the period" operation.
 *   soft_locked -> hard_locked   the AUDCIF Art. 22 clôture informatique;
 *     never reopened (§5.2) - a later correction is a reversal in a
 *     subsequently open period, never an unlock of this one.
 *
 * Takes the row `lockForUpdate()` (EXCLUSIVE) - this is the write side of
 * 00-core §11's "posting takes a shared lock; the locking Action takes it
 * exclusively", which closes the check-then-act window where an entry could
 * post between a status read and this write. See
 * AccountingPeriod::lockForPosting()/assertOpenForPosting() for the
 * matching read side, which is the contract Agent D3's PostJournalEntry
 * consumes.
 *
 * The full §5.3 forced-quarterly-closure sequence (escalating warnings, the
 * §17.9 trial-balance validation gating the hard lock) is a scheduled job
 * outside this Action's and this phase's scope; this Action is the
 * mechanical state transition an accountant or that job calls once its own
 * checks pass.
 */
final class CloseAccountingPeriod
{
    public const PERMISSION = Permission::LedgerConfigure->value;

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(int $periodId, Actor $actor, bool $hard = false): AccountingPeriod
    {
        Gate::authorize(self::PERMISSION);

        return DB::transaction(function () use ($periodId, $actor, $hard): AccountingPeriod {
            /** @var AccountingPeriod $period */
            $period = AccountingPeriod::query()->whereKey($periodId)->lockForUpdate()->firstOrFail();

            $before = ['status' => $period->status->value];
            // The Action already receives $actor precisely so it does not
            // need to reach for global auth state - Auth::id() also returns
            // int|string|null (a string primary key is possible in general),
            // where $actor->id is already the correct, already-validated
            // ?int this column expects.
            $userId = $actor->id;

            if (! $hard) {
                if ($period->status !== AccountingPeriodStatus::Open) {
                    throw new DomainException(sprintf(
                        'AccountingPeriod %d is %s; only an open period may be soft-locked.',
                        $periodId,
                        $period->status->value,
                    ));
                }

                $period->status = AccountingPeriodStatus::SoftLocked;
                $period->soft_locked_at = now();
                $period->soft_locked_by = $userId;
            } else {
                if ($period->status !== AccountingPeriodStatus::SoftLocked) {
                    throw new DomainException(sprintf(
                        'AccountingPeriod %d is %s; only a soft-locked period may be hard-locked. Soft-lock happens first (§5.2).',
                        $periodId,
                        $period->status->value,
                    ));
                }

                $period->status = AccountingPeriodStatus::HardLocked;
                $period->hard_locked_at = now();
                $period->hard_locked_by = $userId;
            }

            $period->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Accounting',
                auditableType: AccountingPeriod::class,
                auditableId: (int) $period->getKey(),
                before: $before,
                after: ['status' => $period->status->value],
                actor: $actor,
            );

            return $period;
        });
    }
}
