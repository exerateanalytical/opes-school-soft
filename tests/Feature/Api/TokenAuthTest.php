<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Livewire\Users\Tokens;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

require_once __DIR__.'/ApiTestHelpers.php';

uses(RefreshDatabase::class);

/*
 * docs/plans/phase-12-13.md 12.4: auth:sanctum + can: + abilities: on every
 * API route. Both the user permission AND the token ability must hold - a
 * token narrows, never widens.
 */

it('rejects an unauthenticated API request with 401 JSON', function () {
    $response = getJson('/api/v1/students');

    $response->assertStatus(401);
    $response->assertHeader('content-type', 'application/json');
});

it('rejects a token that was not issued the required ability', function () {
    // The USER holds students.view, but the TOKEN was scoped to fee.view
    // only - the integration holding it must not be able to read students.
    $user = p12apiUserWithPermissions(Permission::StudentsView, Permission::FeeView);
    $headers = p12apiBearerHeaders($user, [Permission::FeeView->value]);

    getJson('/api/v1/students', $headers)->assertStatus(403);
});

it('rejects a token whose owner lacks the permission even when the ability was granted', function () {
    // The TOKEN claims students.view, but the USER holds nothing - a token
    // can only ever narrow what its owner may already do, never widen it.
    $user = p12apiUserWithPermissions();
    $headers = p12apiBearerHeaders($user, [Permission::StudentsView->value]);

    getJson('/api/v1/students', $headers)->assertStatus(403);
});

it('accepts a token holding the ability when its owner holds the permission', function () {
    $user = p12apiUserWithPermissions(Permission::StudentsView);
    $headers = p12apiBearerHeaders($user, [Permission::StudentsView->value]);

    getJson('/api/v1/students', $headers)
        ->assertStatus(200)
        ->assertJsonStructure(['data', 'meta' => ['page', 'per_page', 'total', 'last_page']]);
});

it('accepts a first-party session without any token, gated by permissions alone', function () {
    // Sanctum's TransientToken answers yes to every ability for session
    // callers; the can: gate stays their real control.
    $user = p12apiUserWithPermissions(Permission::StudentsView);
    actingAs($user);

    getJson('/api/v1/students')->assertStatus(200);
});

it('denies a session caller without the permission', function () {
    $user = p12apiUserWithPermissions();
    actingAs($user);

    getJson('/api/v1/students')->assertStatus(403);
});

it('stops honouring a token once it is revoked', function () {
    $user = p12apiUserWithPermissions(Permission::StudentsView);
    $headers = p12apiBearerHeaders($user, [Permission::StudentsView->value]);

    getJson('/api/v1/students', $headers)->assertStatus(200);

    $user->tokens()->delete();

    // The auth manager memoises the resolved guard for the lifetime of the
    // test application; a real deployment resolves it per request. Reset so
    // the next call re-authenticates from the (now deleted) token row.
    app('auth')->forgetGuards();

    getJson('/api/v1/students', $headers)->assertStatus(401);
});

/*
 * Token management screen, /users/{user}/tokens (api.manage_tokens).
 */

it('denies the token screen to a user without api.manage_tokens', function () {
    $viewer = p12apiUserWithPermissions(Permission::UserView);
    $target = User::factory()->create();
    actingAs($viewer);

    get('/users/'.$target->id.'/tokens')->assertStatus(403);
});

it('shows the token screen to a holder of api.manage_tokens', function () {
    $admin = p12apiUserWithPermissions(Permission::ApiTokenManage);
    $target = User::factory()->create();
    actingAs($admin);

    get('/users/'.$target->id.'/tokens')->assertStatus(200);
});

it('issues a token from the screen, shows the plaintext once and stores only a hash', function () {
    $admin = p12apiUserWithPermissions(Permission::ApiTokenManage);
    $target = p12apiUserWithPermissions(Permission::StudentsView);
    actingAs($admin);

    $component = Livewire::test(Tokens::class, ['user' => $target])
        ->set('name', 'Reporting integration')
        ->set('abilities', [Permission::StudentsView->value])
        ->call('createToken')
        ->assertHasNoErrors();

    $plain = $component->get('plainTextToken');
    expect($plain)->toBeString();
    expect($plain)->not->toBe('');
    $plain = is_string($plain) ? $plain : '';

    /** @var \Laravel\Sanctum\PersonalAccessToken $row */
    $row = $target->tokens()->firstOrFail();
    expect((string) $row->name)->toBe('Reporting integration');
    expect($row->abilities)->toBe([Permission::StudentsView->value]);
    // Sanctum stores the SHA-256 of the secret part, never the plaintext.
    expect((string) $row->token)->not->toContain($plain);

    // The plaintext round-trips as a working credential. The admin's
    // actingAs session would otherwise win (Sanctum prefers the first-party
    // web guard over the bearer token), so the memoised guards are reset to
    // authenticate this request from the token alone.
    app('auth')->forgetGuards();

    getJson('/api/v1/students', [
        'Authorization' => 'Bearer '.$plain,
        'Accept' => 'application/json',
    ])->assertStatus(200);
});

it('rejects issuing a token with an ability that is not a known permission', function () {
    $admin = p12apiUserWithPermissions(Permission::ApiTokenManage);
    $target = User::factory()->create();
    actingAs($admin);

    Livewire::test(Tokens::class, ['user' => $target])
        ->set('name', 'Broken')
        ->set('abilities', ['not.a_permission'])
        ->call('createToken')
        ->assertHasErrors('abilities');

    expect($target->tokens()->count())->toBe(0);
});

it('revokes a token from the screen', function () {
    $admin = p12apiUserWithPermissions(Permission::ApiTokenManage);
    $target = p12apiUserWithPermissions(Permission::StudentsView);
    $target->createToken('doomed', [Permission::StudentsView->value]);
    /** @var \Laravel\Sanctum\PersonalAccessToken $row */
    $row = $target->tokens()->firstOrFail();
    actingAs($admin);

    Livewire::test(Tokens::class, ['user' => $target])
        ->call('revoke', $row->id);

    expect($target->tokens()->count())->toBe(0);
});

it('writes an audit entry when a token is issued and when it is revoked', function () {
    $admin = p12apiUserWithPermissions(Permission::ApiTokenManage);
    $target = p12apiUserWithPermissions(Permission::StudentsView);
    actingAs($admin);

    $component = Livewire::test(Tokens::class, ['user' => $target])
        ->set('name', 'Audited token')
        ->set('abilities', [Permission::StudentsView->value])
        ->call('createToken');

    /** @var \Laravel\Sanctum\PersonalAccessToken $row */
    $row = $target->tokens()->firstOrFail();
    $component->call('revoke', $row->id);

    $entries = \Illuminate\Support\Facades\DB::table('audit_logs')
        ->where('auditable_id', $target->id)
        ->whereIn('action', ['created', 'deleted'])
        ->get();

    expect($entries->where('action', 'created')->count())->toBeGreaterThanOrEqual(1);
    expect($entries->where('action', 'deleted')->count())->toBeGreaterThanOrEqual(1);
});
