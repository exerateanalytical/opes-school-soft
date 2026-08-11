<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Portal;

use App\Modules\Communication\Actions\Messaging\ListThreadsForUser;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * `/portal/messages` - the guardian's inbox.
 *
 * Authorized by PARTICIPATION, not by the guardian matrix, and read through
 * Communication's own Action so the unread watermark logic has one home. That
 * logic is not incidental: it counts on `messages.id > last_read_message_id`
 * rather than a timestamp, because MySQL DATETIME is second-granular and a
 * reply landing in the same second as a read stamp would silently count as
 * already read.
 *
 * Announcements are filtered out - they have their own screen and their own
 * rendering, and mixing a one-way broadcast into a conversation list makes
 * both harder to read.
 */
#[Layout('layouts.portal')]
final class Messages extends Component
{
    public function render(): mixed
    {
        $threads = array_values(array_filter(
            app(ListThreadsForUser::class)->handle((int) (auth()->id() ?? 0)),
            static fn (array $thread): bool => $thread['kind'] !== 'announcement',
        ));

        return view('livewire.guardians.portal.messages', ['threads' => $threads]);
    }
}
