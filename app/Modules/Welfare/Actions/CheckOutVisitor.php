<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Welfare\Domain\VisitorPermission;
use App\Modules\Welfare\Models\VisitorLog;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/plans/phase-10.md §4 (W4). Front desk checks a visitor out.
 *
 * A visit is open exactly while checked_out_at is NULL, so a second
 * check-out is refused rather than silently rewriting the recorded exit
 * time - the register is history the moment the visitor walks out (the
 * exact double-close shape CloseReferral set). Checking out frees the
 * badge: the schema's active_badge_key falls to NULL and the badge can be
 * issued again.
 */
final class CheckOutVisitor
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(
        int $visitorLogId,
        Carbon $checkedOutAt,
        Actor $actor,
        ?string $gatePassNo = null,
    ): VisitorLog {
        Gate::authorize(VisitorPermission::MANAGE);

        return DB::transaction(function () use ($visitorLogId, $checkedOutAt, $gatePassNo, $actor): VisitorLog {
            /** @var VisitorLog $log */
            $log = VisitorLog::query()
                ->lockForUpdate()
                ->findOrFail($visitorLogId);

            if ($log->checked_out_at !== null) {
                throw new DomainException(
                    'This visitor already checked out at '
                    .$log->checked_out_at->toDateTimeString().'; the register is not rewritten.'
                );
            }

            if ($checkedOutAt->lessThan($log->checked_in_at)) {
                throw new DomainException('A visitor cannot check out before checking in.');
            }

            $log->fill([
                'checked_out_at' => $checkedOutAt,
                'gate_pass_no' => $gatePassNo !== null && trim($gatePassNo) !== ''
                    ? trim($gatePassNo)
                    : $log->gate_pass_no,
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Welfare',
                auditableType: VisitorLog::class,
                auditableId: (int) $log->getKey(),
                after: [
                    'visitor_name' => $log->visitor_name,
                    'badge_no' => $log->badge_no,
                    'checked_out_at' => $checkedOutAt->toDateTimeString(),
                ],
                actor: $actor,
            );

            return $log->refresh();
        });
    }
}
