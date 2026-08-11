<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Portal;

use App\Modules\Guardians\Support\Portal\GuardianAccount;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * `/portal/account/security` - Connected Devices and Active Sessions
 * (`mobile/security.png`, and the Security card on
 * `mobile/account-settings.png`).
 *
 * The two lists are different things and are kept apart:
 *
 *   SESSIONS are browsers signed in right now (Laravel's `sessions` table).
 *   DEVICES are mobile app installations holding a 30-day Sanctum token named
 *   `mobile:{platform}:{device_id}`.
 *
 * Revoking a device is real and is the honest answer to "I lost my phone" -
 * the token dies immediately rather than in 30 days. It is scoped by owner
 * inside the delete, so a guessed id revokes nothing.
 *
 * There is no "change password" and no 2FA toggle here, for the reason
 * Account's docblock gives: neither exists on this surface, and a control that
 * appears to harden an account without doing so is worse than its absence.
 */
#[Layout('layouts.portal')]
final class Security extends Component
{
    public function revokeDevice(int $tokenId): void
    {
        app(GuardianAccount::class)->revokeDevice($this->userId(), $tokenId);

        session()->flash('portal-status', __('opes.guardian_portal.security_device_revoked'));
    }

    public function render(): mixed
    {
        $account = app(GuardianAccount::class);
        $userId = $this->userId();

        return view('livewire.guardians.portal.security', [
            'sessions' => $account->activeSessions($userId),
            'devices' => $account->mobileDevices($userId),
            'lastLoginAt' => $account->lastLoginAt($userId),
        ]);
    }

    private function userId(): int
    {
        return (int) (auth()->id() ?? 0);
    }
}
