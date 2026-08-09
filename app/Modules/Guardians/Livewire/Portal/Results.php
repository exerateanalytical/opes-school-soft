<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Portal;

use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `/portal/children/{student}/results` - 07-students.md 7.5 rows 5-10.
 *
 * Row 8 first: this screen NEVER reads `marks`, `period_results` or anything
 * live - it reads `report_card_snapshots` filtered to
 * `period_publications.status = 'published'`, which is the "publication
 * state is checked first, always" rule stated positively. An unpublished
 * period has no row here to select, full stop; GuardianScopeMatrix's row 8
 * `false` is redundant with this query by construction, not a second gate to
 * keep in sync.
 *
 * 01-assessment 13.3: "the snapshot is authoritative... never recompute" -
 * every number below is read straight out of the stored `payload` JSON, the
 * same document PublishPeriod wrote, so this screen and the class teacher's
 * printed card can never show two different averages for the same
 * generation.
 *
 * Row 9's narrowing ("only the child's own rank and the class denominator")
 * is automatic: the payload is per-ENROLLMENT, so there is no other
 * student's row to leak from this query in the first place.
 */
#[Layout('layouts.portal')]
final class Results extends Component
{
    public int $studentId;

    public string $childName = '';

    public ?int $selectedSnapshotId = null;

    public function mount(int $student): void
    {
        app(GuardianPortalPolicy::class)->authorize(GuardianCapability::R05ViewReportCard, $student);

        $row = DB::table('students')->where('id', $student)->first(['first_name', 'last_name']);

        if ($row === null) {
            throw new NotFoundHttpException();
        }

        $this->studentId = $student;
        $this->childName = trim($row->first_name.' '.$row->last_name);
    }

    public function selectSnapshot(int $snapshotId): void
    {
        $this->selectedSnapshotId = $snapshotId;
    }

    /**
     * @return Collection<int, \stdClass>
     */
    private function publishedSnapshots(): Collection
    {
        $enrollmentIds = DB::table('enrollments')->where('student_id', $this->studentId)->pluck('id');

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
     * Row 10: `receives_reports` alone is not enough - the promotion
     * decision must also be `applied` (07-students 7.5 row 10 / 11.5),
     * which `applied_enrollment_id IS NOT NULL` records (the promotion
     * engine's own vocabulary, docs/plans/phase-8.md).
     *
     * @return array{outcome: string|null, annual_average: string|null}|null
     */
    private function appliedPromotion(int $enrollmentId): ?array
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
            'outcome' => is_string($row->outcome ?? null) ? $row->outcome : (is_string($row->decision ?? null) ? $row->decision : null),
            'annual_average' => is_string($row->annual_average ?? null) ? $row->annual_average : null,
        ];
    }

    public function render(): mixed
    {
        $policy = app(GuardianPortalPolicy::class);
        $canRank = $policy->allows(GuardianCapability::R09ViewRankAndClassMean, $this->studentId);
        $canPromotion = $policy->allows(GuardianCapability::R10ViewAnnualAverageAndPromotion, $this->studentId);

        $snapshots = $this->publishedSnapshots();

        $periods = $snapshots->map(static fn (\stdClass $s): array => [
            'id' => (int) $s->id,
            'label' => app()->getLocale() === 'fr' ? (string) $s->period_name_fr : (string) $s->period_name,
            'generation' => (int) $s->generation,
        ])->values()->all();

        $card = null;
        $promotion = null;

        if ($snapshots->isNotEmpty()) {
            $selectedRow = $this->selectedSnapshotId !== null
                ? $snapshots->first(fn (\stdClass $s): bool => (int) $s->id === $this->selectedSnapshotId)
                : $snapshots->first();

            if ($selectedRow !== null) {
                /** @var array<string, mixed> $payload */
                $payload = json_decode((string) $selectedRow->payload, true, flags: JSON_THROW_ON_ERROR);

                if (! $canRank) {
                    unset($payload['rank']);
                }

                $card = [
                    'snapshot_id' => (int) $selectedRow->id,
                    'generation' => (int) $selectedRow->generation,
                    'issued_at' => (string) $selectedRow->issued_at,
                    'payload' => $payload,
                ];

                if ($canPromotion) {
                    $promotion = $this->appliedPromotion((int) $selectedRow->enrollment_id);
                }
            }
        }

        return view('livewire.guardians.portal.results', [
            'studentId' => $this->studentId,
            'childName' => $this->childName,
            'periods' => $periods,
            'card' => $card,
            'promotion' => $promotion,
        ]);
    }
}
