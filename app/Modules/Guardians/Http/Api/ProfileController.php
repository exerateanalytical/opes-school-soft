<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Http\Api;

use App\Modules\Guardians\Actions\UpdateOwnContactDetails;
use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use App\Modules\Guardians\Support\PortalContext;
use App\Modules\Notifications\Actions\SubscribeToPush;
use App\Modules\Notifications\Actions\UnsubscribeFromPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Slice E - the guardian's own row and their own devices
 * (docs/specs/2026-08-11-guardian-mobile-api-v1.md §5, §7; 7.5 rows 29 and 30).
 *
 * Row 29 is the only place in this whole surface where a guardian writes about
 * themselves, and row 30 is why that is safe: the authorization flags on a link
 * are the school's judgement, never the parent's. Both rules live in
 * UpdateOwnContactDetails - this controller validates shape and hands over.
 */
final class ProfileController
{
    public function __construct(
        private readonly GuardianPortalPolicy $policy,
        private readonly UpdateOwnContactDetails $updater,
        private readonly SubscribeToPush $subscribe,
        private readonly UnsubscribeFromPush $unsubscribe,
    ) {
    }

    /**
     * `PATCH /v1/me/profile` - row 29.
     *
     * Row 30 is enforced in the Action, not here, and the difference matters:
     * validation that merely IGNORED an unknown field would let a client post
     * `has_custody` forever and never learn it was refused. The Action treats
     * it as a security event, audits it and answers 403.
     */
    public function update(Request $request): JsonResponse
    {
        $context = $this->context();

        if (! $this->policy->allowsForAnyChild(GuardianCapability::R29EditOwnContactDetails)) {
            abort(403);
        }

        $validated = $request->validate([
            'phone' => ['sometimes', 'string', 'max:32'],
            'alternative_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'address_line' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'region' => ['sometimes', 'nullable', 'string', 'max:120'],
            'country' => ['sometimes', 'nullable', 'string', 'max:2'],
            'occupation' => ['sometimes', 'nullable', 'string', 'max:120'],
            'employer' => ['sometimes', 'nullable', 'string', 'max:160'],
            'preferred_contact_method' => ['sometimes', 'string', 'max:20'],
            'language' => ['sometimes', 'string', 'in:en,fr'],
            'emergency_contact_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'emergency_contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'emergency_contact_relationship' => ['sometimes', 'nullable', 'string', 'max:60'],
            'emergency_contact_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notify_sms' => ['sometimes', 'boolean'],
            'notify_email' => ['sometimes', 'boolean'],
            'notify_push' => ['sometimes', 'boolean'],
        ]);

        // The RAW input, not the validated subset: a forbidden field is
        // stripped by validation and would never reach the Action's row-30
        // check. The refusal has to see what the client actually sent.
        $guardian = $this->updater->handle(
            $context->guardian,
            array_merge($validated, array_intersect_key(
                $request->all(),
                array_flip(UpdateOwnContactDetails::FORBIDDEN)
            )),
            auth()->user()?->toAuditActor(),
        );

        return response()->json([
            'data' => [
                'id' => (int) $guardian->getKey(),
                'display_name' => $guardian->fullName(),
                'phone' => $guardian->phone,
                'email' => $guardian->email,
                'language' => $guardian->language->value,
                'preferred_contact_method' => $guardian->preferred_contact_method->value,
            ],
        ]);
    }

    /**
     * `POST /v1/me/devices/push` - spec §7.
     *
     * Web Push and Expo push are the same registration with different key
     * material, discriminated by `platform`. `push_subscriptions` upserts on
     * `endpoint`, which is what stops a re-subscribing app piling up duplicate
     * rows that then receive duplicate pushes.
     */
    public function registerPush(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:500'],
            'p256dh' => ['required', 'string', 'max:255'],
            'auth' => ['required', 'string', 'max:255'],
            'platform' => ['sometimes', 'string', 'max:20'],
        ]);

        $subscription = $this->subscribe->handle(
            $this->userId(),
            $validated['endpoint'],
            $validated['p256dh'],
            $validated['auth'],
            substr((string) $request->userAgent(), 0, 255),
        );

        return response()->json([
            'data' => ['id' => (int) $subscription->getKey(), 'registered' => true],
        ], 201);
    }

    /**
     * `DELETE /v1/me/devices/push`.
     *
     * Idempotent on purpose: unregistering a device that was already gone is a
     * success, not a 404. An app signing out must not be able to fail here.
     */
    public function unregisterPush(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:500'],
        ]);

        // UnsubscribeFromPush is already scoped by user id, which is what stops
        // a signed-in guardian unsubscribing a stranger's device by quoting its
        // endpoint - `endpoint` is unique table-wide, so an unscoped delete
        // would be exactly that hole.
        if (Schema::hasTable('push_subscriptions')) {
            $this->unsubscribe->handle($this->userId(), $validated['endpoint']);
        }

        return response()->json(['data' => ['registered' => false]]);
    }

    private function context(): PortalContext
    {
        $context = PortalContext::current();

        if ($context === null) {
            abort(403);
        }

        return $context;
    }

    private function userId(): int
    {
        $id = auth()->id();

        if ($id === null) {
            abort(401);
        }

        return (int) $id;
    }
}
