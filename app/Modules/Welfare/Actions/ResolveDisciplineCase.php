<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Welfare\Domain\DisciplineCaseStatus;
use App\Modules\Welfare\Models\DisciplineCase;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Moves a case along its lifecycle: open -> under_investigation ->
 * resolved | dismissed (DisciplineCaseStatus::allowedNext, enforced here,
 * not in the UI).
 *
 * `dismissed` exists so "we investigated and it was baseless" is a stated
 * outcome the read door EXCLUDES from promotion counts — deleting the row
 * instead would erase the investigation, and counting it would punish an
 * exonerated student.
 */
final class ResolveDisciplineCase
{
    public function handle(
        int $caseId,
        DisciplineCaseStatus $to,
        ?string $note = null,
    ): DisciplineCase {
        Gate::authorize(Permission::DisciplineManage->value);

        if ($to === DisciplineCaseStatus::Open) {
            throw ValidationException::withMessages([
                'status' => 'A case cannot be moved back to open.',
            ]);
        }

        if ($to->isTerminal() && trim((string) $note) === '') {
            throw ValidationException::withMessages([
                'resolution_note' => 'Closing a case must record the outcome.',
            ]);
        }

        return DB::transaction(function () use ($caseId, $to, $note): DisciplineCase {
            /** @var DisciplineCase $case */
            $case = DisciplineCase::query()->lockForUpdate()->findOrFail($caseId);

            $from = $case->status;

            if (! $from->canTransitionTo($to)) {
                throw ValidationException::withMessages([
                    'status' => "A case that is {$from->value} cannot become {$to->value}.",
                ]);
            }

            $actor = $this->currentActor();

            $case->status = $to;

            if ($to->isTerminal()) {
                $case->resolved_at = now();
                $case->resolved_by = $actor->id;
                $case->resolution_note = $note;
            }

            $case->save();

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Updated,
                module: 'Welfare',
                auditableType: DisciplineCase::class,
                auditableId: $caseId,
                before: ['status' => $from->value],
                after: ['status' => $to->value, 'resolution_note' => $note],
                actor: $actor,
            );

            return $case;
        });
    }

    private function currentActor(): Actor
    {
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
