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

final class UpdateClassGroup
{
    /**
     * @param  array{name?: string, capacity?: int, stream_id?: int|null, room_id?: int|null, class_teacher_staff_id?: int|null, status?: string}  $attributes
     */
    public function handle(ClassGroup $classGroup, array $attributes): ClassGroup
    {
        Gate::authorize(Permission::AcademicsManage->value);

        if (array_key_exists('capacity', $attributes) && $attributes['capacity'] <= 0) {
            throw new InvalidArgumentException('Class group capacity must be greater than zero.');
        }

        return DB::transaction(function () use ($classGroup, $attributes): ClassGroup {
            $before = array_intersect_key($classGroup->getOriginal(), $attributes);

            try {
                $classGroup->fill($attributes)->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'name' => 'A class group with this name already exists for this level and academic year.',
                ]);
            }

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Updated,
                module: 'Academics',
                auditableType: ClassGroup::class,
                auditableId: (int) $classGroup->getKey(),
                before: $before,
                after: $attributes,
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
