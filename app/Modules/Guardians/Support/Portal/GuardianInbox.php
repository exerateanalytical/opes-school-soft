<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Support\Portal;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Announcements, notifications and message threads for ONE signed-in user -
 * the reader behind both the portal's communication screens and the mobile
 * API's.
 *
 * A deliberate note on authorization, because it is the opposite of everything
 * else in this namespace: NONE of this is guardian-matrix territory.
 *
 *   - a notification is scoped by `notifications.user_id`. The row IS the
 *     permission.
 *   - a message thread is scoped by PARTICIPATION
 *     (`message_thread_participants`). Communication owns that rule and states
 *     it plainly: "the membership check IS the authorization".
 *   - announcements are the one exception - they need matrix row 26, checked
 *     by the CALLER before it asks for them, because "may this person see
 *     school announcements at all" is a portal-entry question.
 *
 * Forcing the matrix onto threads would be actively harmful: a teacher who
 * messaged a parent about one child would become unreachable mid-conversation
 * the day that link expired.
 *
 * Announcements are read through resolved PARTICIPANTS rather than by
 * re-deriving `announcement_recipients` scopes. The office chooses a scope
 * ("class group 4") and the send resolves it into participants; re-deriving it
 * here would be a second, drifting answer to who was actually addressed.
 */
final class GuardianInbox
{
    private const KIND_ANNOUNCEMENT = 'announcement';

    /**
     * @return Collection<int, \stdClass>
     */
    public function announcements(int $userId, int $limit = 100): Collection
    {
        if (! Schema::hasTable('message_threads')) {
            return collect();
        }

        return DB::table('message_threads as t')
            ->join('message_thread_participants as p', 'p.message_thread_id', '=', 't.id')
            ->leftJoin('messages as m', 'm.id', '=', DB::raw(
                '(SELECT MIN(m2.id) FROM messages m2 WHERE m2.message_thread_id = t.id)'
            ))
            ->where('p.user_id', $userId)
            ->whereNull('p.removed_at')
            ->where('t.kind', self::KIND_ANNOUNCEMENT)
            ->where('t.is_archived', false)
            ->orderByDesc('t.last_message_at')
            ->limit($limit)
            ->get([
                't.id', 't.title', 't.last_message_at', 'm.body',
                'p.last_read_message_id', 'm.id as first_message_id',
            ]);
    }

    /**
     * @return Collection<int, \stdClass>
     */
    public function notifications(int $userId, int $limit = 50): Collection
    {
        if (! Schema::hasTable('notifications')) {
            return collect();
        }

        return DB::table('notifications')
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'kind', 'title', 'body', 'url', 'read_at', 'created_at']);
    }

    public function unreadNotificationCount(int $userId): int
    {
        if (! Schema::hasTable('notifications')) {
            return 0;
        }

        return DB::table('notifications')
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->count();
    }

    /**
     * The messages of one thread, for a caller that has ALREADY established
     * participation. This method does not check it - `isParticipant()` is the
     * gate and must be called first.
     *
     * @return Collection<int, \stdClass>
     */
    public function messages(int $threadId, int $limit = 500): Collection
    {
        if (! Schema::hasTable('messages')) {
            return collect();
        }

        return DB::table('messages as m')
            ->leftJoin('users as u', 'u.id', '=', 'm.sender_id')
            ->where('m.message_thread_id', $threadId)
            ->orderBy('m.id')
            ->limit($limit)
            // The official-account tick matters most here: this is the screen
            // where a guardian reads a fee demand and has to know it came
            // from the school and not from someone naming themselves after it.
            ->get([
                'm.id', 'm.sender_id', 'm.body', 'm.is_system', 'm.created_at',
                'u.name as sender_name', 'u.is_official as sender_is_official',
            ]);
    }

    public function isParticipant(int $threadId, int $userId): bool
    {
        return Schema::hasTable('message_thread_participants')
            && DB::table('message_thread_participants')
                ->where('message_thread_id', $threadId)
                ->where('user_id', $userId)
                ->whereNull('removed_at')
                ->exists();
    }

    public function threadTitle(int $threadId): ?string
    {
        $title = DB::table('message_threads')->where('id', $threadId)->value('title');

        return is_string($title) ? $title : null;
    }
}
