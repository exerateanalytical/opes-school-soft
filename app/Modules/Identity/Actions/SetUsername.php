<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\Username;
use App\Modules\Identity\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Set or change a user's handle (docs/specs/00-core.md 9.1).
 *
 * Unlike SetUserPassword this is NOT an administrator-only door: a handle is
 * how you ask to be messaged, so you own your own. The gate is therefore
 * "yourself, OR someone holding `user.manage`" - the same authority CreateUser
 * demands, so an Administrator fixing an offensive or mistyped handle does not
 * need a permission of its own.
 *
 * Audited, because a handle is an identity claim: if `bursar.office` changes
 * hands, the trail has to say when and to whom.
 */
final class SetUsername
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  string  $username  raw input; normalised (trimmed, lower-cased) here
     *
     * @throws AuthorizationException when the actor is neither the target nor an administrator
     * @throws DomainException on a malformed handle or one already taken
     */
    public function handle(User $target, string $username, User $actor): string
    {
        $isSelf = (int) $actor->getKey() === (int) $target->getKey();

        if (! $isSelf && ! $actor->can(Permission::UserManage->value)) {
            throw new AuthorizationException('You do not have permission to set another user\'s username.');
        }

        $value = Username::normalise($username);

        if (! Username::isValid($value)) {
            throw new DomainException((string) Username::violation($username));
        }

        // Compared on the normalised form, so `Amina.N` cannot slip past a
        // stored `amina.n`. The unique index is the real guarantee; this
        // check exists to turn a driver-specific integrity exception into a
        // sentence the account screen can show.
        $taken = User::query()
            ->where('username', $value)
            ->where('id', '!=', $target->getKey())
            ->exists();

        if ($taken) {
            throw new DomainException(__('opes.account.username_taken'));
        }

        return DB::transaction(function () use ($target, $value, $actor): string {
            $before = $target->username;

            $target->username = $value;
            $target->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Identity',
                auditableType: User::class,
                auditableId: (int) $target->getKey(),
                before: ['username' => $before],
                after: ['username' => $value],
                actor: $actor->toAuditActor(),
            );

            return $value;
        });
    }
}
