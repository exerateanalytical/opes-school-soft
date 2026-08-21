<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Actions\MarkNotificationRead;
use App\Modules\Notifications\Actions\Notify;
use App\Modules\Notifications\Actions\SubscribeToPush;
use App\Modules\Notifications\Actions\UnsubscribeFromPush;
use App\Modules\Notifications\Domain\NotificationKind;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\PushSubscription;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

/*
 * The in-app half of the notification engine.
 *
 * The Web Push SEND path is still not exercised here, but no longer because
 * of the machine: EC key generation works now that OpensslConfig supplies
 * openssl's configuration. What it needs is a live push endpoint to talk to.
 * Notify() calling it is proven not to blow up when push is unconfigured -
 * the realistic state of this demo box - by the first test below.
 */

function notificationActor(): User
{
    (new RolePermissionSeeder)->run();

    $user = User::factory()->create();
    $user->assignRole(Role::SuperAdmin->value);
    Auth::setUser($user);

    return $user;
}

it('creates an in-app notification even with no VAPID keys and no push subscriptions', function (): void {
    $actor = notificationActor();

    $notification = app(Notify::class)->handle(
        (int) $actor->getKey(),
        NotificationKind::System,
        'Test title',
        'Test body',
    );

    expect($notification->title)->toBe('Test title')
        ->and($notification->read_at)->toBeNull()
        ->and($notification->pushed_at)->toBeNull();
});

it('marks a single notification read and refuses to mark someone else\'s', function (): void {
    $owner = notificationActor();
    $notification = app(Notify::class)->handle((int) $owner->getKey(), NotificationKind::System, 'Mine');

    $stranger = User::factory()->create();
    $stranger->assignRole(Role::SuperAdmin->value);

    expect(fn () => app(MarkNotificationRead::class)->handle((int) $notification->getKey(), (int) $stranger->getKey()))
        ->toThrow(DomainException::class);

    $read = app(MarkNotificationRead::class)->handle((int) $notification->getKey(), (int) $owner->getKey());

    expect($read->read_at)->not->toBeNull();
});

it('marks every unread notification read in one call', function (): void {
    $actor = notificationActor();

    app(Notify::class)->handle((int) $actor->getKey(), NotificationKind::System, 'One');
    app(Notify::class)->handle((int) $actor->getKey(), NotificationKind::System, 'Two');
    app(Notify::class)->handle((int) $actor->getKey(), NotificationKind::System, 'Three');

    $count = app(MarkNotificationRead::class)->markAllRead((int) $actor->getKey());

    expect($count)->toBe(3)
        ->and(Notification::query()->whereNull('read_at')->count())->toBe(0);
});

it('upserts a push subscription on the same endpoint rather than duplicating', function (): void {
    $actor = notificationActor();

    app(SubscribeToPush::class)->handle((int) $actor->getKey(), 'https://push.example/ep1', 'p256dh-key', 'auth-key');
    app(SubscribeToPush::class)->handle((int) $actor->getKey(), 'https://push.example/ep1', 'p256dh-key-refreshed', 'auth-key');

    expect(PushSubscription::query()->count())->toBe(1)
        ->and(PushSubscription::query()->value('p256dh'))->toBe('p256dh-key-refreshed');
});

it('removes only the subscription that belongs to the requesting user', function (): void {
    $actor = notificationActor();
    app(SubscribeToPush::class)->handle((int) $actor->getKey(), 'https://push.example/ep2', 'k', 'a');

    $stranger = User::factory()->create();
    app(UnsubscribeFromPush::class)->handle((int) $stranger->getKey(), 'https://push.example/ep2');
    expect(PushSubscription::query()->count())->toBe(1);

    app(UnsubscribeFromPush::class)->handle((int) $actor->getKey(), 'https://push.example/ep2');
    expect(PushSubscription::query()->count())->toBe(0);
});
