<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Laravel\getJson;

require_once __DIR__.'/ApiTestHelpers.php';

uses(RefreshDatabase::class);

/*
 * Named rate limiters (docs/plans/phase-12-13.md 12.4): `api` at 60/min
 * backing the api middleware group, `verify` at 10/min/IP created now for
 * Phase 13's public /verify endpoint (10-documents 17.2).
 */

it('defines the api limiter at 60 per minute keyed by the authenticated user', function () {
    $limiter = RateLimiter::limiter('api');

    if ($limiter === null) {
        throw new RuntimeException('The named api limiter is not registered.');
    }

    $user = User::factory()->create();
    $request = Request::create('/api/v1/students');
    $request->setUserResolver(fn (): User => $user);

    /** @var Limit $limit */
    $limit = $limiter($request);

    expect($limit->maxAttempts)->toBe(60);
    expect((string) $limit->key)->toContain('user:'.$user->id);
});

it('keys the api limiter by IP before authentication', function () {
    $limiter = RateLimiter::limiter('api');

    if ($limiter === null) {
        throw new RuntimeException('The named api limiter is not registered.');
    }

    $request = Request::create('/api/v1/students', 'GET', [], [], [], ['REMOTE_ADDR' => '10.9.8.7']);

    /** @var Limit $limit */
    $limit = $limiter($request);

    expect($limit->maxAttempts)->toBe(60);
    expect((string) $limit->key)->toContain('ip:10.9.8.7');
});

it('defines the verify limiter at 10 per minute per IP', function () {
    $limiter = RateLimiter::limiter('verify');

    if ($limiter === null) {
        throw new RuntimeException('The named verify limiter is not registered.');
    }

    $request = Request::create('/verify/some-token', 'GET', [], [], [], ['REMOTE_ADDR' => '10.1.2.3']);

    /** @var Limit $limit */
    $limit = $limiter($request);

    expect($limit->maxAttempts)->toBe(10);
    expect((string) $limit->key)->toContain('verify:10.1.2.3');
});

it('returns 429 with rate limit headers once the api budget is exhausted', function () {
    // The framework's middleware priority runs Authenticate before
    // ThrottleRequests, so the budget being proven here is the
    // authenticated caller's: 60 requests pass, the 61st is refused with
    // Retry-After before the controller ever runs.
    $user = p12apiUserWithPermissions(\App\Modules\Identity\Domain\Permission::StudentsView);
    $headers = p12apiBearerHeaders($user, [\App\Modules\Identity\Domain\Permission::StudentsView->value]);

    for ($i = 0; $i < 60; $i++) {
        getJson('/api/v1/students', $headers)->assertStatus(200);
    }

    $response = getJson('/api/v1/students', $headers);

    $response->assertStatus(429);
    $response->assertHeader('Retry-After');
});

it('advertises the 60-request budget in the rate limit headers', function () {
    $user = p12apiUserWithPermissions(\App\Modules\Identity\Domain\Permission::StudentsView);
    $headers = p12apiBearerHeaders($user, [\App\Modules\Identity\Domain\Permission::StudentsView->value]);

    $response = getJson('/api/v1/students', $headers);

    $response->assertStatus(200);
    $response->assertHeader('X-RateLimit-Limit', '60');
    $response->assertHeader('X-RateLimit-Remaining', '59');
});
