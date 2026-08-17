<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Grant or withdraw the official-account tick.
 *
 * The whole value of a blue tick is that its subject cannot award it to
 * themselves, so unlike SetUsername there is NO self-service branch here: the
 * actor must hold `user.manage` even when marking their own account. That is
 * an existing permission the Administrator and SuperAdmin baselines already
 * grant (Identity\Domain\Role::defaultPermissions) - deliberately not a new
 * string, which would be a right no role holds and therefore a gate nobody
 * can pass.
 */
final class MarkUserOfficial
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @throws AuthorizationException when the actor does not hold `user.manage`
     */
    public function handle(User $target, bool $official, User $actor): void
    {
        if (! $actor->can(Permission::UserManage->value)) {
            throw new AuthorizationException(
                'You do not have permission to mark an account as official.'
            );
        }

        DB::transaction(function () use ($target, $official, $actor): void {
            $before = (bool) $target->is_official;

            $target->is_official = $official;
            $target->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Identity',
                auditableType: User::class,
                auditableId: (int) $target->getKey(),
                before: ['is_official' => $before],
                after: ['is_official' => $official],
                actor: $actor->toAuditActor(),
            );
        });
    }
}
