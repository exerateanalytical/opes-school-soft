<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Portal;

use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use App\Modules\Guardians\Support\Portal\ChildDirectory;
use App\Modules\Guardians\Support\Portal\GuardianAccount;
use App\Modules\Guardians\Support\PortalContext;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * `/portal/account` - the Parent Profile screen (`mobile/parent-profile.png`).
 *
 * The hub: who the parent is, which children are on their account, what the
 * account itself looks like, and the ways out to the things they can change.
 *
 * Two things the reference design shows that this screen deliberately does NOT
 * claim, because neither exists:
 *
 *   "Two-factor authentication is enabled" - there is no 2FA anywhere in this
 *   platform (spec §1 non-goals). Printing that sentence would tell a parent
 *   their account has a protection it does not have, which is worse than
 *   telling them nothing.
 *
 *   "Change Password" - there is no password-change endpoint on this surface.
 *   The tile is absent rather than dead.
 *
 * What IS shown is real: the registration date off `guardians.created_at`, the
 * last login off the audit chain, and the live sessions and mobile devices.
 * See GuardianAccount.
 */
#[Layout('layouts.portal')]
final class Account extends Component
{
    public function mount(): void
    {
        // The account screen is reachable by any portal principal - it is
        // about THEM, not about a child. Row 29 gates the EDIT screen, not
        // this one, so a guardian whose links have all expired can still see
        // who the school thinks they are and how to reach the office.
    }

    public function render(): mixed
    {
        $context = PortalContext::current();
        $guardian = $context?->guardian;
        $account = app(GuardianAccount::class);
        $userId = (int) (auth()->id() ?? 0);

        $children = $context === null ? [] : app(ChildDirectory::class)->children($context);

        return view('livewire.guardians.portal.account', [
            'guardian' => $guardian,
            'children' => $children,
            'registeredOn' => $guardian === null ? null : $account->registeredOn((int) $guardian->getKey()),
            'lastLoginAt' => $account->lastLoginAt($userId),
            'sessionCount' => $account->activeSessions($userId)->count(),
            'deviceCount' => $account->mobileDevices($userId)->count(),
            // Row 29 decides whether the "Update contact info" door is offered.
            'canEdit' => app(GuardianPortalPolicy::class)
                ->allowsForAnyChild(GuardianCapability::R29EditOwnContactDetails),
        ]);
    }
}
