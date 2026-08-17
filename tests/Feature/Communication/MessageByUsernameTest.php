<?php

declare(strict_types=1);

use App\Modules\Communication\Livewire\Messages\Index as MessagesIndex;
use App\Modules\Identity\Actions\SetUsername;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * The compose field used to accept an email address and nothing else, which
 * assumed every parent knows their teacher's address. It now accepts a handle
 * too - handle first, address second, because the two namespaces cannot
 * collide (a handle may not contain `@`).
 *
 * The email path is regression-tested alongside: the whole point of falling
 * back is that nothing that worked before stopped working.
 */

function byUsernameUser(string $name): User
{
    (new \Database\Seeders\RolePermissionSeeder())->run();

    return User::factory()->create(['name' => $name]);
}

it('starts a thread addressed by username', function (): void {
    $teacher = byUsernameUser('Mr Tabi');
    $guardian = byUsernameUser('Mrs Ngo');
    app(SetUsername::class)->handle($guardian, 'ngo.marie', $guardian);

    Livewire::actingAs($teacher)
        ->test(MessagesIndex::class)
        ->set('newTitle', 'Homework')
        ->set('newRecipient', 'ngo.marie')
        ->set('newBody', 'Amina did not submit her assignment today.')
        ->call('startThread')
        ->assertSet('error', '');

    $threadId = DB::table('message_thread_participants')
        ->where('user_id', $guardian->getKey())
        ->value('message_thread_id');

    expect($threadId)->not->toBeNull();
});

it('accepts a username typed with a leading at sign', function (): void {
    $teacher = byUsernameUser('Mr Tabi');
    $guardian = byUsernameUser('Mrs Ngo');
    app(SetUsername::class)->handle($guardian, 'ngo.marie', $guardian);

    Livewire::actingAs($teacher)
        ->test(MessagesIndex::class)
        ->set('newRecipient', '@Ngo.Marie')
        ->set('newBody', 'Hello.')
        ->call('startThread')
        ->assertSet('error', '');

    expect(DB::table('message_thread_participants')->where('user_id', $guardian->getKey())->exists())
        ->toBeTrue();
});

it('still starts a thread addressed by email', function (): void {
    // Regression: the username lookup runs first, and must not swallow the
    // address path it was added in front of.
    $teacher = byUsernameUser('Mr Tabi');
    $guardian = byUsernameUser('Mrs Ngo');

    Livewire::actingAs($teacher)
        ->test(MessagesIndex::class)
        ->set('newRecipient', (string) $guardian->email)
        ->set('newBody', 'Hello.')
        ->call('startThread')
        ->assertSet('error', '');

    expect(DB::table('message_thread_participants')->where('user_id', $guardian->getKey())->exists())
        ->toBeTrue();
});

it('names what it looked for when neither a handle nor an address matches', function (): void {
    $teacher = byUsernameUser('Mr Tabi');

    $component = Livewire::actingAs($teacher)
        ->test(MessagesIndex::class)
        ->set('newRecipient', 'nobody.here')
        ->set('newBody', 'Hello.')
        ->call('startThread');

    expect($component->get('error'))
        ->toContain('nobody.here')
        ->and($component->get('error'))->toContain('username');

    expect(DB::table('message_threads')->count())->toBe(0);
});
