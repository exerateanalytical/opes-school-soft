<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Revoke (delete) a personal access token.
 *
 * Deletion IS revocation for Sanctum - the stored row holds only the hash,
 * so removing it makes every future presentation of the plaintext fail
 * authentication immediately. The audit row keeps the token's name so the
 * trail still says what was revoked after the row is gone.
 */
final class RevokeApiToken
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(User $tokenOwner, int $tokenId, User $actor): void
    {
        if (! $actor->can(Permission::ApiTokenManage->value)) {
            throw new AuthorizationException('You do not have permission to manage API tokens.');
        }

        /** @var \Laravel\Sanctum\PersonalAccessToken|null $token */
        $token = $tokenOwner->tokens()->whereKey($tokenId)->first();

        if ($token === null) {
            return; // Already gone - revocation is idempotent.
        }

        $name = (string) $token->name;
        $token->delete();

        $this->audit->handle(
            action: AuditAction::Deleted,
            module: 'Identity',
            auditableType: User::class,
            auditableId: (int) $tokenOwner->getKey(),
            before: ['token_name' => $name],
            actor: $actor->toAuditActor(),
        );
    }
}
