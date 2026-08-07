<?php

declare(strict_types=1);

namespace App\Modules\Academics\Actions;

use App\Modules\Academics\Models\Room;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CreateRoom
{
    public function handle(
        string $code,
        string $name,
        int $capacity,
        ?string $building = null,
        string $type = 'classroom',
    ): Room {
        Gate::authorize(Permission::AcademicsManage->value);

        if ($capacity <= 0) {
            throw new InvalidArgumentException('Room capacity must be greater than zero.');
        }

        return DB::transaction(function () use ($code, $name, $capacity, $building, $type): Room {
            try {
                $room = Room::query()->create([
                    'code' => $code,
                    'name' => $name,
                    'capacity' => $capacity,
                    'building' => $building,
                    'type' => $type,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'code' => 'A room with this code already exists.',
                ]);
            }

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Created,
                module: 'Academics',
                auditableType: Room::class,
                auditableId: (int) $room->getKey(),
                after: ['code' => $code, 'name' => $name, 'capacity' => $capacity, 'type' => $type],
                actor: $this->currentActor(),
            );

            return $room;
        });
    }

    private function currentActor(): Actor
    {
        // No textual reference to the Identity User model crosses this module
        // boundary; larastan resolves auth()->user() to it on its own.
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
