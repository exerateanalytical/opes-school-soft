<?php

declare(strict_types=1);

use App\Modules\Communication\Actions\Messaging\ListThreadsForUser;
use App\Modules\Communication\Actions\Messaging\MarkThreadRead;
use App\Modules\Communication\Actions\Messaging\PostMessage;
use App\Modules\Communication\Actions\Messaging\StartThread;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * The in-platform messenger. NOT outbox_messages, which is a one-way
 * SMS/email dispatch queue with no concept of a reply - this is a real
 * two-way conversation that never leaves the product.
 */

function messagingUser(): User
{
    (new \Database\Seeders\RolePermissionSeeder())->run();

    return User::factory()->create();
}

it('starts a thread with an opening message and adds the creator as a participant', function (): void {
    $teacher = messagingUser();
    $guardian = messagingUser();

    $thread = app(StartThread::class)->handle(
        (int) $teacher->getKey(),
        'About Amina\'s homework',
        [(int) $guardian->getKey()],
        'Amina did not submit her assignment today.',
    );

    expect($thread->messages()->count())->toBe(1)
        ->and($thread->participants()->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$teacher->getKey(), $guardian->getKey()])->sort()->values()->all());
});

it('refuses an empty opening message', function (): void {
    $teacher = messagingUser();
    $guardian = messagingUser();

    expect(fn () => app(StartThread::class)->handle(
        (int) $teacher->getKey(),
        'Empty',
        [(int) $guardian->getKey()],
        '   ',
    ))->toThrow(DomainException::class);
});

it('lets a participant reply and bumps last_message_at', function (): void {
    $teacher = messagingUser();
    $guardian = messagingUser();

    $thread = app(StartThread::class)->handle(
        (int) $teacher->getKey(), 'Thread', [(int) $guardian->getKey()], 'Hello.',
    );

    $before = $thread->last_message_at;

    sleep(1);

    app(PostMessage::class)->handle((int) $thread->getKey(), (int) $guardian->getKey(), 'Thank you for telling me.');

    $thread->refresh();

    expect($thread->messages()->count())->toBe(2)
        ->and($thread->last_message_at->gt($before))->toBeTrue();
});

it('refuses a reply from someone who is not a participant', function (): void {
    $teacher = messagingUser();
    $guardian = messagingUser();
    $stranger = messagingUser();

    $thread = app(StartThread::class)->handle(
        (int) $teacher->getKey(), 'Thread', [(int) $guardian->getKey()], 'Hello.',
    );

    // The membership check IS the authorization: a stranger cannot post
    // into a thread they were never added to, with no separate permission
    // check needed - who may speak is entirely determined by who was
    // invited.
    expect(fn () => app(PostMessage::class)->handle(
        (int) $thread->getKey(), (int) $stranger->getKey(), 'I should not be able to say this.',
    ))->toThrow(DomainException::class);
});

it('computes an unread count from last_read_at, not a stored flag', function (): void {
    $teacher = messagingUser();
    $guardian = messagingUser();

    $thread = app(StartThread::class)->handle(
        (int) $teacher->getKey(), 'Thread', [(int) $guardian->getKey()], 'First message.',
    );

    app(PostMessage::class)->handle((int) $thread->getKey(), (int) $teacher->getKey(), 'Second message.');

    $guardianInbox = collect(app(ListThreadsForUser::class)->handle((int) $guardian->getKey()))
        ->keyBy('id');

    // The guardian never read anything: both messages are unread.
    expect($guardianInbox[$thread->getKey()]['unread_count'])->toBe(2);

    app(MarkThreadRead::class)->handle((int) $thread->getKey(), (int) $guardian->getKey());

    $afterRead = collect(app(ListThreadsForUser::class)->handle((int) $guardian->getKey()))
        ->keyBy('id');

    expect($afterRead[$thread->getKey()]['unread_count'])->toBe(0);
});

it('counts a reply as unread even when it lands in the same second as the read stamp', function (): void {
    // Regression: the unread count is a MESSAGE ID watermark, not a
    // last_read_at timestamp comparison. MySQL DATETIME is second-granular,
    // so a reply created in the same second as a prior read compares EQUAL
    // under `created_at > cutoff` and silently reads as zero unread. This
    // reproduces that exact same-second condition by forcing both rows to
    // an identical created_at rather than relying on real-clock timing,
    // which passed by luck as often as it failed.
    $teacher = messagingUser();
    $guardian = messagingUser();

    $thread = app(StartThread::class)->handle(
        (int) $teacher->getKey(), 'Thread', [(int) $guardian->getKey()], 'Opening message.',
    );

    $opening = $thread->messages()->firstOrFail();

    // The guardian has read up to and including the OPENING message.
    app(\App\Modules\Communication\Actions\Messaging\MarkThreadRead::class)
        ->handle((int) $thread->getKey(), (int) $guardian->getKey());

    $reply = app(PostMessage::class)->handle((int) $thread->getKey(), (int) $teacher->getKey(), 'Reply.');

    // Force the collision directly: the guardian's read stamp and the
    // teacher's reply now share the exact same DATETIME value. A
    // timestamp-based `created_at > cutoff` comparison would read this as
    // "not newer than my last read" and report zero unread, even though
    // the reply's ID is strictly greater than the opening message's.
    $frozenInstant = now();

    \App\Modules\Communication\Models\Message::query()
        ->whereKey($reply->getKey())
        ->update(['created_at' => $frozenInstant]);

    \App\Modules\Communication\Models\MessageThreadParticipant::query()
        ->where('message_thread_id', $thread->getKey())
        ->where('user_id', (int) $guardian->getKey())
        ->update(['last_read_at' => $frozenInstant, 'last_read_message_id' => $opening->getKey()]);

    $guardianInbox = collect(app(ListThreadsForUser::class)->handle((int) $guardian->getKey()))
        ->keyBy('id');

    expect($guardianInbox[$thread->getKey()]['unread_count'])->toBe(1);
});

it('never shows a thread to a user who is not a participant', function (): void {
    $teacher = messagingUser();
    $guardian = messagingUser();
    $stranger = messagingUser();

    app(StartThread::class)->handle(
        (int) $teacher->getKey(), 'Private thread', [(int) $guardian->getKey()], 'Confidential.',
    );

    $strangerInbox = app(ListThreadsForUser::class)->handle((int) $stranger->getKey());

    expect($strangerInbox)->toBe([]);
});
