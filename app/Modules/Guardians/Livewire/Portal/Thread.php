<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Portal;

use App\Modules\Communication\Actions\Messaging\PostMessage;
use App\Modules\Guardians\Support\Portal\GuardianInbox;
use DomainException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `/portal/messages/{thread}` - one conversation, and the guardian portal's
 * first WRITE.
 *
 * A thread this user does not participate in answers 404, not 403, for the
 * same reason row 32 chooses 404 for an unlinked child: confirming that a
 * thread exists is itself a disclosure.
 *
 * The participation check runs on mount AND again inside PostMessage's own
 * transaction. That is not redundant - the check here stops the page
 * rendering, and the one inside the Action is the actual control, owned by the
 * module that owns threads. A Livewire component is a long-lived object across
 * wire requests, so re-checking at write time is what makes it safe.
 */
#[Layout('layouts.portal')]
final class Thread extends Component
{
    public int $threadId;

    public string $title = '';

    public string $body = '';

    public function mount(int $thread): void
    {
        $inbox = app(GuardianInbox::class);

        if (! $inbox->isParticipant($thread, $this->userId())) {
            throw new NotFoundHttpException();
        }

        $this->threadId = $thread;
        $this->title = $inbox->threadTitle($thread) ?? '';
    }

    public function send(): void
    {
        $this->validate([
            // 4000 is the API's limit too (spec §5); the two doors must not
            // disagree about what a sendable message is.
            'body' => ['required', 'string', 'max:4000'],
        ]);

        try {
            app(PostMessage::class)->handle($this->threadId, $this->userId(), $this->body);
        } catch (DomainException) {
            // "You are not a participant" - the membership changed under us.
            throw new NotFoundHttpException();
        }

        $this->body = '';
        session()->flash('portal-status', __('opes.guardian_portal.messages_sent'));
    }

    public function render(): mixed
    {
        return view('livewire.guardians.portal.thread', [
            'threadId' => $this->threadId,
            'title' => $this->title,
            'messages' => app(GuardianInbox::class)->messages($this->threadId),
            'meId' => $this->userId(),
        ]);
    }

    private function userId(): int
    {
        return (int) (auth()->id() ?? 0);
    }
}
