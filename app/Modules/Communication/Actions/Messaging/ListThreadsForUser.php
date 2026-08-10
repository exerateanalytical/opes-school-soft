<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions\Messaging;

use Illuminate\Support\Facades\DB;

/**
 * The inbox list: every thread a user participates in, newest first, with
 * an unread count computed from `last_read_message_id`.
 *
 * A message ID watermark, not `last_read_at`: MySQL DATETIME is
 * second-granular, so a reply landing in the same second as a read stamp
 * compares EQUAL under `created_at > cutoff` and silently reads as already
 * read. `messages.id` is a strictly increasing PK, so `m.id > p.watermark`
 * has no such collision - see the migration's docblock.
 *
 * One query, not N+1: the per-thread unread count is a correlated
 * subquery, which lets MySQL do the counting rather than pulling every
 * message row into PHP - important for a school with a thousand parents.
 */
final class ListThreadsForUser
{
    /**
     * @return list<array{id: int, title: string, kind: string, last_message_at: string|null, unread_count: int, is_archived: bool}>
     */
    public function handle(int $userId): array
    {
        $rows = DB::table('message_thread_participants as p')
            ->join('message_threads as t', 't.id', '=', 'p.message_thread_id')
            ->where('p.user_id', $userId)
            ->whereNull('p.removed_at')
            ->orderByDesc('t.last_message_at')
            ->selectRaw(
                't.id, t.title, t.kind, t.last_message_at, t.is_archived, '.
                '(SELECT COUNT(*) FROM messages m '.
                ' WHERE m.message_thread_id = t.id '.
                '   AND m.id > COALESCE(p.last_read_message_id, 0)'.
                ') AS unread_count'
            )
            ->get();

        return $rows->map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'title' => (string) $row->title,
            'kind' => (string) $row->kind,
            'last_message_at' => $row->last_message_at,
            'unread_count' => (int) $row->unread_count,
            'is_archived' => (bool) $row->is_archived,
        ])->all();
    }
}
