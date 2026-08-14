<?php

declare(strict_types=1);

namespace App\Modules\Activities\Actions;

use App\Modules\Activities\Domain\ActivityPermission;
use App\Modules\Activities\Domain\ActivityStatus;
use App\Modules\Activities\Domain\MembershipStatus;
use App\Modules\Activities\Models\Activity;
use App\Modules\Activities\Models\ActivityMembership;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Closes an activity and ends every live membership in the same
 * transaction, so a closed activity can never show "active" members. The
 * end date is today, clamped to each membership's own start so the
 * CHECK(ends_on >= starts_on) invariant holds even for a member enrolled
 * with a future start.
 *
 * One-way in the MVP: closing twice is refused rather than silently
 * idempotent, because the second close is almost always an operator on
 * the wrong row.
 */
final class CloseActivity
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(int $activityId, Actor $actor): Activity
    {
        Gate::authorize(ActivityPermission::MANAGE);

        return DB::transaction(function () use ($activityId, $actor): Activity {
            /** @var Activity $activity */
            $activity = Activity::query()->lockForUpdate()->findOrFail($activityId);

            if ($activity->status === ActivityStatus::Closed) {
                throw new DomainException("Activity '{$activity->name}' is already closed.");
            }

            $today = Carbon::today();
            $ended = 0;

            $liveMemberships = ActivityMembership::query()
                ->where('activity_id', $activityId)
                ->where('status', MembershipStatus::Active->value)
                ->lockForUpdate()
                ->get();

            foreach ($liveMemberships as $membership) {
                $endsOn = $today->copy();

                if ($endsOn->lessThan($membership->starts_on)) {
                    $endsOn = $membership->starts_on->copy();
                }

                $membership->fill([
                    'status' => MembershipStatus::Ended,
                    'ends_on' => $endsOn,
                ])->save();

                $ended++;
            }

            $activity->fill(['status' => ActivityStatus::Closed])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Activities',
                auditableType: Activity::class,
                auditableId: (int) $activity->getKey(),
                before: ['status' => ActivityStatus::Active->value],
                after: [
                    'status' => ActivityStatus::Closed->value,
                    'memberships_ended' => $ended,
                ],
                actor: $actor,
            );

            return $activity->refresh();
        });
    }
}
