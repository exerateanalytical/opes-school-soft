<?php

declare(strict_types=1);

namespace App\Modules\Academics\Actions;

use App\Modules\Academics\Models\Department;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class CreateDepartment
{
    public function handle(string $code, string $name, ?string $nameFr = null, ?int $headStaffId = null): Department
    {
        Gate::authorize(Permission::AcademicsManage->value);

        return DB::transaction(function () use ($code, $name, $nameFr, $headStaffId): Department {
            try {
                $department = Department::query()->create([
                    'code' => $code,
                    'name' => $name,
                    'name_fr' => $nameFr,
                    'head_staff_id' => $headStaffId,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'code' => 'A department with this code already exists.',
                ]);
            }

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Created,
                module: 'Academics',
                auditableType: Department::class,
                auditableId: (int) $department->getKey(),
                after: ['code' => $code, 'name' => $name, 'name_fr' => $nameFr],
                actor: $this->currentActor(),
            );

            return $department;
        });
    }

    private function currentActor(): Actor
    {
        // No textual reference to the Identity User model crosses this module
        // boundary; larastan resolves auth()->user() to it on its own.
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
