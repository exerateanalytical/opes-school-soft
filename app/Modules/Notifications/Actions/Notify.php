<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Modules\Notifications\Domain\NotificationKind;
use App\Modules\Notifications\Models\Notification;

/**
 * Creates a notification and, if the recipient has push subscriptions and
 * VAPID is configured, pushes it to their browser(s) immediately.
 *
 * The in-app half ALWAYS works, with or without push. A school running with
 * no VAPID keys configured (§ readiness console) still gets a working bell
 * icon; push is additive, never load-bearing.
 */
final class Notify
{
    public function __construct(private readonly SendPushNotification $push) {}

    public function handle(
        int $userId,
        NotificationKind $kind,
        string $title,
        ?string $body = null,
        ?string $url = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
    ): Notification {
        $notification = Notification::query()->create([
            'user_id' => $userId,
            'kind' => $kind->value,
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
        ]);

        $this->push->handle($notification);

        return $notification;
    }
}
