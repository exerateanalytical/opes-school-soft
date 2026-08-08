<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use App\Support\Clock\BusinessDate;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Closes a leaf assessment period: entry stops, the grid is frozen for
 * composition (docs/specs/01-assessment.md 4.1, 7.6).
 *
 * Closing does NOT delete or resolve pending marks. A `pending` row survives
 * the close and is what the publication gate reports on - 6.4's
 * `missing_component_policy` decides what happens to it at publication, and
 * that decision belongs to the framework, not to whoever happened to press
 * "close". Silently zeroing them here would turn a data-entry gap into a
 * child's zero with no audit trail saying who chose that.
 */
final class CloseAssessmentPeriod
{
    /** See CreateFramework::PERMISSION. */
    public const PERMISSION = CreateFramework::PERMISSION;

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @return int the number of marks still `pending` at close - reported, not acted on
     */
    public function handle(int $assessmentPeriodId, Actor $actor): int
    {
        Gate::authorize(self::PERMISSION);

        return DB::transaction(function () use ($assessmentPeriodId, $actor): int {
            $period = DB::table('assessment_periods')
                ->where('id', $assessmentPeriodId)
                ->lockForUpdate()
                ->first();

            if ($period === null) {
                throw new DomainException(sprintf('Assessment period %d does not exist.', $assessmentPeriodId));
            }

            if ((string) $period->status === 'closed') {
                throw new DomainException(sprintf(
                    'Period `%s` is already closed.',
                    (string) $period->code,
                ));
            }

            $pending = (int) DB::table('marks')
                ->where('assessment_period_id', $assessmentPeriodId)
                ->where('state', 'pending')
                ->count();

            // The window is pulled shut as well as the status changed. Leaving
            // a closing time in the future on a closed period would let an
            // entry screen that checks only the window keep writing.
            DB::table('assessment_periods')->where('id', $assessmentPeriodId)->update([
                'status' => 'closed',
                'marks_entry_closes_at' => Carbon::now(BusinessDate::TIMEZONE)->format('Y-m-d H:i:s'),
                'updated_at' => now(),
            ]);

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Assessment',
                auditableType: 'assessment_periods',
                auditableId: $assessmentPeriodId,
                before: ['status' => (string) $period->status],
                after: ['status' => 'closed', 'pending_marks_at_close' => $pending],
                actor: $actor,
            );

            return $pending;
        });
    }
}
