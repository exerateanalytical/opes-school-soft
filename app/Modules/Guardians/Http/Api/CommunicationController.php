<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Http\Api;

use App\Modules\Communication\Actions\Messaging\ListThreadsForUser;
use App\Modules\Communication\Actions\Messaging\PostMessage;
use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use App\Modules\Notifications\Actions\MarkNotificationRead;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Slice E - announcements, notifications and messages
 * (docs/specs/2026-08-11-guardian-mobile-api-v1.md §4 rows 17, 20, 21 and the
 * §5 write set).
 *
 * Two authorization models meet here and must not be confused:
 *
 *   ANNOUNCEMENTS are matrix territory - row 26, granted on any valid link -
 *   because they are school information about a child's world.
 *
 *   THREADS AND NOTIFICATIONS are not. A thread is authorized by PARTICIPATION
 *   (Communication\Actions\Messaging\PostMessage's docblock is explicit: "the
 *   membership check IS the authorization"), and a notification by ownership of
 *   the row. The matrix has nothing to say about either, and forcing it to
 *   would be worse than useless - a teacher who messaged a parent about one
 *   child would become unreachable the day that link expired, mid-conversation.
 *
 * So every read below is scoped by the SANCTUM USER, and threads are reached
 * only through the Communication Actions that own the membership rule.
 */
final class CommunicationController
{
    /** A thread the school broadcast rather than a conversation. */
    private const KIND_ANNOUNCEMENT = 'announcement';

    public function __construct(
        private readonly GuardianPortalPolicy $policy,
        private readonly ListThreadsForUser $threads,
        private readonly PostMessage $poster,
        private readonly MarkNotificationRead $reader,
    ) {
    }

    /**
     * `GET /v1/me/announcements` - row 26.
     *
     * Row 26 is granted on "any valid link" without naming a child, so this is
     * not child-scoped. A guardian whose every link has expired holds nothing
     * and gets 403, which is 7.5's historic-access rule: an announcement is
     * about a school you are currently part of.
     */
    public function announcements(): JsonResponse
    {
        if (! $this->policy->allowsForAnyChild(GuardianCapability::R26ViewTimetableAndAnnouncements)) {
            abort(403);
        }

        if (! Schema::hasTable('message_threads')) {
            return response()->json(['data' => []]);
        }

        $userId = $this->userId();

        // Announcements this guardian was actually made a participant of.
        // `announcement_recipients` holds the SCOPE the office chose ("class
        // group 4"), and the migration says individual recipients are resolved
        // into participants at send time - so participation is the resolved
        // truth, and re-deriving the scope here would be a second, drifting
        // implementation of who was addressed.
        $rows = DB::table('message_threads as t')
            ->join('message_thread_participants as p', 'p.message_thread_id', '=', 't.id')
            ->leftJoin('messages as m', 'm.id', '=', DB::raw(
                '(SELECT MIN(m2.id) FROM messages m2 WHERE m2.message_thread_id = t.id)'
            ))
            ->where('p.user_id', $userId)
            ->whereNull('p.removed_at')
            ->where('t.kind', self::KIND_ANNOUNCEMENT)
            ->where('t.is_archived', false)
            ->orderByDesc('t.last_message_at')
            ->limit(100)
            ->get(['t.id', 't.title', 't.last_message_at', 'm.body', 'p.last_read_message_id', 'm.id as first_message_id']);

        return response()->json([
            'data' => $rows->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'title' => (string) $row->title,
                'body' => $row->body === null ? null : (string) $row->body,
                'published_at' => $row->last_message_at,
                'is_read' => $row->first_message_id !== null
                    && (int) ($row->last_read_message_id ?? 0) >= (int) $row->first_message_id,
            ])->values()->all(),
        ]);
    }

    /**
     * `GET /v1/me/notifications` - own rows only, by definition of the table:
     * `notifications.user_id` IS the scope.
     */
    public function notifications(Request $request): JsonResponse
    {
        if (! Schema::hasTable('notifications')) {
            return response()->json(['data' => [], 'meta' => ['unread' => 0]]);
        }

        $userId = $this->userId();
        $perPage = min(100, max(1, (int) $request->query('per_page', '25')));

        $rows = DB::table('notifications')
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->limit($perPage)
            ->get(['id', 'kind', 'title', 'body', 'url', 'read_at', 'created_at']);

        $unread = DB::table('notifications')
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'data' => $rows->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'kind' => (string) $row->kind,
                'title' => (string) $row->title,
                'body' => $row->body === null ? null : (string) $row->body,
                'deep_link' => $row->url === null ? null : (string) $row->url,
                'read_at' => $row->read_at,
                'created_at' => $row->created_at,
            ])->values()->all(),
            'meta' => ['unread' => $unread, 'per_page' => $perPage],
        ]);
    }

    /**
     * `POST /v1/me/notifications/{notification}/read`.
     *
     * MarkNotificationRead already refuses another user's row, and refusing it
     * there rather than here is the point - one owner check, in the module that
     * owns notifications. A DomainException from it means "not yours", which on
     * this surface is 404: whether a notification id exists is not a guardian's
     * business.
     */
    public function readNotification(int $notification): JsonResponse
    {
        try {
            $this->reader->handle($notification, $this->userId());
        } catch (DomainException) {
            abort(404);
        }

        return response()->json(['data' => ['id' => $notification, 'read' => true]]);
    }

    /** `POST /v1/me/notifications/read-all`. */
    public function readAllNotifications(): JsonResponse
    {
        $count = $this->reader->markAllRead($this->userId());

        return response()->json(['data' => ['marked_read' => $count]]);
    }

    /**
     * `GET /v1/me/threads` - conversations this user participates in.
     * Announcements are filtered out; they have their own endpoint and their
     * own rendering.
     */
    public function threads(): JsonResponse
    {
        if (! Schema::hasTable('message_threads')) {
            return response()->json(['data' => []]);
        }

        $threads = array_values(array_filter(
            $this->threads->handle($this->userId()),
            static fn (array $thread): bool => $thread['kind'] !== self::KIND_ANNOUNCEMENT
        ));

        return response()->json(['data' => $threads]);
    }

    /**
     * `GET /v1/me/threads/{thread}/messages`.
     *
     * The participation check is repeated here rather than assumed from the
     * list: a client may hold a thread id from any source, and the list is a
     * convenience, never a control.
     */
    public function messages(int $thread): JsonResponse
    {
        $this->requireParticipant($thread);

        $rows = DB::table('messages as m')
            ->leftJoin('users as u', 'u.id', '=', 'm.sender_id')
            ->where('m.message_thread_id', $thread)
            ->orderBy('m.id')
            ->limit(500)
            ->get([
                'm.id', 'm.sender_id', 'm.body', 'm.is_system', 'm.created_at',
                'u.name as sender_name', 'u.is_official as sender_is_official',
            ]);

        return response()->json([
            'data' => $rows->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'sender_id' => (int) $row->sender_id,
                'sender_name' => $row->sender_name === null ? null : (string) $row->sender_name,
                // So the mobile app can draw the same tick the web thread does.
                'sender_is_official' => (bool) $row->sender_is_official,
                'body' => (string) $row->body,
                'is_system' => (bool) $row->is_system,
                'sent_at' => $row->created_at,
            ])->values()->all(),
        ]);
    }

    /**
     * `POST /v1/me/threads/{thread}/messages` - the first guardian WRITE.
     *
     * 4 000 characters per spec §5. PostMessage re-checks participation inside
     * its transaction, so the check above is defence in depth rather than the
     * control - the control is in the module that owns threads.
     */
    public function sendMessage(Request $request, int $thread): JsonResponse
    {
        $this->requireParticipant($thread);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);

        try {
            $message = $this->poster->handle($thread, $this->userId(), $validated['body']);
        } catch (DomainException) {
            abort(403);
        }

        return response()->json([
            'data' => [
                'id' => (int) $message->getKey(),
                'thread_id' => $thread,
                'sent_at' => $message->created_at,
            ],
        ], 201);
    }

    /**
     * A thread this user is not in does not exist as far as this surface is
     * concerned - 404, not 403, for the same reason row 32 chooses 404.
     */
    private function requireParticipant(int $thread): void
    {
        $isParticipant = Schema::hasTable('message_thread_participants')
            && DB::table('message_thread_participants')
                ->where('message_thread_id', $thread)
                ->where('user_id', $this->userId())
                ->whereNull('removed_at')
                ->exists();

        if (! $isParticipant) {
            abort(404);
        }
    }

    private function userId(): int
    {
        $id = auth()->id();

        if ($id === null) {
            abort(401);
        }

        return (int) $id;
    }
}
