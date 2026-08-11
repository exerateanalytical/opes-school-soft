<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Http\Api;

use App\Modules\Guardians\Support\Portal\GuardianSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Slice F - guardian-scoped search
 * (docs/specs/2026-08-11-guardian-mobile-api-v1.md §4 row 22 of the endpoint
 * table; build plan §8).
 *
 * A thin adapter, like every controller here. The rule - walk the caller's own
 * links and gate each source BEFORE querying it, never filter afterwards -
 * lives in Support\Portal\GuardianSearch, because the /portal search screen
 * needs exactly the same rule and search is the single worst thing in this
 * product to have two implementations of: a result count or a snippet
 * discloses that a record exists even when the record is withheld, so a
 * second, laxer copy would be a hole no test of this one could catch.
 */
final class SearchController
{
    public function __construct(private readonly GuardianSearch $search)
    {
    }

    /** `GET /v1/me/search?q=` */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'max:120'],
        ]);

        $term = trim((string) $validated['q']);
        $results = $this->search->search($term);

        return response()->json([
            'data' => ['query' => $term, 'results' => $results],
            'meta' => ['total' => count($results), 'min_length' => GuardianSearch::MIN_LENGTH],
        ]);
    }
}
