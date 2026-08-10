<?php

declare(strict_types=1);

use App\Modules\Forms\Actions\DiscardDraft;
use App\Modules\Forms\Actions\HoldDraft;
use App\Modules\Forms\Actions\ResumeDraft;
use App\Modules\Forms\Actions\SaveDraft;
use App\Modules\Forms\Actions\SweepUnfinishedWork;
use App\Modules\Forms\Domain\DraftStatus;
use App\Modules\Forms\Models\FormDraft;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * The autosave + hold/resume mechanism behind every popup form
 * (AutosavesDraft), and the sweep that turns a stale held draft into a
 * notification.
 */

function draftActor(): User
{
    (new \Database\Seeders\RolePermissionSeeder())->run();

    $user = User::factory()->create();
    $user->assignRole(Role::SuperAdmin->value);
    Auth::setUser($user);

    return $user;
}

it('upserts on repeated autosave rather than creating a new row per keystroke', function (): void {
    $actor = draftActor();

    app(SaveDraft::class)->handle((int) $actor->getKey(), 'test.form', ['title' => 'A']);
    app(SaveDraft::class)->handle((int) $actor->getKey(), 'test.form', ['title' => 'Ab']);
    app(SaveDraft::class)->handle((int) $actor->getKey(), 'test.form', ['title' => 'Abc']);

    // MySQL's JSON column type decodes through the query builder even
    // without going through the Eloquent model's array cast, so this reads
    // the array directly rather than string-searching a JSON blob.
    expect(FormDraft::query()->count())->toBe(1)
        ->and(FormDraft::query()->value('payload'))->toMatchArray(['title' => 'Abc']);
});

it('keeps subject-scoped drafts separate even for the same user and form', function (): void {
    $actor = draftActor();

    app(SaveDraft::class)->handle((int) $actor->getKey(), 'test.form', ['x' => 1], 'Student', 12);
    app(SaveDraft::class)->handle((int) $actor->getKey(), 'test.form', ['x' => 2], 'Student', 13);
    app(SaveDraft::class)->handle((int) $actor->getKey(), 'test.form', ['x' => 3]); // brand-new record

    expect(FormDraft::query()->count())->toBe(3);
});

it('does not let a silent autosave downgrade a held draft back to draft', function (): void {
    $actor = draftActor();

    $draft = app(SaveDraft::class)->handle((int) $actor->getKey(), 'test.form', ['title' => 'A']);
    app(HoldDraft::class)->handle((int) $draft->getKey(), (int) $actor->getKey(), 'My hold label');

    // The user reopens the form (autosave fires again as they edit further).
    app(SaveDraft::class)->handle((int) $actor->getKey(), 'test.form', ['title' => 'A edited']);

    $draft->refresh();

    expect($draft->status)->toBe(DraftStatus::Held)
        ->and($draft->hold_label)->toBe('My hold label')
        ->and($draft->payload['title'])->toBe('A edited');
});

it('refuses to hold, resume or discard a draft that belongs to someone else', function (): void {
    $owner = draftActor();
    $draft = app(SaveDraft::class)->handle((int) $owner->getKey(), 'test.form', ['x' => 1]);

    $stranger = User::factory()->create();
    $stranger->assignRole(Role::SuperAdmin->value);

    expect(fn () => app(HoldDraft::class)->handle((int) $draft->getKey(), (int) $stranger->getKey()))
        ->toThrow(DomainException::class);

    expect(fn () => app(ResumeDraft::class)->handle((int) $draft->getKey(), (int) $stranger->getKey()))
        ->toThrow(DomainException::class);

    expect(fn () => app(DiscardDraft::class)->handle((int) $draft->getKey(), (int) $stranger->getKey()))
        ->toThrow(DomainException::class);
});

it('finds the live draft for a user/form/subject and none for a different subject', function (): void {
    $actor = draftActor();
    app(SaveDraft::class)->handle((int) $actor->getKey(), 'test.form', ['x' => 1], 'Student', 12);

    $found = app(ResumeDraft::class)->findFor((int) $actor->getKey(), 'test.form', 'Student', 12);
    $notFound = app(ResumeDraft::class)->findFor((int) $actor->getKey(), 'test.form', 'Student', 999);

    expect($found)->not->toBeNull()
        ->and($notFound)->toBeNull();
});

it('notifies once for a stale held draft and does not re-notify while it is still unread', function (): void {
    $actor = draftActor();

    $draft = app(SaveDraft::class)->handle((int) $actor->getKey(), 'test.form', ['title' => 'Parked']);
    app(HoldDraft::class)->handle((int) $draft->getKey(), (int) $actor->getKey());

    DB::table('form_drafts')->where('id', $draft->getKey())->update(['updated_at' => now()->subHours(2)]);

    $first = app(SweepUnfinishedWork::class)->handle(60);
    $second = app(SweepUnfinishedWork::class)->handle(60);

    expect($first)->toBe(1)
        ->and($second)->toBe(0)
        ->and(DB::table('notifications')->where('user_id', $actor->getKey())->count())->toBe(1);
});

it('does not notify a held draft that is still fresh', function (): void {
    $actor = draftActor();

    $draft = app(SaveDraft::class)->handle((int) $actor->getKey(), 'test.form', ['title' => 'Just parked']);
    app(HoldDraft::class)->handle((int) $draft->getKey(), (int) $actor->getKey());

    $count = app(SweepUnfinishedWork::class)->handle(60);

    expect($count)->toBe(0)
        ->and(DB::table('notifications')->count())->toBe(0);
});

it('discards the whole autosave row so a completed form has nothing left to resume', function (): void {
    $actor = draftActor();
    $draft = app(SaveDraft::class)->handle((int) $actor->getKey(), 'test.form', ['x' => 1]);

    app(DiscardDraft::class)->handle((int) $draft->getKey(), (int) $actor->getKey());

    expect(FormDraft::query()->count())->toBe(0);
});
