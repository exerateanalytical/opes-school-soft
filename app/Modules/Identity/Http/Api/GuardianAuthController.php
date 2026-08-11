<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Api;

use App\Modules\Guardians\Support\PortalContext;
use App\Modules\Identity\Actions\AuthenticateUser;
use App\Modules\Identity\Actions\IssueDeviceToken;
use App\Modules\Identity\Actions\RevokeDeviceToken;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The guardian mobile app's front door
 * (docs/specs/2026-08-11-guardian-mobile-api-v1.md §2.2).
 *
 * Why a separate controller from anything staff-facing: a parent cannot reach
 * Identity\Livewire\Users\Tokens - that screen is `api.manage_tokens`, held by
 * administrators - and must not. This endpoint is self-service and therefore
 * gives the caller no say in what the token may do: IssueDeviceToken fixes the
 * abilities at portal.read + portal.write whatever the request body says.
 *
 * ONE failure message for every failure mode - wrong password, unknown
 * identifier, suspended account, staff account with no guardian row behind it.
 * Distinguishing them would turn this endpoint into an oracle for "is this
 * person a parent at this school", which is exactly the fact the portal exists
 * to protect.
 */
final class GuardianAuthController
{
    public function token(Request $request, AuthenticateUser $authenticate, IssueDeviceToken $issue): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:191'],
            'password' => ['required', 'string'],
            'device_id' => ['required', 'string', 'max:64'],
            'device_name' => ['required', 'string', 'max:120'],
            'platform' => ['required', 'string', 'in:ios,android,web'],
        ]);

        $identifier = trim($data['identifier']);
        $user = $this->resolveUser($identifier);

        // verify() is AuthenticateUser's - the same password check, dummy-hash
        // timing defence and login_failed audit row the web login writes. The
        // only thing this path does differently is not create a session.
        if (! $authenticate->verify($user, $data['password'], $identifier)) {
            $this->refuse();
        }

        if (! $user instanceof User) {
            $this->refuse();
        }

        // The account must be a guardian-portal principal: the outer gate AND
        // an active, non-archived guardian row. Same two questions
        // EnsureGuardianApi asks on every subsequent request - asked here too
        // so a token is never minted for a principal that every later request
        // would 403.
        if (! Gate::forUser($user)->allows(Permission::PortalAccess->value)) {
            $this->refuse();
        }

        $context = PortalContext::resolveForUserId((int) $user->getKey());

        if ($context === null) {
            $this->refuse();
        }

        $token = $issue->handle($user, $data['platform'], $data['device_id'], $data['device_name']);

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
                'abilities' => IssueDeviceToken::ABILITIES,
                'guardian' => [
                    'id' => (int) $context->guardian->getKey(),
                    'display_name' => $context->guardian->fullName(),
                    'language' => $context->guardian->language->value,
                ],
            ],
        ], 201);
    }

    /**
     * Rotate the presented token: a new secret, a fresh 30 days, the same
     * device. The old row is deleted by IssueDeviceToken (same token name), so
     * a refresh cannot leave two live credentials for one phone.
     */
    public function refresh(Request $request, IssueDeviceToken $issue): JsonResponse
    {
        $user = $request->user();
        $current = $user?->currentAccessToken();

        if (! $user instanceof User || $current === null) {
            $this->refuse();
        }

        $name = (string) ($current->name ?? '');
        $parts = explode(':', $name, 3);

        if (count($parts) !== 3 || $parts[0] !== 'mobile') {
            // Not a device token - a staff integration token must not be able
            // to rotate itself into a portal token.
            abort(403);
        }

        $token = $issue->handle($user, $parts[1], $parts[2], $name);

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
                'abilities' => IssueDeviceToken::ABILITIES,
            ],
        ]);
    }

    public function logout(Request $request, RevokeDeviceToken $revoke): JsonResponse
    {
        $user = $request->user();

        if ($user instanceof User) {
            $revoke->current($user);
        }

        return response()->json(['data' => ['revoked' => true]]);
    }

    public function logoutAll(Request $request, RevokeDeviceToken $revoke): JsonResponse
    {
        $user = $request->user();
        $count = $user instanceof User ? $revoke->all($user) : 0;

        return response()->json(['data' => ['revoked' => $count]]);
    }

    /** Where this account is signed in. Never exposes a token value. */
    public function devices(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $current = $user->currentAccessToken()?->getKey();

        $devices = $user->tokens()
            ->where('name', 'like', 'mobile:%')
            ->orderByDesc('last_used_at')
            ->get()
            ->map(static function ($token) use ($current): array {
                $parts = explode(':', (string) $token->name, 3);

                return [
                    'id' => (int) $token->getKey(),
                    'platform' => $parts[1] ?? 'unknown',
                    'created_at' => $token->created_at?->toIso8601String(),
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'expires_at' => $token->expires_at?->toIso8601String(),
                    'is_current' => $current !== null && (int) $token->getKey() === (int) $current,
                ];
            })
            ->all();

        return response()->json(['data' => $devices]);
    }

    public function forgetDevice(Request $request, int $token, RevokeDeviceToken $revoke): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User || ! $revoke->one($user, $token)) {
            // Not theirs, or not a device token. 404 rather than 403: the
            // existence of somebody else's token id is not this caller's
            // business either way.
            abort(404);
        }

        return response()->json(['data' => ['revoked' => true]]);
    }

    /**
     * Email, or the phone the guardian record carries. The phone lookup goes
     * through `guardians.portal_user_id`, so it can only ever land on an
     * account that is already a portal principal; a staff email remains the
     * only way to reach a staff account, and that account will fail the
     * guardian checks above anyway.
     */
    private function resolveUser(string $identifier): ?User
    {
        if (str_contains($identifier, '@')) {
            return User::query()->where('email', $identifier)->first();
        }

        $digits = preg_replace('/[^0-9+]/', '', $identifier) ?? '';

        if ($digits === '') {
            return null;
        }

        $userId = DB::table('guardians')
            ->whereNotNull('portal_user_id')
            ->where('is_archived', false)
            ->where(function ($query) use ($digits): void {
                $query->where('phone', $digits)->orWhere('alternative_phone', $digits);
            })
            ->value('portal_user_id');

        if ($userId === null) {
            return null;
        }

        return User::query()->whereKey($userId)->first();
    }

    /**
     * The single refusal. 422 with a generic message rather than 401, because
     * this is a credential-shaped validation failure and the mobile client
     * renders it beside the field; the reason is in the audit log, never here.
     *
     */
    private function refuse(): never
    {
        throw ValidationException::withMessages([
            'identifier' => __('opes.auth.failed'),
        ]);
    }
}
