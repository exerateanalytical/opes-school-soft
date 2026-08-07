<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class CreateUser
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(string $name, string $email, Role $role, string $plainPassword, User $actor): User
    {
        if (! $actor->can(Permission::UserManage->value)) {
            throw new AuthorizationException('You do not have permission to create users.');
        }

        return DB::transaction(function () use ($name, $email, $role, $plainPassword, $actor): User {
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $plainPassword, // 'hashed' cast applies argon2id
                'status' => 'active',
            ]);

            $user->assignRole($role->value);

            // name, email and role - never the password. An audit log that
            // records credentials is a credential store, sitting in the one
            // table auditors are meant to read.
            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Identity',
                auditableType: User::class,
                auditableId: (int) $user->getKey(),
                after: ['name' => $name, 'email' => $email, 'role' => $role->value],
                actor: $actor->toAuditActor(),
            );

            return $user;
        });
    }
}
