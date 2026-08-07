<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Admin-driven password reset (docs/specs/00-core.md 9.3).
 *
 * The PRIMARY reset path, not a fallback: most Cameroonian schools have no
 * SMTP server, so a token-emailed-to-the-user flow would strand them.
 */
final class SetUserPassword
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(User $target, string $plainPassword, User $actor): void
    {
        if (! $actor->can(Permission::UserSetPassword->value)) {
            throw new AuthorizationException(
                'You do not have permission to set another user\'s password.'
            );
        }

        DB::transaction(function () use ($target, $plainPassword, $actor): void {
            $target->password = $plainPassword; // 'hashed' cast applies argon2id
            $target->must_change_password_at = now();
            $target->save();

            // Neither the old nor the new password appears in before/after.
            // An audit log that records credentials is a credential store.
            $this->audit->handle(
                action: AuditAction::PasswordSet,
                module: 'Identity',
                auditableType: User::class,
                auditableId: (int) $target->getKey(),
                before: null,
                after: ['forced_change' => true],
                actor: $actor->toAuditActor(),
            );
        });
    }
}
