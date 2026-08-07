<?php

declare(strict_types=1);

namespace App\Modules\Academics\Actions;

use App\Modules\Academics\Models\Subject;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class UpdateSubject
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @param  array{code?: string, name?: string, name_fr?: string|null, department_id?: int|null, is_active?: bool}  $changes
     */
    public function handle(Subject $subject, array $changes): Subject
    {
        Gate::authorize(Permission::AcademicsManage->value);

        return DB::transaction(function () use ($subject, $changes): Subject {
            $before = array_intersect_key($subject->getOriginal(), $changes);

            $subject->fill($changes);
            $after = $subject->getDirty();
            $subject->save();

            if ($after !== []) {
                $this->audit->handle(
                    action: AuditAction::Updated,
                    module: 'Academics',
                    auditableType: Subject::class,
                    auditableId: (int) $subject->getKey(),
                    before: array_intersect_key($before, $after),
                    after: $after,
                    actor: auth()->user()?->toAuditActor() ?? Actor::system(),
                );
            }

            return $subject;
        });
    }
}
