<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Portal;

use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use App\Modules\Guardians\Support\Portal\GuardianInbox;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * `/portal/announcements` - 07-students.md 7.5 row 26.
 *
 * Row 26 is granted on "any valid link" without naming a child, so this page
 * is not child-scoped. A guardian whose every link has expired holds nothing
 * and is refused - which is 7.5's historic-access rule, not a bug: an
 * announcement is news about a school you are currently part of.
 */
#[Layout('layouts.portal')]
final class Announcements extends Component
{
    public function mount(): void
    {
        app(GuardianPortalPolicy::class)
            ->authorizeForAnyChild(GuardianCapability::R26ViewTimetableAndAnnouncements);
    }

    public function render(): mixed
    {
        $userId = (int) (auth()->id() ?? 0);

        return view('livewire.guardians.portal.announcements', [
            'announcements' => app(GuardianInbox::class)->announcements($userId),
        ]);
    }
}
