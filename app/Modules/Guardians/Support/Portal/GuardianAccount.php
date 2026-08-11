<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Support\Portal;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The facts the account screens show ABOUT the account itself, as opposed to
 * the guardian's own editable details (row 29, which is
 * Actions\UpdateOwnContactDetails).
 *
 * Everything here is derived from data that genuinely exists. That constraint
 * decided the shape of these screens: the reference designs show a "Last
 * Login" row, an "Active Sessions" count and a "Two-Factor Authentication:
 * Enabled" line, and only the first two are real.
 *
 *   Last login   - the newest `login` row in `audit_logs` for this user. The
 *                  `users` table has no `last_login_at` column, and adding one
 *                  would duplicate a fact the audit chain already records.
 *   Sessions     - Laravel's own `sessions` table: the browsers signed in
 *                  right now, with their user agent and last activity.
 *   Devices      - Sanctum tokens named `mobile:{platform}:{device_id}`, which
 *                  is the mobile app's own device registration.
 *   2FA          - does NOT exist. Spec §1 lists it under non-goals and there
 *                  is no endpoint, no column and no flow. The screens say so
 *                  rather than printing "Enabled" beside a feature nobody
 *                  built, which would be the single most dangerous sentence
 *                  in this product.
 */
final class GuardianAccount
{
    /** Tokens issued to the mobile app carry this name prefix. */
    private const DEVICE_PREFIX = 'mobile:';

    /**
     * When this guardian's record was created - the "Registration Date" row.
     */
    public function registeredOn(int $guardianId): ?string
    {
        $value = DB::table('guardians')->where('id', $guardianId)->value('created_at');

        return $value === null ? null : (string) $value;
    }

    /**
     * The newest successful sign-in, read off the audit chain rather than a
     * denormalised column.
     */
    public function lastLoginAt(int $userId): ?string
    {
        if (! Schema::hasTable('audit_logs')) {
            return null;
        }

        $value = DB::table('audit_logs')
            ->where('actor_id', $userId)
            ->where('action', 'login')
            ->orderByDesc('id')
            ->value('created_at');

        return $value === null ? null : (string) $value;
    }

    /**
     * Browsers currently signed in as this user.
     *
     * The CURRENT session is flagged rather than hidden, so a parent reviewing
     * this list can tell which row is the device in their hand - a list where
     * every entry looks equally foreign invites them to revoke the wrong one.
     *
     * @return Collection<int, object{id: string, ip_address: string|null, user_agent: string|null, last_activity: int, is_current: bool}&\stdClass>
     */
    public function activeSessions(int $userId): Collection
    {
        if (! Schema::hasTable('sessions')) {
            return collect();
        }

        $currentId = session()->getId();

        return DB::table('sessions')
            ->where('user_id', $userId)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(static fn (object $row): object => (object) [
                'id' => (string) $row->id,
                'ip_address' => $row->ip_address === null ? null : (string) $row->ip_address,
                'user_agent' => $row->user_agent === null ? null : (string) $row->user_agent,
                'last_activity' => (int) $row->last_activity,
                'is_current' => (string) $row->id === $currentId,
            ])
            ->values();
    }

    /**
     * Mobile app installations holding a live device token.
     *
     * Expired tokens are excluded: a 30-day token that lapsed is not a device
     * a parent still needs to worry about, and listing it would make the
     * screen look alarming for no reason.
     *
     * @return Collection<int, object{id: int, platform: string, last_used_at: string|null, created_at: string|null, expires_at: string|null}&\stdClass>
     */
    public function mobileDevices(int $userId): Collection
    {
        if (! Schema::hasTable('personal_access_tokens')) {
            return collect();
        }

        return DB::table('personal_access_tokens')
            ->where('tokenable_type', 'App\\Modules\\Identity\\Models\\User')
            ->where('tokenable_id', $userId)
            ->where('name', 'like', self::DEVICE_PREFIX.'%')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('last_used_at')
            ->get(['id', 'name', 'last_used_at', 'created_at', 'expires_at'])
            ->map(static function (object $row): object {
                // `mobile:android:uuid` -> "android".
                $parts = explode(':', (string) $row->name);

                return (object) [
                    'id' => (int) $row->id,
                    'platform' => $parts[1] ?? 'mobile',
                    'last_used_at' => $row->last_used_at === null ? null : (string) $row->last_used_at,
                    'created_at' => $row->created_at === null ? null : (string) $row->created_at,
                    'expires_at' => $row->expires_at === null ? null : (string) $row->expires_at,
                ];
            })
            ->values();
    }

    /**
     * Revoke one mobile device token, scoped to its owner.
     *
     * Scoped inside the delete, not before it: an unscoped delete by id would
     * let any signed-in guardian revoke a stranger's device by guessing a
     * number.
     */
    public function revokeDevice(int $userId, int $tokenId): void
    {
        DB::table('personal_access_tokens')
            ->where('id', $tokenId)
            ->where('tokenable_type', 'App\\Modules\\Identity\\Models\\User')
            ->where('tokenable_id', $userId)
            ->where('name', 'like', self::DEVICE_PREFIX.'%')
            ->delete();
    }
}
