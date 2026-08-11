<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Models\User;
use Laravel\Sanctum\NewAccessToken;

/**
 * Issue a DEVICE token for the guardian mobile app
 * (docs/specs/2026-08-11-guardian-mobile-api-v1.md §2.2).
 *
 * Deliberately NOT IssueApiToken. That action is the staff-operated one: an
 * actor holding `api.manage_tokens` mints a token for somebody else, with any
 * abilities they choose. This one is self-service - the caller has just proven
 * the password - so the two things that action takes as parameters are fixed
 * here instead:
 *
 *   - the owner is the authenticating user, never an argument;
 *   - the abilities are exactly portal.read + portal.write, never an argument.
 *
 * A parent can therefore never obtain a token that reads students or fees
 * module-wide, whatever they post. The abilities are still Permission enum
 * values, so they remain checkable by the `abilities:` middleware exactly like
 * a staff token's.
 *
 * Device identity: the token NAME carries `mobile:{platform}:{device_id}` and
 * re-authenticating from the same device deletes the previous row first. One
 * device holds one live token, so a re-install cannot leave an orphan
 * credential valid for 30 days, and `/auth/devices` is an honest list of where
 * the account is signed in.
 */
final class IssueDeviceToken
{
    /** How long a device token lives before the app must refresh it. */
    public const LIFETIME_DAYS = 30;

    /** The scope every mobile token carries - the whole of it. */
    public const ABILITIES = [
        Permission::PortalRead->value,
        Permission::PortalWrite->value,
    ];

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(User $user, string $platform, string $deviceId, string $deviceName): NewAccessToken
    {
        $name = self::tokenName($platform, $deviceId);

        // Same device, new sign-in: the old credential dies before the new one
        // exists. Not after - a crash between the two must leave the account
        // with fewer live tokens, never more.
        $user->tokens()->where('name', $name)->delete();

        // Expiry is set PER TOKEN rather than through sanctum.expiration:
        // that config key is global, and switching it on would silently put a
        // 30-day clock on every existing staff integration token too. A
        // device token dies in 30 days; refreshing rotates it.
        $token = $user->createToken($name, self::ABILITIES, now()->addDays(self::LIFETIME_DAYS));

        $this->audit->handle(
            action: AuditAction::Login,
            module: 'Identity',
            auditableType: User::class,
            auditableId: (int) $user->getKey(),
            after: [
                'token_name' => $name,
                'device_name' => $deviceName,
                'abilities' => self::ABILITIES,
                'channel' => 'mobile',
            ],
            actor: $user->toAuditActor(),
        );

        return $token;
    }

    public static function tokenName(string $platform, string $deviceId): string
    {
        return 'mobile:'.$platform.':'.$deviceId;
    }
}
