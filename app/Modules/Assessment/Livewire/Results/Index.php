<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Livewire\Results;

use App\Modules\Assessment\Actions\CloseAssessmentPeriod;
use App\Modules\Assessment\Actions\ComputePeriodResults;
use App\Modules\Assessment\Actions\ComputeRanking;
use App\Modules\Assessment\Actions\OpenAssessmentPeriod;
use App\Modules\Assessment\Actions\PublishPeriod;
use App\Modules\Assessment\Actions\RenderReportCard;
use App\Modules\Assessment\Models\ClassStatistic;
use App\Modules\Assessment\Models\ReportCardConfig;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Results browsing screen at /assessment/results, gated `academics.view`
 * (no dedicated `assessment.view`/`results.view` case exists in
 * App\Modules\Identity\Domain\Permission - AcademicsView is the best-fit
 * existing case for "viewing computed results").
 *
 * Three tabs, one paginated DB::table() query each, mirroring
 * Welfare\Livewire\Transport\Index: Period Results (per student, from
 * `period_results`), Class Statistics (per class general-average aggregate,
 * from `class_statistics`), and Publications (from `period_publications`).
 *
 * Cross-module reads (student/class/publisher names) go through DB::table
 * joins only - never another module's Models (ModuleBoundaryTest).
 *
 * Two writes live here, each re-checking its own gate inside the Action it
 * calls (rule 17: enforced in Actions, not menus), mirroring
 * Welfare\Livewire\Visitors\Index's checkOut() shape - a direct call into an
 * Action, flashed, no intermediate state machine:
 *
 *   - computeResults(): stage 5+6 of the pipeline for one (period, class
 *     group) pair, via RenderReportCard::collect() +
 *     ComputePeriodResults::handle() + ComputeRanking::handle() - the same
 *     sequence PublishPeriod::writeSnapshots() runs, exposed here so a
 *     class's results can be (re)computed without publishing them. Gated by
 *     ComputePeriodResults/ComputeRanking's own `marks.validate` check.
 *   - publishPeriod(): PublishPeriod::handle() for a single class group,
 *     gated by that Action's own `reports.publish` check. Publishing is
 *     13.2's irreversible, guardian-facing act, so the button carries a
 *     `wire:confirm` (the marks-entry screen's own convention) before the
 *     click fires.
 */
#[Layout('layouts.app')]
#[Title('Assessment Results')]
final class Index extends Component
{
    /** Which table is showing: results | statistics | publications. */
    #[Url]
    public string $tab = 'results';

    #[Url]
    public string $period = '';

    #[Url]
    public string $classGroup = '';

    #[Url]
    public string $search = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    // ── Compute Results form ────────────────────────────────────────────
    public bool $showComputeForm = false;

    public string $computePeriodId = '';

    public string $computeClassGroupId = '';

    public function mount(): void
    {
        Gate::authorize(Permission::AcademicsView);
    }

    public function toggleComputeForm(): void
    {
        $this->showComputeForm = ! $this->showComputeForm;
    }

    /**
     * Stage 5 (ComputePeriodResults) + stage 6 (ComputeRanking) for one
     * (period, class group) pair. The same sequence PublishPeriod runs
     * before it writes snapshots - exposed on its own so a class's results
     * can be recomputed without publishing them (e.g. after a late mark
     * correction).
     */
    public function computeResults(): void
    {
        Gate::authorize(Permission::AcademicsView);

        $periodId = (int) $this->computePeriodId;
        $classGroupId = (int) $this->computeClassGroupId;

        if ($periodId <= 0 || $classGroupId <= 0) {
            $this->addError('computePeriodId', 'Choose both a period and a class before computing results.');

            return;
        }

        try {
            $collected = app(RenderReportCard::class)->collect($periodId, $classGroupId);
            app(ComputePeriodResults::class)->handle($periodId, $collected['subject_results']);
            $ranked = app(ComputeRanking::class)->handle($periodId);
        } catch (ValidationException $e) {
            $this->addError('computePeriodId', $e->getMessage());

            return;
        } catch (Throwable $e) {
            $this->addError('computePeriodId', $e->getMessage());

            return;
        }

        $processed = count($collected['enrollment_ids']);

        $this->showComputeForm = false;
        $this->tab = 'results';
        $this->period = (string) $periodId;
        $this->classGroup = (string) $classGroupId;
        $this->resetPage();

        session()->flash('status', "Results computed: {$processed} student(s) processed, {$ranked} ranked.");
    }

    /**
     * PublishPeriod for a single class group. Publication is 13.2's
     * irreversible, guardian-facing act - the Blade button that calls this
     * carries a `wire:confirm` step before the click ever reaches here.
     */
    public function publishPeriod(int $periodId, int $classGroupId): void
    {
        Gate::authorize(Permission::ReportsPublish);

        $configId = ReportCardConfig::query()->where('is_active', true)->value('id');

        if (! is_numeric($configId)) {
            $this->addError('publish', 'No active report card configuration is set up; publication has nowhere to render a layout from.');

            return;
        }

        try {
            $result = app(PublishPeriod::class)->handle($periodId, [$classGroupId], (int) $configId);
        } catch (ValidationException $e) {
            $this->addError('publish', $e->getMessage());

            return;
        } catch (Throwable $e) {
            $this->addError('publish', $e->getMessage());

            return;
        }

        $outcome = $result['results'][0] ?? null;

        if (is_array($outcome) && $outcome['outcome'] === PublishPeriod::OUTCOME_BLOCKED) {
            session()->flash('error', 'Publication blocked: '.implode(' ', $outcome['failures']));

            return;
        }

        if (is_array($outcome) && $outcome['outcome'] === PublishPeriod::OUTCOME_FAILED) {
            session()->flash('error', 'Publication failed: '.implode(' ', $outcome['failures']));

            return;
        }

        $this->resetPage();
        session()->flash('status', 'Results published for this class group; the report card is now issuable.');
    }

    /**
     * Opens a leaf assessment period: marks obligations materialise
     * (`pending` rows) for every enrolment x allocation x required
     * component in scope, so the marks-entry grid has something to show a
     * teacher. Gated by `OpenAssessmentPeriod`'s own `assessment.configure`
     * check (rule 17: enforced in the Action, not here).
     */
    public function openPeriod(int $periodId): void
    {
        try {
            app(OpenAssessmentPeriod::class)->handle($periodId, $this->actor());
        } catch (DomainException $e) {
            $this->addError('period', $e->getMessage());

            return;
        }

        $this->resetPage();
        session()->flash('status', 'Period opened; marks entry is now available.');
    }

    /**
     * Closes a leaf assessment period: entry stops and the grid freezes for
     * composition. Gated by `CloseAssessmentPeriod`'s own
     * `assessment.configure` check.
     */
    public function closePeriod(int $periodId): void
    {
        try {
            $pending = app(CloseAssessmentPeriod::class)->handle($periodId, $this->actor());
        } catch (DomainException $e) {
            $this->addError('period', $e->getMessage());

            return;
        }

        $this->resetPage();
        session()->flash('status', $pending > 0
            ? "Period closed; {$pending} mark(s) are still pending."
            : 'Period closed.');
    }

    private function actor(): Actor
    {
        /** @var \App\Modules\Identity\Models\User $user */
        $user = auth()->user();

        return $user->toAuditActor();
    }

    public function selectTab(string $tab): void
    {
        $this->tab = in_array($tab, ['results', 'statistics', 'publications', 'periods'], true)
            ? $tab
            : 'results';
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['period', 'classGroup', 'search']);
        $this->resetPage();
    }

    public function updatedPeriod(): void
    {
        $this->resetPage();
    }

    public function updatedClassGroup(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    private function resetPage(): void
    {
        $this->page = 1;
    }

    /**
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function rows(): LengthAwarePaginator
    {
        return match ($this->tab) {
            'statistics' => $this->statisticRows(),
            'publications' => $this->publicationRows(),
            'periods' => $this->periodRows(),
            default => $this->resultRows(),
        };
    }

    /**
     * Assessment Periods: only LEAF periods are listed - only a leaf can be
     * opened or closed (01-assessment 4.1; OpenAssessmentPeriod itself
     * refuses a period that has children).
     *
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function periodRows(): LengthAwarePaginator
    {
        return DB::table('assessment_periods as ap')
            ->leftJoin('assessment_periods as child', 'child.parent_id', '=', 'ap.id')
            ->whereNull('child.id')
            ->when($this->period !== '', fn ($q) => $q->where('ap.id', (int) $this->period))
            ->when($this->search !== '', fn ($q) => $q->where('ap.name', 'like', '%'.$this->search.'%'))
            ->orderBy('ap.order_index')
            ->select([
                'ap.id', 'ap.code', 'ap.name', 'ap.status', 'ap.starts_on', 'ap.ends_on',
                'ap.marks_entry_opens_at', 'ap.marks_entry_closes_at',
            ])
            ->distinct()
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * Period Results: one row per student per period, joined to the
     * enrollment/student for names and the class group and period for
     * labels.
     *
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function resultRows(): LengthAwarePaginator
    {
        return DB::table('period_results as pr')
            ->join('enrollments as e', 'e.id', '=', 'pr.enrollment_id')
            ->join('students as s', 's.id', '=', 'e.student_id')
            ->join('class_groups as cg', 'cg.id', '=', 'pr.class_group_id')
            ->join('assessment_periods as ap', 'ap.id', '=', 'pr.assessment_period_id')
            ->when($this->period !== '', fn ($q) => $q->where('pr.assessment_period_id', (int) $this->period))
            ->when($this->classGroup !== '', fn ($q) => $q->where('pr.class_group_id', (int) $this->classGroup))
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('s.first_name', 'like', '%'.$this->search.'%')
                        ->orWhere('s.last_name', 'like', '%'.$this->search.'%')
                        ->orWhere('s.matricule', 'like', '%'.$this->search.'%');
                });
            })
            ->orderBy('ap.id')
            ->orderBy('cg.name')
            ->orderByRaw('pr.rank_position IS NULL')
            ->orderBy('pr.rank_position')
            ->select([
                'pr.id', 'pr.general_average_rounded', 'pr.rank_position', 'pr.rank_denominator',
                'pr.is_pass', 'pr.is_ranked', 'pr.nc_reason',
                // Carried so the row can offer a "Print report card" link:
                // the bulletin is addressed by (enrolment, period), which is
                // the key `report_card_snapshots` is published under.
                'pr.enrollment_id', 'pr.assessment_period_id',
                'cg.name as class_group_name', 'ap.name as period_name',
                's.first_name', 's.last_name', 's.matricule',
            ])
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * Class Statistics: the general-average aggregate row per class per
     * period (subject_allocation_id = ClassStatistic::GENERAL - the
     * per-subject rows are out of scope for this browse screen).
     *
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function statisticRows(): LengthAwarePaginator
    {
        return DB::table('class_statistics as cs')
            ->join('class_groups as cg', 'cg.id', '=', 'cs.class_group_id')
            ->join('assessment_periods as ap', 'ap.id', '=', 'cs.assessment_period_id')
            ->where('cs.subject_allocation_id', ClassStatistic::GENERAL)
            ->when($this->period !== '', fn ($q) => $q->where('cs.assessment_period_id', (int) $this->period))
            ->when($this->classGroup !== '', fn ($q) => $q->where('cs.class_group_id', (int) $this->classGroup))
            ->when($this->search !== '', fn ($q) => $q->where('cg.name', 'like', '%'.$this->search.'%'))
            ->orderBy('ap.id')
            ->orderBy('cg.name')
            ->select([
                'cs.id', 'cs.n', 'cs.mean', 'cs.min_score', 'cs.max_score',
                'cs.median', 'cs.stdev_population', 'cs.pass_count', 'cs.pass_rate',
                'cg.name as class_group_name', 'ap.name as period_name',
            ])
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * Publications: one row per (period, class group) publication record,
     * with the publisher's name resolved from `users`.
     *
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function publicationRows(): LengthAwarePaginator
    {
        return DB::table('period_publications as pp')
            ->join('class_groups as cg', 'cg.id', '=', 'pp.class_group_id')
            ->join('assessment_periods as ap', 'ap.id', '=', 'pp.assessment_period_id')
            ->leftJoin('users as u', 'u.id', '=', 'pp.published_by')
            ->when($this->period !== '', fn ($q) => $q->where('pp.assessment_period_id', (int) $this->period))
            ->when($this->classGroup !== '', fn ($q) => $q->where('pp.class_group_id', (int) $this->classGroup))
            ->when($this->search !== '', fn ($q) => $q->where('cg.name', 'like', '%'.$this->search.'%'))
            ->orderByDesc('pp.published_at')
            ->orderBy('ap.id')
            ->orderBy('cg.name')
            ->select([
                'pp.id', 'pp.status', 'pp.published_at', 'pp.generation',
                'pp.unpublished_at', 'pp.unpublish_reason',
                'pp.assessment_period_id', 'pp.class_group_id',
                'cg.name as class_group_name', 'ap.name as period_name',
                'u.name as publisher_name',
            ])
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * KPI strip: count-based only, dataset-wide (never filter-dependent
     * inventions). A pass-rate KPI is deliberately omitted - class_statistics
     * only carries pass_count/pass_rate per class, and a single clean
     * dataset-wide aggregate is not cleanly derivable from one query without
     * re-deriving a weighted mean the pipeline itself does not publish.
     *
     * @return array{students_with_results: int, classes_with_statistics: int, published_periods: int}
     */
    private function kpis(): array
    {
        return [
            'students_with_results' => (int) DB::table('period_results')->count(),
            'classes_with_statistics' => (int) DB::table('class_statistics')
                ->where('subject_allocation_id', ClassStatistic::GENERAL)
                ->distinct()
                ->count('class_group_id'),
            'published_periods' => (int) DB::table('period_publications')
                ->where('status', 'published')
                ->count(),
        ];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function periodOptions(): array
    {
        $options = [];

        foreach (DB::table('assessment_periods')->orderBy('name')->get(['id', 'name']) as $row) {
            /** @var object{id: int|string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => $row->name];
        }

        return $options;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function classGroupOptions(): array
    {
        $options = [];

        foreach (DB::table('class_groups')->orderBy('name')->get(['id', 'name']) as $row) {
            /** @var object{id: int|string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => $row->name];
        }

        return $options;
    }

    public function render(): mixed
    {
        $tabCounts = [
            'results' => (int) DB::table('period_results')->count(),
            'statistics' => (int) DB::table('class_statistics')
                ->where('subject_allocation_id', ClassStatistic::GENERAL)
                ->count(),
            'publications' => (int) DB::table('period_publications')->count(),
            'periods' => (int) DB::table('assessment_periods as ap')
                ->leftJoin('assessment_periods as child', 'child.parent_id', '=', 'ap.id')
                ->whereNull('child.id')
                ->count('ap.id'),
        ];

        return view('livewire.assessment.results.index', [
            'rows' => $this->rows(),
            'kpis' => $this->kpis(),
            'tabCounts' => $tabCounts,
            'periodOptions' => $this->periodOptions(),
            'classGroupOptions' => $this->classGroupOptions(),
            'canOpenPeriod' => Gate::allows(OpenAssessmentPeriod::PERMISSION),
            'canClosePeriod' => Gate::allows(CloseAssessmentPeriod::PERMISSION),
        ]);
    }
}
