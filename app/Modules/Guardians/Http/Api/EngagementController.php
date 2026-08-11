<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Http\Api;

use App\Modules\Guardians\Actions\AcknowledgeSanctionAsGuardian;
use App\Modules\Guardians\Actions\RequestGuardianMeeting;
use App\Modules\Guardians\Domain\MeetingType;
use App\Modules\Guardians\Support\PortalContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Slice E - the two writes a guardian makes ABOUT A CHILD rather than about
 * themselves: requesting a meeting (row 27) and acknowledging a sanction
 * (row 21).
 *
 * Both are thin to the point of transparency, because both rules - the matrix
 * check, the ownership conjunct, the audit entry - live in the Actions. That
 * is the whole architectural point (00-core §6.1): a controller that decided
 * anything here would be a second implementation of a rule the portal screens
 * also need.
 */
final class EngagementController
{
    public function __construct(
        private readonly RequestGuardianMeeting $meetings,
        private readonly AcknowledgeSanctionAsGuardian $acknowledgements,
    ) {
    }

    /**
     * `POST /v1/me/children/{student}/meetings` - row 27, needs `has_custody`.
     *
     * The time posted is a PREFERENCE, not a booking: `requested_by =
     * guardian` is what tells the office to treat it as an ask. See
     * RequestGuardianMeeting for why this does not reuse the staff Action.
     */
    public function requestMeeting(Request $request, int $student): JsonResponse
    {
        $context = $this->context();

        $validated = $request->validate([
            'preferred_at' => ['required', 'date', 'after:now'],
            'meeting_type' => ['sometimes', 'string', 'in:parent_teacher,disciplinary,financial,admission,other'],
            'agenda' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $meeting = $this->meetings->handle(
            guardianId: (int) $context->guardian->getKey(),
            studentId: $student,
            preferredAt: (string) $validated['preferred_at'],
            type: MeetingType::from($validated['meeting_type'] ?? MeetingType::ParentTeacher->value),
            agenda: $validated['agenda'] ?? null,
            createdBy: $this->userId(),
            actor: auth()->user()?->toAuditActor(),
        );

        return response()->json([
            'data' => [
                'id' => (int) $meeting->getKey(),
                'student_id' => $student,
                'requested_at' => $meeting->scheduled_at,
                'status' => $meeting->status->value,
                'requested_by' => 'guardian',
            ],
        ], 201);
    }

    /**
     * `POST /v1/me/children/{student}/sanctions/{sanction}/ack` - row 21.
     *
     * A second acknowledgement is refused by the Welfare writer with a 422, not
     * swallowed: WHEN a parent signed is evidentiary, and silently rewriting
     * the timestamp would destroy that.
     */
    public function acknowledgeSanction(int $student, int $sanction): JsonResponse
    {
        $this->acknowledgements->handle($student, $sanction, auth()->user()?->toAuditActor());

        return response()->json([
            'data' => ['sanction_id' => $sanction, 'acknowledged' => true],
        ]);
    }

    private function context(): PortalContext
    {
        $context = PortalContext::current();

        if ($context === null) {
            abort(403);
        }

        return $context;
    }

    private function userId(): int
    {
        $id = auth()->id();

        if ($id === null) {
            abort(401);
        }

        return (int) $id;
    }
}
