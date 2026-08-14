<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Support\Audit\Actor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Identity's door for portal-account provisioning (Phase 12,
 * docs/plans/phase-12-13.md 12.2). Guardians\Actions\ActivatePortalAccount
 * calls this instead of touching the User model, which
 * tests/Architecture/ModuleBoundaryTest.php forbids it to import.
 *
 * On the self-activation path this takes NO permission gate: the caller's
 * authority is the invitation code it has already verified, and portal
 * activation happens before any session exists. What replaces the gate is a
 * hard ceiling on what can be provisioned: only the two portal roles, which
 * the seeder grants exactly `portal.access` and nothing else. A stolen or
 * forged call can mint a portal shell account, never an operational one -
 * staff accounts still go through CreateUser under `user.manage`.
 *
 * The ADMIN-MEDIATED path (HR\Actions\GrantStaffPortalAccess, where there is
 * no email channel to carry an invitation code) passes an `$authorisedBy`
 * Actor instead, and then this action enforces `user.manage` itself. That
 * check has to live here rather than in HR, because proving it in HR would
 * mean HR holding a User - the very import the boundary rule forbids.
 */
final class ProvisionPortalUser
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @param  Actor|null  $authorisedBy  When supplied, this provisioning is
     *   an ADMIN-MEDIATED grant rather than a self-activation, and the actor
     *   must hold `user.manage` - the same authority CreateUser demands. The
     *   check lives here, on Identity's side of the module boundary, so a
     *   calling module never has to hold a User to prove it may create one
     *   (00-core §6.2 rule 2). Null keeps the invitation-code path exactly as
     *   it was: its authority is the code it already verified, and no session
     *   exists yet to gate on.
     */
    public function handle(
        string $name,
        string $email,
        Role $role,
        string $plainPassword,
        ?Actor $authorisedBy = null,
        bool $mustChangePassword = false,
    ): User {
        if (! $role->isPortal()) {
            throw new InvalidArgumentException(
                'ProvisionPortalUser only provisions portal roles; use CreateUser for operational accounts.',
            );
        }

        if ($authorisedBy !== null) {
            $authoriser = $authorisedBy->id === null
                ? null
                : User::query()->find($authorisedBy->id);

            if ($authoriser === null || ! $authoriser->can(Permission::UserManage->value)) {
                throw new AuthorizationException('You do not have permission to create users.');
            }
        }

        return DB::transaction(function () use ($name, $email, $role, $plainPassword, $mustChangePassword): User {
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

            if ($mustChangePassword) {
                // The admin hands over a temporary password face to face
                // (this platform sends no email), so the account must force a
                // change at first sign-in. HR used to reach in and forceFill
                // this itself, which is the row-level access the boundary
                // rule exists to stop.
                $user->forceFill(['must_change_password_at' => now()])->save();
            }

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
