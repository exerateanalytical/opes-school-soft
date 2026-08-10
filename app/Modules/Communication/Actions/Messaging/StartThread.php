<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions\Messaging;

use App\Modules\Communication\Domain\ThreadKind;
use App\Modules\Communication\Models\Message;
use App\Modules\Communication\Models\MessageThread;
use App\Modules\Communication\Models\MessageThreadParticipant;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Starts a conversation between the caller and a set of other users, with
 * an opening message.
 *
 * There is no separate "compose" step and "send" step: a thread without a
 * first message is a database row nobody can see, since the inbox list is
 * driven off `last_message_at`. Creating the thread and posting the first
 * message happen in one transaction so the two states are never split.
 *
 * The caller is ALWAYS a participant, even if not explicitly listed - a
 * thread the starter cannot read would be a thread they could never manage.
 */
final class StartThread
{
    /**
     * @param  list<int>  $participantUserIds
     */
    public function handle(
        int $creatorUserId,
        string $title,
        array $participantUserIds,
        string $firstMessageBody,
        ?string $subjectType = null,
        ?int $subjectId = null,
    ): MessageThread {
        if (trim($firstMessageBody) === '') {
            throw new DomainException('A thread cannot start with an empty message.');
        }

        $participantIds = array_values(array_unique(array_merge([$creatorUserId], $participantUserIds)));

        if (count($participantIds) < 2) {
            throw new DomainException('A conversation needs at least one other participant.');
        }

        return DB::transaction(function () use (
            $creatorUserId, $title, $participantIds, $firstMessageBody, $subjectType, $subjectId
        ): MessageThread {
            $now = now();

            $thread = MessageThread::query()->create([
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'title' => $title,
                'kind' => ThreadKind::Conversation->value,
                'created_by' => $creatorUserId,
                'last_message_at' => $now,
                'is_archived' => false,
            ]);

            $opening = Message::query()->create([
                'message_thread_id' => $thread->getKey(),
                'sender_id' => $creatorUserId,
                'body' => $firstMessageBody,
                'is_system' => false,
            ]);

            foreach ($participantIds as $userId) {
                MessageThreadParticipant::query()->create([
                    'message_thread_id' => $thread->getKey(),
                    'user_id' => $userId,
                    // The sender has read their own opening message; every
                    // other participant has not. The watermark is the
                    // MESSAGE ID, not a timestamp - see the migration's
                    // docblock for why a same-second reply would otherwise
                    // read as already-read.
                    'last_read_at' => $userId === $creatorUserId ? $now : null,
                    'last_read_message_id' => $userId === $creatorUserId ? $opening->getKey() : null,
                    'is_muted' => false,
                    'added_at' => $now,
                ]);
            }

            return $thread->refresh();
        });
    }
}
