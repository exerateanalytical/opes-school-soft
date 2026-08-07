<?php

declare(strict_types=1);

namespace App\Modules\Students\Actions;

use App\Modules\Students\Domain\StudentActivityEvent;
use App\Modules\Students\Models\StudentActivityLog;
use App\Support\Audit\Actor;
use Illuminate\Support\Carbon;

/**
 * The writer of docs/specs/07-students.md 8.3 activity rows.
 *
 * Published as an Action rather than left to each module's own INSERT because
 * 00-core 6.2 rule 2 makes an Action the only legal cross-module entry point:
 * Fees writes `invoice_issued`, Assessment writes `marks_published`, Welfare
 * writes `sanction_applied`, and none of them may touch this module's Models.
 *
 * Deliberately NOT permission-gated. It is a side effect of an Action that has
 * already authorized - gating it again would mean a bursar with fee.collect
 * but no students.manage silently drops the `payment_received` entry, and a
 * child's history would then depend on who happened to take the payment.
 * There is no route to it and no way to call it except from another Action.
 *
 * Deliberately NOT wrapped in its own transaction: it is called from inside
 * the caller's, and the entry must roll back with the event it describes.
 */
final class LogStudentActivity
{
    public function handle(
        int $studentId,
        StudentActivityEvent $event,
        string $summary,
        ?int $enrollmentId = null,
        ?string $relatedType = null,
        ?int $relatedId = null,
        ?Actor $actor = null,
        ?Carbon $occurredAt = null,
    ): StudentActivityLog {
        $actor ??= $this->currentActor();

        return StudentActivityLog::query()->create([
            'student_id' => $studentId,
            'enrollment_id' => $enrollmentId,
            'event' => $event,
            // The column is VARCHAR(255) and this is a human-readable line in
            // a paginated list, not a payload. Truncate rather than let a
            // caller's long string abort the transaction that carries the
            // real work.
            'summary' => mb_substr($summary, 0, 255),
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'actor_id' => $actor->id,
            'actor_name_at_time' => $actor->name,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }

    private function currentActor(): Actor
    {
        // No textual reference to the Identity User model crosses this module
        // boundary; larastan resolves auth()->user() to it on its own.
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
