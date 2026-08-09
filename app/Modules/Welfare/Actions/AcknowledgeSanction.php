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
 * Refuses a second acknowledgement rather than silently rewriting the
 * timestamp: WHEN the guardian signed is evidentiary.
 */
final class AcknowledgeSanction
{
    public function handle(int $sanctionId): DisciplineSanction
    {
        Gate::authorize(Permission::DisciplineManage->value);

        return DB::transaction(function () use ($sanctionId): DisciplineSanction {
            /** @var DisciplineSanction $sanction */
            $sanction = DisciplineSanction::query()->lockForUpdate()->findOrFail($sanctionId);

            if ($sanction->acknowledged_at !== null) {
                throw ValidationException::withMessages([
                    'sanction_id' => 'This sanction was already acknowledged on '
                        .$sanction->acknowledged_at->toDateString().'.',
                ]);
            }

            $actor = $this->currentActor();

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
