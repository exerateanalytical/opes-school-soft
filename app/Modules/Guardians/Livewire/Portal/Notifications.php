<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Portal;

use App\Modules\Guardians\Support\Portal\GuardianInbox;
use App\Modules\Notifications\Actions\MarkNotificationRead;
use DomainException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * `/portal/notifications` - the signed-in user's own rows.
 *
 * NOT matrix territory: `notifications.user_id` is the scope, and the row is
 * the permission. Marking one read goes through the Notifications module's own
 * Action, which owns the ownership check - one owner check, in the module that
 * owns notifications, rather than a second copy here that could disagree.
 */
#[Layout('layouts.portal')]
final class Notifications extends Component
{
    public function markRead(int $notificationId): void
    {
        try {
            app(MarkNotificationRead::class)->handle($notificationId, $this->userId());
        } catch (DomainException) {
            // Another user's row. Silently ignored rather than reported:
            // confirming that a notification id exists is not this user's
            // business, and the list will simply not change.
            return;
        }

        session()->flash('portal-status', __('opes.guardian_portal.notifications_marked'));
    }

    public function markAllRead(): void
    {
        app(MarkNotificationRead::class)->markAllRead($this->userId());

        session()->flash('portal-status', __('opes.guardian_portal.notifications_marked'));
    }

    public function render(): mixed
    {
        $inbox = app(GuardianInbox::class);
        $userId = $this->userId();

        return view('livewire.guardians.portal.notifications', [
            'notifications' => $inbox->notifications($userId),
            'unread' => $inbox->unreadNotificationCount($userId),
        ]);
    }

    private function userId(): int
    {
        return (int) (auth()->id() ?? 0);
    }
}
