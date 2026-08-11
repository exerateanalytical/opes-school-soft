<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Actions;

use App\Modules\Guardians\Domain\FollowUpStatus;
use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Domain\MeetingRequestedBy;
use App\Modules\Guardians\Domain\MeetingStatus;
use App\Modules\Guardians\Domain\MeetingType;
use App\Modules\Guardians\Models\GuardianMeeting;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Row 27: a guardian ASKING for a meeting about their own child.
 *
 * Deliberately NOT a call into ScheduleGuardianMeeting, and not a fork of it
 * either - they are different operations that happen to write the same table:
 *
 *   ScheduleGuardianMeeting  the school BOOKS a meeting. Gated on
 *                            `guardians.manage`, a staff permission. The time
 *                            it is given is a commitment: a room, a teacher.
 *   this class               a parent REQUESTS one. Gated on matrix row 27,
 *                            which needs `has_custody`. The time given is a
 *                            preference, and `requested_by = guardian` is what
 *                            tells the office the difference.
 *
 * Reusing the staff Action would have required granting a parent
 * `guardians.manage` - the permission that also edits authorization flags,
 * which is row 30, which is granted to nobody. That is not a reuse, it is a
 * privilege escalation wearing a reuse's clothes.
 *
 * The row lands as `scheduled` because the schema has no `requested` state;
 * `requested_by` carries the distinction, and the office confirms or cancels
 * it through the existing staff screens. Adding a status case is a 07-students
 * §7.8 decision, not one this surface may take unilaterally.
 */
final class RequestGuardianMeeting
{
    public function __construct(private readonly GuardianPortalPolicy $policy)
    {
    }

    public function handle(
        int $guardianId,
        int $studentId,
        string $preferredAt,
        MeetingType $type,
        ?string $agenda = null,
        ?int $createdBy = null,
        ?Actor $actor = null,
    ): GuardianMeeting {
        // Row 32 before anything else.
        if (! $this->policy->allows(GuardianCapability::R01ViewChildIdentity, $studentId)) {
            throw new NotFoundHttpException();
        }

        $this->policy->authorize(GuardianCapability::R27RequestGuardianMeeting, $studentId);

        $meeting = GuardianMeeting::query()->create([
            'guardian_id' => $guardianId,
            'student_id' => $studentId,
            'scheduled_at' => $preferredAt,
            'meeting_type' => $type->value,
            'requested_by' => MeetingRequestedBy::Guardian->value,
            'agenda' => $agenda,
            'follow_up_status' => FollowUpStatus::None->value,
            'status' => MeetingStatus::Scheduled->value,
            'created_by' => $createdBy,
        ]);

        app(WriteAuditEntry::class)->handle(
            action: AuditAction::Created,
            module: 'Guardians',
            auditableType: GuardianMeeting::class,
            auditableId: (int) $meeting->getKey(),
            after: [
                'guardian_id' => $guardianId,
                'student_id' => $studentId,
                'scheduled_at' => $preferredAt,
                'requested_by' => MeetingRequestedBy::Guardian->value,
            ],
            actor: $actor,
        );

        return $meeting;
    }
}
