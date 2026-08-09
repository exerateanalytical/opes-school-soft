<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

/**
 * Issue a personal access token (docs/plans/phase-12-13.md 12.4).
 *
 * Abilities are Permission enum VALUES, validated here rather than trusted
 * from the screen: a token row with a misspelt ability would silently match
 * nothing (Sanctum string-compares), which reads as "API broken" months
 * later. Failing loudly at issuance is the kinder failure.
 *
 * The audit row records the token's name and abilities - never the token
 * itself. The plaintext exists only in the NewAccessToken returned to the
 * caller, is shown once, and is otherwise stored solely as a SHA-256 hash.
 */
final class IssueApiToken
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @param  list<string>  $abilities
     */
    public function handle(User $tokenOwner, string $name, array $abilities, User $actor): NewAccessToken
    {
        if (! $actor->can(Permission::ApiTokenManage->value)) {
            throw new AuthorizationException('You do not have permission to manage API tokens.');
        }

        $valid = array_map(static fn (Permission $p): string => $p->value, Permission::cases());

        foreach ($abilities as $ability) {
            if (! in_array($ability, $valid, true)) {
                throw ValidationException::withMessages([
                    'abilities' => __('opes.api_tokens.invalid_ability'),
                ]);
            }
        }

        if ($abilities === []) {
            throw ValidationException::withMessages([
                'abilities' => __('opes.api_tokens.abilities_required'),
            ]);
        }

        $token = $tokenOwner->createToken($name, $abilities);

        $this->audit->handle(
            action: AuditAction::Created,
            module: 'Identity',
            auditableType: User::class,
            auditableId: (int) $tokenOwner->getKey(),
            after: ['token_name' => $name, 'abilities' => $abilities],
            actor: $actor->toAuditActor(),
        );

        return $token;
    }
}
