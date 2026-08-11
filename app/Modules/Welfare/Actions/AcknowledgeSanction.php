<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Welfare\Models\DisciplineSanction;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Records the guardian's acknowledgement of a sanction (docs/specs/
 * 07-students.md §8 row 21; 10-documents DISC guardian signature line).
 *
 * v1 scope: the Discipline Master records that the signed slip came back —
 * hence the discipline.manage gate. When the guardian portal (Phase 12)
 * gains its own acknowledge button it will call THIS action inside a
 * portal-scoped wrapper, so the timestamp has exactly one writer.
 *
 * That wrapper now exists — Guardians\Actions\AcknowledgeSanctionAsGuardian,
 * row 21 of the 7.5 matrix — so the promise above is kept literally: the staff
 * gate moved OUT of the writer into handle(), and the wrapper calls
 * handleAuthorized() after its own matrix check. Two authorization paths, each
 * explicit about the authority it carries; still exactly ONE piece of code
 * that stamps `acknowledged_at`, writes the audit entry and refuses a repeat.
 * A guardian will never hold `discipline.manage`, so the alternative was a
 * fork — and a forked evidentiary timestamp is two different answers to "when
 * did the parent sign".
 *
 * Refuses a second acknowledgement rather than silently rewriting the
 * timestamp: WHEN the guardian signed is evidentiary.
 */
final class AcknowledgeSanction
{
    /** The staff door: the Discipline Master logging a returned slip. */
    public function handle(int $sanctionId): DisciplineSanction
    {
        Gate::authorize(Permission::DisciplineManage->value);

        return $this->handleAuthorized($sanctionId);
    }

    /**
     * The write itself, for a caller that has ALREADY established its own
     * authority over this sanction. Carries no gate of its own, deliberately:
     * the two callers do not share a gate to carry. Never call it without
     * having authorized first.
     */
    public function handleAuthorized(int $sanctionId, ?Actor $actor = null): DisciplineSanction
    {
        return DB::transaction(function () use ($sanctionId, $actor): DisciplineSanction {
            /** @var DisciplineSanction $sanction */
            $sanction = DisciplineSanction::query()->lockForUpdate()->findOrFail($sanctionId);

            if ($sanction->acknowledged_at !== null) {
                throw ValidationException::withMessages([
                    'sanction_id' => 'This sanction was already acknowledged on '
                        .$sanction->acknowledged_at->toDateString().'.',
                ]);
            }

            $actor ??= $this->currentActor();

            $sanction->acknowledged_at = now();
            $sanction->save();

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Updated,
                module: 'Welfare',
                auditableType: DisciplineSanction::class,
                auditableId: $sanctionId,
                before: ['acknowledged_at' => null],
                after: ['acknowledged_at' => $sanction->acknowledged_at->toDateTimeString()],
                actor: $actor,
            );

            return $sanction;
        });
    }

    private function currentActor(): Actor
    {
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
