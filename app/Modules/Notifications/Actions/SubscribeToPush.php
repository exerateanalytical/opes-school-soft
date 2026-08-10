<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Modules\Notifications\Models\PushSubscription;

/**
 * Records (or refreshes) a browser's Web Push subscription.
 *
 * Upserts on `endpoint`: a browser re-subscribing after a permission
 * refresh or a service-worker update sends the SAME endpoint back, and
 * without the upsert every refresh would pile up a duplicate row that then
 * receives duplicate pushes.
 */
final class SubscribeToPush
{
    public function handle(
        int $userId,
        string $endpoint,
        string $p256dh,
        string $auth,
        ?string $userAgent = null,
    ): PushSubscription {
        return PushSubscription::query()->updateOrCreate(
            ['endpoint' => $endpoint],
            [
                'user_id' => $userId,
                'p256dh' => $p256dh,
                'auth' => $auth,
                'user_agent' => $userAgent,
                'last_used_at' => now(),
            ],
        );
    }
}
