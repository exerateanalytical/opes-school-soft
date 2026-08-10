<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Notifications\Actions\SubscribeToPush;
use App\Modules\Notifications\Actions\UnsubscribeFromPush;
use App\Modules\SchoolProfile\Actions\ReadSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The plain-HTTP door behind `PushManager.subscribe()` in the browser: the
 * Fetch API posts the subscription JSON here, not through Livewire, since
 * push subscription is a one-shot browser API call with no form to submit.
 */
final class PushSubscriptionController
{
    public function vapidPublicKey(ReadSetting $read): JsonResponse
    {
        $key = $read->handle('notifications.vapid_public_key');

        return response()->json(['publicKey' => is_string($key) ? $key : null]);
    }

    public function subscribe(Request $request, SubscribeToPush $subscribe): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => 'required|string|max:500',
            'keys.p256dh' => 'required|string|max:255',
            'keys.auth' => 'required|string|max:255',
        ]);

        $subscribe->handle(
            (int) $request->user()->getKey(),
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth'],
            mb_substr((string) $request->userAgent(), 0, 255),
        );

        return response()->json(['status' => 'subscribed']);
    }

    public function unsubscribe(Request $request, UnsubscribeFromPush $unsubscribe): JsonResponse
    {
        $validated = $request->validate(['endpoint' => 'required|string|max:500']);

        $unsubscribe->handle((int) $request->user()->getKey(), $validated['endpoint']);

        return response()->json(['status' => 'unsubscribed']);
    }
}
