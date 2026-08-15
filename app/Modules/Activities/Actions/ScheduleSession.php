<?php

declare(strict_types=1);

namespace App\Modules\Activities\Actions;

use App\Modules\Activities\Domain\ActivityPermission;
use App\Modules\Activities\Domain\ActivityStatus;
use App\Modules\Activities\Models\Activity;
use App\Modules\Activities\Models\ActivitySession;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Schedules one occurrence of an activity - date, optional start/end
 * times, venue, and an optional staff supervisor whose existence is
 * verified via DB::table('staff_members') (never the HR module's Models -
 * ModuleBoundaryTest). Only a running activity can gain sessions.
 */
final class ScheduleSession
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(int $activityId, array $data, Actor $actor): ActivitySession
    {
        Gate::authorize(ActivityPermission::MANAGE);

        $scheduledRaw = trim((string) ($data['scheduled_on'] ?? ''));

        if ($scheduledRaw === '') {
            throw ValidationException::withMessages([
                'scheduled_on' => 'A session requires a date.',
            ]);
        }

        $scheduledOn = Carbon::parse($scheduledRaw);

        $startsAt = $this->timeOrNull($data['starts_at'] ?? null);
        $endsAt = $this->timeOrNull($data['ends_at'] ?? null);

        if ($startsAt !== null && $endsAt !== null && $endsAt < $startsAt) {
            throw ValidationException::withMessages([
                'ends_at' => 'The session cannot end before it starts.',
            ]);
        }

        $supervisorId = ($data['supervisor_id'] ?? null) !== null && (string) $data['supervisor_id'] !== ''
            ? (int) $data['supervisor_id']
            : null;

        return DB::transaction(function () use ($activityId, $data, $scheduledOn, $startsAt, $endsAt, $supervisorId, $actor): ActivitySession {
            /** @var Activity $activity */
            $activity = Activity::query()->lockForUpdate()->findOrFail($activityId);

            if ($activity->status !== ActivityStatus::Active) {
                throw new DomainException(
                    "Activity '{$activity->name}' is closed; no further sessions can be scheduled."
                );
            }

            if ($supervisorId !== null) {
                $supervisorExists = DB::table('staff_members')->where('id', $supervisorId)->exists();

                if (! $supervisorExists) {
                    throw new DomainException('The named supervisor does not exist.');
                }
            }

            $venue = trim((string) ($data['venue'] ?? ''));
            $notes = trim((string) ($data['notes'] ?? ''));

            $session = ActivitySession::query()->create([
                'activity_id' => $activityId,
                'scheduled_on' => $scheduledOn,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'venue' => $venue === '' ? null : $venue,
                'supervisor_id' => $supervisorId,
                'notes' => $notes === '' ? null : $notes,
                'created_by' => $actor->id,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Activities',
                auditableType: ActivitySession::class,
                auditableId: (int) $session->getKey(),
                after: [
                    'activity' => $activity->name,
                    'scheduled_on' => $scheduledOn->toDateString(),
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'supervisor_id' => $supervisorId,
                ],
                actor: $actor,
            );

            return $session->refresh();
        });
    }

    private function timeOrNull(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }
}
