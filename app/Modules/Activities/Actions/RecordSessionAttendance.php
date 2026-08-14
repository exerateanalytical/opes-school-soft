<?php

declare(strict_types=1);

namespace App\Modules\Activities\Actions;

use App\Modules\Activities\Domain\ActivityPermission;
use App\Modules\Activities\Domain\SessionAttendanceStatus;
use App\Modules\Activities\Models\ActivityAttendance;
use App\Modules\Activities\Models\ActivityMembership;
use App\Modules\Activities\Models\ActivitySession;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Records (or re-records) the register for one session: a map of
 * membership id => present|absent|excused.
 *
 * Every named membership must belong to the session's own activity - a
 * mark for a member of a different club is a keying error, refused whole:
 * the transaction rolls back so a register is never half-written.
 * Re-recording is an update by UNIQUE(session_id, membership_id), so
 * correcting a mark leaves one row, not two.
 */
final class RecordSessionAttendance
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  array<int, string>  $marks  membership id => status value
     * @return int number of marks written
     */
    public function handle(int $sessionId, array $marks, Actor $actor): int
    {
        Gate::authorize(ActivityPermission::MANAGE);

        if ($marks === []) {
            throw ValidationException::withMessages([
                'marks' => 'A register requires at least one mark.',
            ]);
        }

        /** @var array<int, SessionAttendanceStatus> $parsed */
        $parsed = [];

        foreach ($marks as $membershipId => $statusValue) {
            $status = SessionAttendanceStatus::tryFrom((string) $statusValue);

            if ($status === null) {
                throw ValidationException::withMessages([
                    'marks' => "'{$statusValue}' is not a valid attendance status.",
                ]);
            }

            $parsed[(int) $membershipId] = $status;
        }

        return DB::transaction(function () use ($sessionId, $parsed, $actor): int {
            /** @var ActivitySession $session */
            $session = ActivitySession::query()->lockForUpdate()->findOrFail($sessionId);

            $membershipIds = array_keys($parsed);

            $known = ActivityMembership::query()
                ->whereIn('id', $membershipIds)
                ->where('activity_id', $session->activity_id)
                ->pluck('id');

            $knownIds = array_map(static fn ($id): int => (int) $id, $known->all());

            foreach ($membershipIds as $membershipId) {
                if (! in_array($membershipId, $knownIds, true)) {
                    throw new DomainException(
                        "Membership {$membershipId} does not belong to this session's activity."
                    );
                }
            }

            $written = 0;

            foreach ($parsed as $membershipId => $status) {
                ActivityAttendance::query()->updateOrCreate(
                    ['session_id' => $sessionId, 'membership_id' => $membershipId],
                    ['status' => $status, 'recorded_by' => $actor->id],
                );

                $written++;
            }

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Activities',
                auditableType: ActivitySession::class,
                auditableId: $sessionId,
                after: [
                    'register_marks' => $written,
                    'present' => count(array_filter($parsed, static fn (SessionAttendanceStatus $s): bool => $s === SessionAttendanceStatus::Present)),
                    'absent' => count(array_filter($parsed, static fn (SessionAttendanceStatus $s): bool => $s === SessionAttendanceStatus::Absent)),
                    'excused' => count(array_filter($parsed, static fn (SessionAttendanceStatus $s): bool => $s === SessionAttendanceStatus::Excused)),
                ],
                actor: $actor,
            );

            return $written;
        });
    }
}
