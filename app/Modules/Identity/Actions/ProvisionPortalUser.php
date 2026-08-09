<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Identity's door for portal-account provisioning (Phase 12,
 * docs/plans/phase-12-13.md 12.2). Guardians\Actions\ActivatePortalAccount
 * calls this instead of touching the User model, which
 * tests/Architecture/ModuleBoundaryTest.php forbids it to import.
 *
 * Unlike CreateUser this takes NO permission gate: the caller's authority is
 * the invitation code it has already verified, and portal activation happens
 * before any session exists. What replaces the gate is a hard ceiling on
 * what can be provisioned: only the two portal roles, which the seeder grants
 * exactly `portal.access` and nothing else. A stolen or forged call can mint
 * a portal shell account, never an operational one - staff accounts still go
 * through CreateUser under `user.manage`.
 */
final class ProvisionPortalUser
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(string $name, string $email, Role $role, string $plainPassword): User
    {
        if (! $role->isPortal()) {
            throw new InvalidArgumentException(
                'ProvisionPortalUser only provisions portal roles; use CreateUser for operational accounts.',
            );
        }

        return DB::transaction(function () use ($name, $email, $role, $plainPassword): User {
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $plainPassword, // 'hashed' cast applies argon2id
                'status' => 'active',
            ]);

            // findOrCreate rather than assignRole(string): activation must
            // not fail on an installation whose seeder predates Phase 12, and
            // a freshly-created role holds no permissions until the seeder
            // grants portal.access - fail-closed, not fail-open.
            $spatieRole = SpatieRole::findOrCreate($role->value, 'web');
            $user->assignRole($spatieRole);

            // Name, email, role - never the password (an audit log that
            // records credentials is a credential store). Actor is the new
            // account itself: there is no staff operator in this flow, and
            // Actor::system() would erase WHO activated.
            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Identity',
                auditableType: User::class,
                auditableId: (int) $user->getKey(),
                after: [
                    'name' => $name,
                    'email' => $email,
                    'role' => $role->value,
                    'permissions_via_role' => [Permission::PortalAccess->value],
                    'provisioned_via' => 'portal_invitation',
                ],
                actor: $user->toAuditActor(),
            );

            return $user;
        });
    }
}
