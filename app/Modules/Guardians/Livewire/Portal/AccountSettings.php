<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Portal;

use App\Modules\Guardians\Support\Portal\GuardianAccount;
use App\Modules\Guardians\Support\PortalContext;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * `/portal/account/settings` - the Account Settings hub
 * (`mobile/account-settings.png`).
 *
 * A router, not a form: every row leads to the screen that actually owns that
 * decision. The reference design lists six rows; five of them exist and are
 * wired. "Password, 2FA and security options" becomes "Devices and sessions",
 * because that is the part of it this platform has - see Account's docblock
 * for why the missing parts are not stubbed.
 */
#[Layout('layouts.portal')]
final class AccountSettings extends Component
{
    public function render(): mixed
    {
        $account = app(GuardianAccount::class);
        $userId = (int) (auth()->id() ?? 0);
        $guardian = PortalContext::current()?->guardian;

        return view('livewire.guardians.portal.account-settings', [
            'guardian' => $guardian,
            'sessionCount' => $account->activeSessions($userId)->count(),
            'deviceCount' => $account->mobileDevices($userId)->count(),
            // The notification channels, shown as On/Off chips exactly as the
            // reference screen does - read from the guardian row, which is
            // where the school's SMS and email senders read them from too.
            'notifySms' => (bool) ($guardian->notify_sms ?? false),
            'notifyEmail' => (bool) ($guardian->notify_email ?? false),
            'notifyPush' => (bool) ($guardian->notify_push ?? false),
        ]);
    }
}
