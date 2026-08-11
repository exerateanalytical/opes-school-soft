<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Support\Portal;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The published report-card snapshots a guardian may read, and the narrowing
 * 7.5 rows 9 and 10 put on them.
 *
 * Extracted from Livewire\Portal\Results so the portal screen and the mobile
 * API run the SAME query rather than two that must be kept in step. The rules
 * this class carries are the ones that would be most dangerous to duplicate:
 *
 *   Row 8 - "publication state is checked first, always". Nothing here reads
 *   `marks` or `period_results`. It reads `report_card_snapshots` joined to
 *   `period_publications.status = 'published'`, so an unpublished period has
 *   no row to select in the first place. The matrix's row-8 `false` is
 *   redundant with this query by construction, not a second gate to sync.
 *
 *   01-assessment 13.3 - "the snapshot is authoritative... never recompute".
 *   Every number comes out of the stored payload, the same document
 *   PublishPeriod wrote, so the portal, the app and the printed card cannot
 *   disagree about an average.
 *
 *   Row 9 - the payload is per-ENROLLMENT, so there is no other student's row
 *   to leak. `rank` is additionally stripped when the capability is absent.
 *
 *   Row 10 - `receives_reports` is not enough; the promotion decision must
 *   also have been APPLIED (`applied_enrollment_id IS NOT NULL`).
 */
final class PublishedResults
{
    /**
     * Published, non-superseded snapshots for a student, newest period first.
     *
     * @return Collection<int, \stdClass>
     */
    public function snapshots(int $studentId): Collection
    {
        $enrollmentIds = DB::table('enrollments')->where('student_id', $studentId)->pluck('id');

        if ($enrollmentIds->isEmpty()) {
            return collect();
        }

        return DB::table('report_card_snapshots as s')
            ->join('period_publications as p', 'p.id', '=', 's.period_publication_id')
            ->join('assessment_periods as ap', 'ap.id', '=', 's.assessment_period_id')
            ->whereIn('s.enrollment_id', $enrollmentIds)
            ->where('p.status', 'published')
            ->whereNull('s.superseded_by_snapshot_id')
            ->orderByDesc('ap.starts_on')
            ->get([
                's.id', 's.enrollment_id', 's.assessment_period_id', 's.generation',
                's.payload', 's.issued_at', 'ap.name as period_name', 'ap.name_fr as period_name_fr',
            ]);
    }

    /**
     * The stored payload, with row 9's `rank` removed when not granted.
     *
     * @return array<string, mixed>
     */
    public function payload(\stdClass $snapshot, bool $canSeeRank): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $snapshot->payload, true, flags: JSON_THROW_ON_ERROR);

        if (! $canSeeRank) {
            unset($payload['rank']);
        }

        return $payload;
    }

    /**
     * Row 10's second conjunct: the decision must be APPLIED. A decision that
     * has been evaluated but not applied is not a result yet, and telling a
     * parent their child has been held back before the school has acted on it
     * is exactly the disclosure that conjunct prevents.
     *
     * @return array{outcome: string|null, annual_average: string|null}|null
     */
    public function appliedPromotion(int $enrollmentId): ?array
    {
        if (! Schema::hasTable('promotion_decisions')) {
            return null;
        }

        $row = DB::table('promotion_decisions')
            ->where('enrollment_id', $enrollmentId)
            ->whereNotNull('applied_enrollment_id')
            ->first(['outcome', 'decision', 'annual_average']);

        if ($row === null) {
            return null;
        }

        return [
            'outcome' => is_string($row->outcome ?? null)
                ? $row->outcome
                : (is_string($row->decision ?? null) ? $row->decision : null),
            'annual_average' => is_string($row->annual_average ?? null) ? $row->annual_average : null,
        ];
    }
}
