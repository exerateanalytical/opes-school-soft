<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Http\Api;

use App\Modules\Assessment\Models\PeriodPublication;
use App\Modules\Assessment\Models\PeriodResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only v1 published-results adapter (docs/plans/phase-12-13.md 12.4).
 *
 * PUBLISHED results only, enforced in the query, not the presenter: a period
 * result row exists from the moment ComputePeriodResults runs, but it does
 * not exist for the outside world until PublishPeriod flips the class
 * group's publication to `published` (01-assessment 13.2). The join makes an
 * unpublished result unreachable through this adapter rather than merely
 * hidden - same reasoning as the guardian portal's publication-first rule.
 *
 * Gated in routes/api.php by `can:academics.view` + `abilities:academics.view`.
 */
final class PublishedResultsController
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        $results = PeriodResult::query()
            ->join('period_publications', function ($join): void {
                $join->on('period_publications.assessment_period_id', '=', 'period_results.assessment_period_id')
                    ->on('period_publications.class_group_id', '=', 'period_results.class_group_id');
            })
            ->where('period_publications.status', PeriodPublication::STATUS_PUBLISHED)
            ->when($request->filled('assessment_period_id'), function ($query) use ($request): void {
                $query->where('period_results.assessment_period_id', $request->integer('assessment_period_id'));
            })
            ->when($request->filled('class_group_id'), function ($query) use ($request): void {
                $query->where('period_results.class_group_id', $request->integer('class_group_id'));
            })
            ->when($request->filled('enrollment_id'), function ($query) use ($request): void {
                $query->where('period_results.enrollment_id', $request->integer('enrollment_id'));
            })
            ->select('period_results.*')
            ->addSelect('period_publications.published_at as publication_published_at')
            ->orderBy('period_results.id')
            ->paginate($perPage);

        return response()->json([
            'data' => array_map(
                fn (PeriodResult $result): array => $this->present($result),
                $results->items(),
            ),
            'meta' => [
                'page' => $results->currentPage(),
                'per_page' => $results->perPage(),
                'total' => $results->total(),
                'last_page' => $results->lastPage(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PeriodResult $result): array
    {
        $publishedAt = $result->getAttribute('publication_published_at');

        return [
            'id' => $result->id,
            'assessment_period_id' => $result->assessment_period_id,
            'class_group_id' => $result->class_group_id,
            'enrollment_id' => $result->enrollment_id,
            'general_average' => $result->general_average,
            'general_average_rounded' => $result->general_average_rounded,
            'is_pass' => $result->is_pass,
            'subjects_counted' => $result->subjects_counted,
            'rank_position' => $result->rank_position,
            'rank_denominator' => $result->rank_denominator,
            'is_ranked' => $result->is_ranked,
            'nc_reason' => $result->nc_reason,
            'published_at' => is_string($publishedAt) || $publishedAt === null
                ? $publishedAt
                : (string) $publishedAt,
        ];
    }
}
