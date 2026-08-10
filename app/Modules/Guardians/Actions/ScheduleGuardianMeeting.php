<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Actions;

use App\Modules\Guardians\Domain\FollowUpStatus;
use App\Modules\Guardians\Domain\MeetingRequestedBy;
use App\Modules\Guardians\Domain\MeetingStatus;
use App\Modules\Guardians\Domain\MeetingType;
use App\Modules\Guardians\Models\GuardianMeeting;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Support\Facades\Gate;

/**
 * The write path for `GuardianMeeting`, which shipped schema-only in Phase 2
 * (07-students §7.8) - "no UI" was a deliberate scope cut at the time, not
 * an oversight, but it left individual parent-teacher/disciplinary/financial
 * meetings with nowhere to be scheduled from.
 */
final class ScheduleGuardianMeeting
{
    public function handle(
        int $guardianId,
        ?int $studentId,
        string $scheduledAt,
        MeetingType $type,
        MeetingRequestedBy $requestedBy,
        int $createdBy,
        ?string $location = null,
        ?string $agenda = null,
    ): GuardianMeeting {
        Gate::authorize(Permission::GuardiansManage->value);

        return GuardianMeeting::query()->create([
            'guardian_id' => $guardianId,
            'student_id' => $studentId,
            'scheduled_at' => $scheduledAt,
            'location' => $location,
            'meeting_type' => $type->value,
            'requested_by' => $requestedBy->value,
            'agenda' => $agenda,
            'follow_up_status' => FollowUpStatus::None->value,
            'status' => MeetingStatus::Scheduled->value,
            'created_by' => $createdBy,
        ]);
    }
}
