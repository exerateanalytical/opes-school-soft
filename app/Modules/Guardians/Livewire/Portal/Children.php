<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Portal;

use App\Modules\Guardians\Support\Portal\ChildDirectory;
use App\Modules\Guardians\Support\PortalContext;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * `/portal/children` - mobile/my-children.png.
 *
 * The dashboard's carousel is a switcher; this is the LIST, with each child's
 * granted capabilities shown as chips so a parent can see at a glance what
 * their school shares for each one - which differs per child, and is the thing
 * parents most often ring the office about.
 *
 * Row 1 is the floor: every currently-valid link puts its child here however
 * narrow the link's other flags are. An empty list is therefore a real answer
 * (every link has expired), not a loading failure.
 */
#[Layout('layouts.portal')]
final class Children extends Component
{
    public function render(): mixed
    {
        $context = PortalContext::current();

        if ($context === null) {
            abort(403);
        }

        return view('livewire.guardians.portal.children', [
            'children' => app(ChildDirectory::class)->children($context),
        ]);
    }
}
