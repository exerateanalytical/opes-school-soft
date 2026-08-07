<?php

declare(strict_types=1);

namespace App\Modules\Academics\Actions;

use App\Modules\Academics\Models\ClassGroup;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CreateClassGroup
{
    public function handle(
        int $classLevelId,
        int $academicYearId,
        string $name,
        int $capacity,
        ?int $streamId = null,
        ?int $roomId = null,
        ?int $classTeacherStaffId = null,
        string $status = 'active',
    ): ClassGroup {
        Gate::authorize(Permission::AcademicsManage->value);

        if ($capacity <= 0) {
            throw new InvalidArgumentException('Class group capacity must be greater than zero.');
        }

        return DB::transaction(function () use (
            $classLevelId, $academicYearId, $name, $capacity, $streamId, $roomId, $classTeacherStaffId, $status
        ): ClassGroup {
            try {
                $classGroup = ClassGroup::query()->create([
                    'class_level_id' => $classLevelId,
                    'academic_year_id' => $academicYearId,
                    'name' => $name,
                    'capacity' => $capacity,
                    'stream_id' => $streamId,
                    'room_id' => $roomId,
                    'class_teacher_staff_id' => $classTeacherStaffId,
                    'status' => $status,
                ]);
            } catch (UniqueConstraintViolationException) {
                // The UNIQUE(academic_year_id, class_level_id, name) index is
                // the source of truth; translate its violation into something
                // a form can display instead of a raw SQL error.
                throw ValidationException::withMessages([
                    'name' => 'A class group with this name already exists for this level and academic year.',
                ]);
            }

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Created,
                module: 'Academics',
                auditableType: ClassGroup::class,
                auditableId: (int) $classGroup->getKey(),
                after: [
                    'name' => $name,
                    'class_level_id' => $classLevelId,
                    'academic_year_id' => $academicYearId,
                    'stream_id' => $streamId,
                    'capacity' => $capacity,
                    'status' => $status,
                ],
                actor: $this->currentActor(),
            );

            return $classGroup;
        });
    }

    private function currentActor(): Actor
    {
        // No textual reference to the Identity User model crosses this module
        // boundary; larastan resolves auth()->user() to it on its own.
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
