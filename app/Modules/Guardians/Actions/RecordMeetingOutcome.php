<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Actions;

use App\Modules\Guardians\Domain\FollowUpStatus;
use App\Modules\Guardians\Domain\MeetingStatus;
use App\Modules\Guardians\Models\GuardianMeeting;
use App\Modules\Identity\Domain\Permission;
use DomainException;
use Illuminate\Support\Facades\Gate;

/**
 * Records a meeting as held, with its minutes and decisions - or as
 * cancelled/no-show, which needs no minutes.
 */
final class RecordMeetingOutcome
{
    public function handle(
        int $meetingId,
        MeetingStatus $status,
        ?string $minutes = null,
        ?string $decisions = null,
        ?string $followUpAction = null,
        ?string $followUpDueOn = null,
    ): GuardianMeeting {
        Gate::authorize(Permission::GuardiansManage->value);

        /** @var GuardianMeeting $meeting */
        $meeting = GuardianMeeting::query()->findOrFail($meetingId);

        if ($meeting->status !== MeetingStatus::Scheduled) {
            throw new DomainException("Meeting {$meetingId} already has an outcome recorded.");
        }

        if ($status === MeetingStatus::Held && ($minutes === null || trim($minutes) === '')) {
            throw new DomainException('A meeting recorded as held needs minutes.');
        }

        $meeting->forceFill([
            'status' => $status->value,
            'held_at' => $status === MeetingStatus::Held ? now() : null,
            'minutes' => $minutes,
            'decisions' => $decisions,
            'follow_up_action' => $followUpAction,
            'follow_up_due_on' => $followUpDueOn,
            'follow_up_status' => $followUpAction !== null ? FollowUpStatus::Open->value : FollowUpStatus::None->value,
        ])->save();

        return $meeting->refresh();
    }
}
