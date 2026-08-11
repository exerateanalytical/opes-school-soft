<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Portal;

use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use App\Modules\Guardians\Support\Portal\PublishedResults;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
     * The query, the payload narrowing and the promotion conjunct now live in
     * Support\Portal\PublishedResults, so this screen and the mobile API
     * (docs/specs/2026-08-11-guardian-mobile-api-v1.md §4) cannot drift on the
     * rules that matter most - row 8's "publication checked first" and row
     * 10's "applied only". The behaviour of this screen is unchanged.
     *
     * @return Collection<int, \stdClass>
     */
    private function publishedSnapshots(): Collection
    {
        return app(PublishedResults::class)->snapshots($this->studentId);
    }

    /**
     * @return array{outcome: string|null, annual_average: string|null}|null
     */
    private function appliedPromotion(int $enrollmentId): ?array
    {
        return app(PublishedResults::class)->appliedPromotion($enrollmentId);
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
                $payload = app(PublishedResults::class)->payload($selectedRow, $canRank);

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
