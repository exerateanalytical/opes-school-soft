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

final class CreateSubject
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(
        string $code,
        string $name,
        ?string $nameFr = null,
        ?int $departmentId = null,
        bool $isActive = true,
    ): Subject {
        Gate::authorize(Permission::AcademicsManage->value);

        return DB::transaction(function () use ($code, $name, $nameFr, $departmentId, $isActive): Subject {
            $subject = Subject::query()->create([
                'code' => $code,
                'name' => $name,
                'name_fr' => $nameFr,
                'department_id' => $departmentId,
                'is_active' => $isActive,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Academics',
                auditableType: Subject::class,
                auditableId: (int) $subject->getKey(),
                after: [
                    'code' => $code,
                    'name' => $name,
                    'name_fr' => $nameFr,
                    'department_id' => $departmentId,
                    'is_active' => $isActive,
                ],
                actor: auth()->user()?->toAuditActor() ?? Actor::system(),
            );

            return $subject;
        });
    }
}
