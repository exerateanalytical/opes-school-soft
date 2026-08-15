<?php

declare(strict_types=1);

namespace App\Modules\Activities\Actions;

use App\Modules\Activities\Domain\ActivityPermission;
use App\Modules\Activities\Domain\ActivityStatus;
use App\Modules\Activities\Domain\ConsentStatus;
use App\Modules\Activities\Domain\MembershipRole;
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
 * Enrols a student into an activity with a role and a start date.
 *
 *  - The student must exist - verified via DB::table('students'), never
 *    through the Students module's Models (ModuleBoundaryTest).
 *  - The activity must be ACTIVE; a closed activity accepts nobody.
 *  - No double-enrol: one ACTIVE membership per student per activity. The
 *    Action refuses with a DomainException, and the schema's NULL-unique
 *    active_key backs the same invariant against every other code path,
 *    so a race loses with a duplicate-key error instead of a silent
 *    second membership.
 *  - A set capacity is a hard ceiling, counted under lock.
 *  - On an EXCURSION the membership starts with consent_status = pending
 *    (gap-analysis row 15): the seat exists, the guardian's decision is
 *    explicitly still owed, and the pending-consents KPI counts it.
 *
 * No billing here: the MVP charges no activity fees, so nothing flows to
 * Accounting\Actions\PostFromEvent.
 */
final class EnrolStudent
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(
        int $activityId,
        int $studentId,
        MembershipRole $role,
        Carbon $startsOn,
        Actor $actor,
    ): ActivityMembership {
        Gate::authorize(ActivityPermission::MANAGE);

        return DB::transaction(function () use ($activityId, $studentId, $role, $startsOn, $actor): ActivityMembership {
            /** @var Activity $activity */
            $activity = Activity::query()->lockForUpdate()->findOrFail($activityId);

            if ($activity->status !== ActivityStatus::Active) {
                throw new DomainException(
                    "Activity '{$activity->name}' is closed and accepts no new members."
                );
            }

            /** @var object{id: int|string}|null $student */
            $student = DB::table('students')->where('id', $studentId)->first(['id']);

            if ($student === null) {
                throw new DomainException('The student does not exist.');
            }

            $alreadyEnrolled = ActivityMembership::query()
                ->where('activity_id', $activityId)
                ->where('student_id', $studentId)
                ->where('status', MembershipStatus::Active->value)
                ->lockForUpdate()
                ->exists();

            if ($alreadyEnrolled) {
                throw new DomainException(
                    "The student is already an active member of '{$activity->name}'."
                );
            }

            if ($activity->capacity !== null) {
                $activeCount = ActivityMembership::query()
                    ->where('activity_id', $activityId)
                    ->where('status', MembershipStatus::Active->value)
                    ->lockForUpdate()
                    ->count();

                if ($activeCount >= $activity->capacity) {
                    throw new DomainException(
                        "'{$activity->name}' is full ({$activity->capacity} members)."
                    );
                }
            }

            $membership = ActivityMembership::query()->create([
                'activity_id' => $activityId,
                'student_id' => $studentId,
                'role' => $role,
                'starts_on' => $startsOn,
                'status' => MembershipStatus::Active,
                // Row-15 tie-in: an excursion seat is born owing a guardian
                // decision; every other type carries no consent state.
                'consent_status' => $activity->type->isExcursion() ? ConsentStatus::Pending : null,
                'enrolled_by' => $actor->id,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Activities',
                auditableType: ActivityMembership::class,
                auditableId: (int) $membership->getKey(),
                after: [
                    'activity' => $activity->name,
                    'student_id' => $studentId,
                    'role' => $role->value,
                    'starts_on' => $startsOn->toDateString(),
                    'consent_status' => $membership->consent_status?->value,
                ],
                actor: $actor,
            );

            return $membership->refresh();
        });
    }
}
