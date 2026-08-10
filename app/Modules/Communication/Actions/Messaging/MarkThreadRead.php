<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions\Messaging;

use App\Modules\Communication\Models\MessageThreadParticipant;

use Illuminate\Support\Facades\DB;

/**
 * Stamps `last_read_message_id` (the watermark the unread count actually
 * uses) and `last_read_at` (display only) for one participant.
 */
final class MarkThreadRead
{
    public function handle(int $threadId, int $userId): void
    {
        $latestMessageId = DB::table('messages')
            ->where('message_thread_id', $threadId)
            ->max('id');

        MessageThreadParticipant::query()
            ->where('message_thread_id', $threadId)
            ->where('user_id', $userId)
            ->update([
                'last_read_at' => now(),
                'last_read_message_id' => $latestMessageId,
            ]);
    }
}
