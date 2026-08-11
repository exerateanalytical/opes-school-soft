<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Models\User;

/**
 * Revoke mobile device tokens (docs/specs/2026-08-11-guardian-mobile-api-v1.md
 * §2.2). Three shapes, one action, because they are one decision - "this
 * credential stops working now" - and splitting them would let one of the
 * three quietly skip the audit row.
 *
 * Scoped to `mobile:` tokens throughout. A guardian signing out of their phone
 * must not be able to kill a staff integration token that happens to hang off
 * the same user row, and `logout-all` means all of THEIR devices, not all of
 * anything.
 */
final class RevokeDeviceToken
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /** The token the request itself arrived on. */
    public function current(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token === null || $token->getKey() === null) {
            // A session-authenticated caller carries a TransientToken with no
            // key; there is nothing to revoke and nothing to audit.
            return;
        }

        $name = (string) ($token->name ?? '');

        $user->tokens()->whereKey($token->getKey())->delete();

        $this->log($user, ['scope' => 'current', 'token_name' => $name]);
    }

    /** Every mobile token this user holds - a "sign out everywhere". */
    public function all(User $user): int
    {
        $deleted = $user->tokens()->where('name', 'like', 'mobile:%')->delete();

        $this->log($user, ['scope' => 'all', 'revoked' => $deleted]);

        return $deleted;
    }

    /** One named device, by token id. Returns false when it is not theirs. */
    public function one(User $user, int $tokenId): bool
    {
        $token = $user->tokens()
            ->whereKey($tokenId)
            ->where('name', 'like', 'mobile:%')
            ->first();

        if ($token === null) {
            return false;
        }

        $name = (string) $token->name;

        $token->delete();

        $this->log($user, ['scope' => 'device', 'token_name' => $name]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function log(User $user, array $after): void
    {
        $this->audit->handle(
            action: AuditAction::Logout,
            module: 'Identity',
            auditableType: User::class,
            auditableId: (int) $user->getKey(),
            after: $after + ['channel' => 'mobile'],
            actor: $user->toAuditActor(),
        );
    }
}
