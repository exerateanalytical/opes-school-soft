<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Livewire;

use App\Modules\Notifications\Actions\MarkNotificationRead;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The bell icon in the shell header (layouts/app.blade.php), present on
 * every authenticated screen because the layout is shared by all of them.
 * Polls rather than pushes its own count - true real-time delivery is the
 * Web Push half (SendPushNotification), this is the always-works fallback
 * that needs no browser permission grant.
 */
final class Bell extends Component
{
    public bool $open = false;

    #[On('notification-created')]
    public function refresh(): void
    {
        // No body needed: re-rendering re-runs the query in render().
        // The event just tells Livewire this component is stale.
    }

    public function markRead(int $notificationId): void
    {
        $userId = Auth::id();

        if ($userId === null) {
            return;
        }

        app(MarkNotificationRead::class)->handle($notificationId, (int) $userId);
    }

    public function markAllRead(): void
    {
        $userId = Auth::id();

        if ($userId === null) {
            return;
        }

        app(MarkNotificationRead::class)->markAllRead((int) $userId);
    }

    public function render(): View
    {
        $userId = Auth::id();

        $notifications = $userId === null
            ? collect()
            : Notification::query()
                ->where('user_id', $userId)
                ->orderByDesc('created_at')
                ->limit(20)
                ->get();

        $unreadCount = $notifications->whereNull('read_at')->count();

        return view('livewire.notifications.bell', [
            'notifications' => $notifications,
            'unreadCount' => $unreadCount,
        ]);
    }
}
