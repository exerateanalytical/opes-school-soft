<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Portal;

use App\Modules\Guardians\Actions\UpdateOwnContactDetails;
use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use App\Modules\Guardians\Support\PortalContext;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * `/portal/account/notifications` - notification preferences
 * (`mobile/notification-preferences.png`).
 *
 * Only the three CHANNELS are real: `notify_sms`, `notify_email` and
 * `notify_push` are columns on the guardian row, inside row 29's allow-list,
 * and they are what the school's own senders read. So changing one here
 * genuinely changes what arrives.
 *
 * Per-TYPE preferences ("Academic Updates", "Payment Reminders") and quiet
 * hours appear in the reference design but are explicitly P1 (spec §7:
 * "Notification preferences … are P1"). They are NOT rendered as switches: a
 * switch a parent can see and flick implies it does something, and one that
 * silently does nothing is worse than an honest note.
 */
#[Layout('layouts.portal')]
final class NotificationSettings extends Component
{
    public bool $notifySms = false;

    public bool $notifyEmail = false;

    public bool $notifyPush = false;

    public function mount(): void
    {
        app(GuardianPortalPolicy::class)
            ->authorizeForAnyChild(GuardianCapability::R29EditOwnContactDetails);

        $guardian = PortalContext::current()?->guardian;

        if ($guardian === null) {
            return;
        }

        $this->notifySms = (bool) $guardian->notify_sms;
        $this->notifyEmail = (bool) $guardian->notify_email;
        $this->notifyPush = (bool) $guardian->notify_push;
    }

    public function save(): void
    {
        $context = PortalContext::current();

        if ($context === null) {
            return;
        }

        // Re-authorized at WRITE time: a Livewire component survives across
        // wire requests and a link can expire between mount and save.
        app(GuardianPortalPolicy::class)
            ->authorizeForAnyChild(GuardianCapability::R29EditOwnContactDetails);

        app(UpdateOwnContactDetails::class)->handle($context->guardian, [
            'notify_sms' => $this->notifySms,
            'notify_email' => $this->notifyEmail,
            'notify_push' => $this->notifyPush,
        ], auth()->user()?->toAuditActor());

        session()->flash('portal-status', __('opes.guardian_portal.account_saved'));
    }

    public function render(): mixed
    {
        return view('livewire.guardians.portal.notification-settings');
    }
}
