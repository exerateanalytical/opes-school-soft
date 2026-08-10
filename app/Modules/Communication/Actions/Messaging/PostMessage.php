<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions\Messaging;

use App\Modules\Communication\Models\Message;
use App\Modules\Communication\Models\MessageThread;
use App\Modules\Communication\Models\MessageThreadParticipant;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Posts a reply into an existing thread.
 *
 * The membership check IS the authorization: there is no separate
 * `messaging.send` permission, because who may post is entirely determined
 * by who was added to the thread by StartThread/AddParticipant. A teacher
 * cannot message a parent they were never linked to because no thread
 * containing both of them would ever exist - the gate is upstream, not
 * inside this Action.
 *
 * Posting also stamps the SENDER's own last_read_at: replying to a thread
 * necessarily means you have read up to that point.
 */
final class PostMessage
{
    public function handle(int $threadId, int $senderId, string $body): Message
    {
        if (trim($body) === '') {
            throw new DomainException('A message cannot be empty.');
        }

        return DB::transaction(function () use ($threadId, $senderId, $body): Message {
            /** @var MessageThread $thread */
            $thread = MessageThread::query()->lockForUpdate()->findOrFail($threadId);

            $participant = MessageThreadParticipant::query()
                ->where('message_thread_id', $thread->getKey())
                ->where('user_id', $senderId)
                ->whereNull('removed_at')
                ->first();

            if ($participant === null) {
                throw new DomainException('You are not a participant in this conversation.');
            }

            $now = now();

            $message = Message::query()->create([
                'message_thread_id' => $thread->getKey(),
                'sender_id' => $senderId,
                'body' => $body,
                'is_system' => false,
            ]);

            $thread->forceFill(['last_message_at' => $now])->save();
            $participant->forceFill([
                'last_read_at' => $now,
                'last_read_message_id' => $message->getKey(),
            ])->save();

            return $message;
        });
    }
}
