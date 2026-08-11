<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replay protection for the guardian portal's write endpoints
 * (docs/specs/2026-08-11-guardian-mobile-api-v1.md §5).
 *
 * A phone on a Cameroonian mobile network loses the response, not the request.
 * Without this, "send message" retried by the client posts twice, and a
 * meeting request or a payment retried posts twice - which is a data problem
 * the parent cannot undo from the app.
 *
 * The contract:
 *   - No `Idempotency-Key` header: the request proceeds untouched. The header
 *     is the client's promise, not a requirement this layer invents.
 *   - First use of a key: the request runs, and a SUCCESSFUL response
 *     (2xx) is stored for 24 hours against that key.
 *   - Repeat with the same key and the same body: the stored response is
 *     replayed, with `Idempotency-Replayed: true`. The action never runs twice.
 *   - Repeat with the same key and a DIFFERENT body: 409. A key that means two
 *     different things is a client bug, and quietly honouring either reading
 *     would hide it.
 *
 * Scoped per token owner: two guardians cannot collide on a key, and a stolen
 * key cannot be used to read back somebody else's response - the cache entry is
 * unreachable without the same authenticated user.
 *
 * Failures are deliberately NOT stored. A 422 or a 500 must be retryable; only
 * an outcome that actually happened is worth replaying.
 */
final class EnforceIdempotency
{
    /** How long a key stays claimed. */
    public const TTL_SECONDS = 86400;

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));

        if ($key === '' || ! in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            return $next($request);
        }

        $owner = $request->user()?->getAuthIdentifier() ?? 'anon';
        $cacheKey = 'idem:'.$owner.':'.hash('sha256', $key);
        $fingerprint = hash('sha256', $request->getContent());

        /** @var array{fingerprint: string, status: int, body: string}|null $stored */
        $stored = Cache::get($cacheKey);

        if (is_array($stored)) {
            if ($stored['fingerprint'] !== $fingerprint) {
                return new JsonResponse([
                    'error' => [
                        'code' => 'conflict',
                        'message' => __('opes.api.idempotency_conflict'),
                        'details' => new \stdClass,
                    ],
                ], 409);
            }

            return new JsonResponse(
                json_decode($stored['body'], true),
                $stored['status'],
                ['Idempotency-Replayed' => 'true'],
            );
        }

        $response = $next($request);

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            Cache::put($cacheKey, [
                'fingerprint' => $fingerprint,
                'status' => $response->getStatusCode(),
                'body' => (string) $response->getContent(),
            ], self::TTL_SECONDS);
        }

        return $response;
    }
}
