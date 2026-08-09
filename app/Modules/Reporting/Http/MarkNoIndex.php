<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * docs/specs/10-documents.md 17.2 - the verification page is `noindex`.
 * Delivered as an X-Robots-Tag response header (the layouts expose no <head>
 * stack, and the header form covers every response the route can produce,
 * including redirects).
 */
final class MarkNoIndex
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
