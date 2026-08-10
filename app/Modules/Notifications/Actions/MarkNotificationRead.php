<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Modules\Notifications\Models\Notification;
use DomainException;

final class MarkNotificationRead
{
    public function handle(int $notificationId, int $userId): Notification
    {
        /** @var Notification $notification */
        $notification = Notification::query()->findOrFail($notificationId);

        if ($notification->user_id !== $userId) {
            throw new DomainException('This notification does not belong to you.');
        }

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return $notification->refresh();
    }

    public function markAllRead(int $userId): int
    {
        return Notification::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}
