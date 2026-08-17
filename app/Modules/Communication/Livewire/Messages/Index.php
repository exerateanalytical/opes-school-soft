<?php

declare(strict_types=1);

namespace App\Modules\Communication\Livewire\Messages;

use App\Modules\Communication\Actions\Messaging\ListThreadsForUser;
use App\Modules\Communication\Actions\Messaging\MarkThreadRead;
use App\Modules\Communication\Actions\Messaging\PostMessage;
use App\Modules\Communication\Actions\Messaging\StartThread;
use App\Modules\Communication\Models\MessageThread;
use App\Modules\Communication\Models\MessageThreadParticipant;
use App\Modules\Identity\Actions\FindUserIdByEmail;
use App\Modules\Identity\Actions\FindUserIdByUsername;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The in-platform messenger. Available to any authenticated user - staff and
 * guardians alike - because who may see and post is entirely controlled by
 * thread membership (00-core §6.2's contract: a route/screen is reachable,
 * an ACTION re-authorizes). There is no `messaging.view` permission gating
 * the door itself.
 */
final class Index extends Component
{
    public ?int $activeThreadId = null;

    public string $reply = '';

    public bool $showCompose = false;

    public string $newTitle = '';

    public string $newRecipient = '';

    public string $newBody = '';

    public string $error = '';

    public function selectThread(int $threadId): void
    {
        $this->activeThreadId = $threadId;
        $this->error = '';

        $userId = (int) Auth::id();

        $isParticipant = MessageThreadParticipant::query()
            ->where('message_thread_id', $threadId)
            ->where('user_id', $userId)
            ->whereNull('removed_at')
            ->exists();

        if (! $isParticipant) {
            $this->activeThreadId = null;
            $this->error = __('opes.messages_screen.not_a_participant');

            return;
        }

        app(MarkThreadRead::class)->handle($threadId, $userId);
    }

    public function send(): void
    {
        $this->error = '';

        if ($this->activeThreadId === null) {
            return;
        }

        try {
            app(PostMessage::class)->handle($this->activeThreadId, (int) Auth::id(), $this->reply);
            $this->reply = '';
            app(MarkThreadRead::class)->handle($this->activeThreadId, (int) Auth::id());
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function startThread(): void
    {
        $this->error = '';

        // Identity's doors, not Identity's model: StartThread already speaks
        // in user IDs, so nothing here ever needed the record (00-core §6.2
        // rule 2).
        //
        // Handle first, address second. A handle cannot contain `@` (see
        // Identity\Domain\Username) so the two namespaces cannot collide, and
        // trying the cheaper, more specific one first means a teacher who
        // types `amina.n` never has that read as a malformed email.
        $recipient = trim($this->newRecipient);

        $recipientId = app(FindUserIdByUsername::class)->handle($recipient);
        $recipientId ??= app(FindUserIdByEmail::class)->handle($recipient);

        if ($recipientId === null) {
            // Naming what was tried, because "not found" against a field that
            // accepts two things leaves the sender guessing which one it read
            // their input as.
            $this->error = __('opes.messages_screen.recipient_not_found', [
                'recipient' => $recipient,
            ]);

            return;
        }

        try {
            $thread = app(StartThread::class)->handle(
                (int) Auth::id(),
                $this->newTitle !== '' ? $this->newTitle : __('opes.messages_screen.default_title'),
                [$recipientId],
                $this->newBody,
            );

            $this->activeThreadId = (int) $thread->getKey();
            $this->showCompose = false;
            $this->newTitle = '';
            $this->newRecipient = '';
            $this->newBody = '';
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render(): View
    {
        $userId = (int) Auth::id();

        $threads = app(ListThreadsForUser::class)->handle($userId);

        $activeMessages = collect();
        $activeThread = null;

        if ($this->activeThreadId !== null) {
            $activeThread = MessageThread::query()->find($this->activeThreadId);

            if ($activeThread !== null) {
                $activeMessages = DB::table('messages as m')
                    ->join('users as u', 'u.id', '=', 'm.sender_id')
                    ->where('m.message_thread_id', $this->activeThreadId)
                    ->orderBy('m.created_at')
                    // sender_is_official rides along on the join the sender
                    // name already needed, so the tick costs no extra query.
                    ->get([
                        'm.id', 'm.body', 'm.created_at', 'm.sender_id',
                        'u.name as sender_name', 'u.username as sender_username',
                        'u.is_official as sender_is_official',
                    ]);
            }
        }

        return view('livewire.communication.messages.index', [
            'threads' => $threads,
            'activeThread' => $activeThread,
            'activeMessages' => $activeMessages,
        ]);
    }
}
