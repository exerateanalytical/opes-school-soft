<?php

declare(strict_types=1);

namespace App\Modules\Academics\Actions;

use App\Modules\Academics\Models\House;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class CreateHouse
{
    public function handle(string $code, string $name, string $colour, ?int $patronStaffId = null): House
    {
        Gate::authorize(Permission::AcademicsManage->value);

        return DB::transaction(function () use ($code, $name, $colour, $patronStaffId): House {
            try {
                $house = House::query()->create([
                    'code' => $code,
                    'name' => $name,
                    'colour' => $colour,
                    'patron_staff_id' => $patronStaffId,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'code' => 'A house with this code already exists.',
                ]);
            }

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Created,
                module: 'Academics',
                auditableType: House::class,
                auditableId: (int) $house->getKey(),
                after: ['code' => $code, 'name' => $name, 'colour' => $colour],
                actor: $this->currentActor(),
            );

            return $house;
        });
    }

    private function currentActor(): Actor
    {
        // No textual reference to the Identity User model crosses this module
        // boundary; larastan resolves auth()->user() to it on its own.
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
