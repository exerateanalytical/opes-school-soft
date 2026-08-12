<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Portal;

use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use App\Modules\Guardians\Support\Portal\PublishedResults;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The academic views that all read the SAME published snapshots and differ
 * only in presentation:
 *
 *   subjects    mobile/subject-results.png
 *   analytics   mobile/academic-performance-analytics.png
 *   terms       mobile/term-sequence-history.png
 *   report-card mobile/report-card-viewer.png
 *   bulletin    mobile/bulletin-scolaire-report-card.png  (the French form)
 *   transcript  mobile/transcript-viewer.png
 *
 * One component with a `$view` rather than six near-identical classes. They
 * share every rule that matters - row 5 to enter, row 9 to see rank, row 10
 * plus an APPLIED decision to see promotion - and six copies of those three
 * checks is six chances for one to drift.
 *
 * Numbers are never recomputed here. 01-assessment 13.3 requires the parent's
 * copy and the printed bulletin to agree by construction, so every figure is
 * read straight out of the stored snapshot payload.
 */
#[Layout('layouts.portal')]
final class Academics extends Component
{
    public int $studentId;

    public string $childName = '';

    /** Which presentation of the same data to render. */
    public string $view = 'subjects';

    public ?int $selectedSnapshotId = null;

    public function mount(int $student, string $view = 'subjects'): void
    {
        app(GuardianPortalPolicy::class)->authorize(GuardianCapability::R05ViewReportCard, $student);

        $row = DB::table('students')->where('id', $student)->first(['first_name', 'last_name']);

        if ($row === null) {
            throw new NotFoundHttpException();
        }

        $this->studentId = $student;
        $this->childName = trim($row->first_name.' '.$row->last_name);
        $this->view = in_array($view, ['subjects', 'analytics', 'terms', 'report-card', 'bulletin', 'transcript'], true)
            ? $view
            : 'subjects';
    }

    public function selectSnapshot(int $snapshotId): void
    {
        $this->selectedSnapshotId = $snapshotId;
    }

    public function render(): mixed
    {
        $policy = app(GuardianPortalPolicy::class);
        $reader = app(PublishedResults::class);

        $canRank = $policy->allows(GuardianCapability::R09ViewRankAndClassMean, $this->studentId);
        $canPromotion = $policy->allows(GuardianCapability::R10ViewAnnualAverageAndPromotion, $this->studentId);

        $snapshots = $reader->snapshots($this->studentId);

        $periods = $snapshots->map(fn (\stdClass $s): array => [
            'id' => (int) $s->id,
            'label' => app()->getLocale() === 'fr' ? (string) $s->period_name_fr : (string) $s->period_name,
            'issued_at' => (string) $s->issued_at,
            // Each period's own payload, so the term history and the transcript
            // can list them all without a second pass over the snapshots.
            'payload' => $reader->payload($s, $canRank),
        ])->values()->all();

        $selected = $snapshots->isEmpty()
            ? null
            : ($this->selectedSnapshotId !== null
                ? $snapshots->first(fn (\stdClass $s): bool => (int) $s->id === $this->selectedSnapshotId)
                : $snapshots->first());

        $card = $selected === null ? null : [
            'snapshot_id' => (int) $selected->id,
            'generation' => (int) $selected->generation,
            'issued_at' => (string) $selected->issued_at,
            'payload' => $reader->payload($selected, $canRank),
        ];

        return view('livewire.guardians.portal.academics', [
            'studentId' => $this->studentId,
            'childName' => $this->childName,
            'view' => $this->view,
            'periods' => $periods,
            'card' => $card,
            // Row 10 AND applied. A pending decision is never shown: telling a
            // parent their child is provisionally repeating a year before the
            // school has applied it is the worst possible false alarm.
            'promotion' => $canPromotion && $selected !== null
                ? $reader->appliedPromotion((int) $selected->enrollment_id)
                : null,
        ]);
    }
}
