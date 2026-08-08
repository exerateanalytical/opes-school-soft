<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Livewire\Marks;

use App\Modules\Assessment\Actions\SaveMarkBatch;
use App\Modules\Assessment\Actions\SubmitMarksForValidation;
use App\Modules\Assessment\Domain\MarkState;
use App\Modules\Assessment\Models\Mark;
use App\Modules\Identity\Domain\Permission;
use App\Support\Score\Score;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The marks-entry screen — docs/specs/01-assessment.md §17.
 * Mockup: `frontend images/Results management.png`.
 *
 * ══ What this screen is ══════════════════════════════════════════════════
 *
 * One grid for one (class group × subject allocation × period × component).
 * One row per enrollment, in class-list order, showing the student, the mark
 * AND ITS STATE, and the workflow stage the row has reached.
 *
 * ══ Why it is not an x-list-screen ═══════════════════════════════════════
 *
 * Every other list in this product composes `x-list-screen`, which REQUIRES a
 * LengthAwarePaginator (00-core §6.2 rule 8). This grid must not paginate: the
 * batch save carries the changed rows in one request, and a paginated grid
 * would either send a request per page — the thing §17 exists to prevent — or
 * silently lose the rows the teacher typed on page 1. So the screen composes
 * the same VOCABULARY (breadcrumb, title, KPI strip, filter bar ending in
 * Filter | Reset, one table) by hand, and bounds the query by the class group
 * instead, which is bounded by a class group's capacity. A 62-row grid is not
 * an unbounded collection.
 *
 * ══ Why almost nothing here is a Livewire binding ════════════════════════
 *
 * §17: "Keystrokes mutate a local Alpine store only. No Livewire round-trip
 * per cell — at 62 students × 2 components that is 124 requests, unusable on
 * school Wi-Fi." So the cells carry NO `wire:model`. Alpine owns the buffer,
 * the counters and the out-of-range warnings; the server sees the grid exactly
 * twice, on load and on save. T21 asserts the save half of that.
 *
 * ══ Authorisation ═══════════════════════════════════════════════════════
 *
 * `mount()` and every write gate on `marks.enter`, and the allocation itself
 * is re-checked through `Mark::mayEnter()` — §7.5's "assigned teacher OR active
 * delegation". The READ side uses the same predicate via `scopeEnterableBy()`,
 * so a teacher cannot even enumerate another class's grid: T22 calls reading
 * another allocation a failure, not only writing one. Both paths resolve
 * through one function in `Mark` precisely so they cannot drift apart.
 */
#[Layout('layouts.app')]
final class Entry extends Component
{
    #[Url]
    public int $classGroup = 0;

    #[Url]
    public int $allocation = 0;

    #[Url]
    public int $period = 0;

    #[Url]
    public int $component = 0;

    /**
     * §17: "one `idempotency_key` for the form instance". Autosave, the manual
     * save and a retry after a dropped connection all carry the SAME key, so a
     * save that succeeded server-side but never reached the browser cannot be
     * applied twice.
     */
    public string $formKey = '';

    /**
     * The §7.7 / T16 payload, surfaced to the teacher rather than thrown.
     *
     * @var list<array{mark_id: int, expected_version: int, current_version: int, their_score: string|null, their_state: string, their_actor_id: int|null, their_actor_name: string, changed_at: string|null, message: string}>
     */
    public array $conflicts = [];

    public string $notice = '';

    public string $problem = '';

    public int $savedCount = 0;

    public function mount(): void
    {
        // Hiding the nav item is presentation; the screen refuses on its own
        // (00-core §6.2).
        Gate::authorize(Permission::MarksEnter->value);

        $this->formKey = (string) Str::uuid();

        // A scope arriving on the query string is attacker-controlled. Refuse
        // it here rather than rendering an empty grid, which reads as "this
        // class has no marks" and is a different, wrong statement.
        if ($this->allocation > 0) {
            Mark::assertMayEnter($this->allocation);
        }
    }

    // -----------------------------------------------------------------------
    // Scope selector
    // -----------------------------------------------------------------------

    public function updatedAllocation(): void
    {
        $this->resetGridMessages();

        if ($this->allocation > 0) {
            Mark::assertMayEnter($this->allocation);
        }
    }

    public function updatedClassGroup(): void
    {
        $this->resetGridMessages();
    }

    public function updatedPeriod(): void
    {
        $this->resetGridMessages();
    }

    public function updatedComponent(): void
    {
        $this->resetGridMessages();
    }

    public function resetFilters(): void
    {
        $this->reset(['classGroup', 'allocation', 'period', 'component']);
        $this->resetGridMessages();
    }

    private function resetGridMessages(): void
    {
        $this->conflicts = [];
        $this->notice = '';
        $this->problem = '';
        $this->savedCount = 0;
    }

    // -----------------------------------------------------------------------
    // The one write path
    // -----------------------------------------------------------------------

    /**
     * ONE request for the whole grid — §17, T21.
     *
     * Alpine sends only the rows whose value or state actually changed, each
     * with the `version` it was loaded at. `SaveMarkBatch` applies them under
     * the optimistic lock and hands back per-row outcomes; nothing here
     * retries, resolves or overwrites a conflict on the teacher's behalf.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function saveBatch(array $rows): void
    {
        Gate::authorize(Permission::MarksEnter->value);
        $this->resetGridMessages();

        if ($this->allocation <= 0 || $this->period <= 0) {
            $this->problem = __('opes.assessment_screen.errors.no_scope');

            return;
        }

        try {
            $payload = $this->normaliseRows($rows);
        } catch (DomainException $e) {
            $this->problem = $e->getMessage();

            return;
        }

        if ($payload === []) {
            $this->notice = __('opes.assessment_screen.nothing_changed');

            return;
        }

        try {
            $outcome = app(SaveMarkBatch::class)->handle(
                subjectAllocationId: $this->allocation,
                assessmentPeriodId: $this->period,
                rows: $payload,
                idempotencyKey: $this->formKey,
            );
        } catch (AuthorizationException $e) {
            // §7.5 / T22 — reached only if the allocation changed underneath
            // the open form (a delegation expired mid-session).
            $this->problem = $e->getMessage();

            return;
        } catch (DomainException $e) {
            // Range, window, workflow and reason refusals from the Action. The
            // teacher gets the Action's own sentence: it names the maximum, the
            // window or the missing reason, which a generic "save failed"
            // cannot.
            $this->problem = $e->getMessage();

            return;
        }

        $this->savedCount = count($outcome['saved']);
        $this->conflicts = $outcome['conflicts'];

        if ($this->conflicts === []) {
            $this->notice = trans_choice(
                'opes.assessment_screen.saved_count',
                $this->savedCount,
                ['count' => $this->savedCount],
            );
        }

        // Tells Alpine to re-baseline the saved rows and KEEP the conflicting
        // ones dirty, so the teacher's typed value is still on screen next to
        // the other party's. Dropping the buffer here is how v1 lost an
        // afternoon of work.
        $this->dispatch(
            'marks-batch-saved',
            saved: $outcome['saved'],
            conflicts: array_column($this->conflicts, 'mark_id'),
        );
    }

    /**
     * §17: "Submit for validation is a separate, explicit action ... never a
     * side effect of saving." A teacher must be able to save a half-finished
     * grid without declaring it finished.
     */
    public function submitForValidation(): void
    {
        $this->resetGridMessages();

        if ($this->allocation <= 0 || $this->period <= 0 || $this->classGroup <= 0) {
            $this->problem = __('opes.assessment_screen.errors.no_scope');

            return;
        }

        try {
            $result = app(SubmitMarksForValidation::class)->handle(
                subjectAllocationId: $this->allocation,
                assessmentPeriodId: $this->period,
                classGroupId: $this->classGroup,
            );
        } catch (AuthorizationException|DomainException $e) {
            $this->problem = $e->getMessage();

            return;
        }

        $this->notice = __('opes.assessment_screen.submitted', [
            'submitted' => $result['submitted'],
            'pending' => $result['still_pending'],
        ]);
    }

    /**
     * The wire payload is user input. Shape it into `SaveMarkBatch`'s contract
     * and refuse anything else — a mark id from another class riding in on an
     * authorised batch is exactly what the Action's scoping defends against,
     * and it should not get that far.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array{mark_id: int, version: int, state: string, score?: string|null, comment?: string|null}>
     */
    private function normaliseRows(array $rows): array
    {
        $payload = [];

        foreach ($rows as $row) {
            $markId = (int) ($row['mark_id'] ?? 0);
            $version = (int) ($row['version'] ?? 0);
            $state = is_string($row['state'] ?? null) ? $row['state'] : '';

            if ($markId <= 0 || $version <= 0) {
                throw new DomainException(__('opes.assessment_screen.errors.bad_payload'));
            }

            if (MarkState::tryFrom($state) === null) {
                throw new DomainException(__('opes.assessment_screen.errors.bad_state', [
                    'state' => $state,
                ]));
            }

            $score = $row['score'] ?? null;
            $comment = $row['comment'] ?? null;

            $payload[] = [
                'mark_id' => $markId,
                'version' => $version,
                'state' => $state,
                'score' => is_scalar($score) && trim((string) $score) !== '' ? (string) $score : null,
                'comment' => is_string($comment) && trim($comment) !== '' ? trim($comment) : null,
            ];
        }

        return $payload;
    }

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /**
     * The grid. ONE query: the student's name and matricule arrive as
     * correlated sub-selects rather than through relations, because
     * `Enrollment` and `Student` are Students models and
     * tests/Architecture/ModuleBoundaryTest.php forbids naming them here — the
     * same route round it that Phase 2's student list documents.
     *
     * @return list<array{mark_id: int, enrollment_id: int, student: string, matricule: string, score: string|null, state: string, workflow_state: string, comment: string|null, version: int}>
     */
    private function grid(): array
    {
        if ($this->allocation <= 0 || $this->period <= 0 || $this->component <= 0 || $this->classGroup <= 0) {
            return [];
        }

        $rows = Mark::query()
            // T22's read half. Deny-by-default lives inside this scope.
            ->enterableBy()
            ->where('subject_allocation_id', $this->allocation)
            ->where('assessment_period_id', $this->period)
            ->where('component_id', $this->component)
            ->where('attempt_no', 1)
            ->whereExists(fn (QueryBuilder $q) => $this->openSegmentOfClassGroup($q))
            ->addSelect('marks.*')
            ->addSelect(['student_name' => $this->studentColumn('CONCAT(st.last_name, " ", st.first_name)')])
            ->addSelect(['student_matricule' => $this->studentColumn('st.matricule')])
            ->orderBy($this->studentColumn('st.last_name'))
            ->orderBy($this->studentColumn('st.first_name'))
            ->get();

        $grid = [];

        foreach ($rows as $mark) {
            $grid[] = [
                'mark_id' => (int) $mark->getKey(),
                'enrollment_id' => $mark->enrollment_id,
                'student' => is_string($mark->getAttribute('student_name'))
                    ? $mark->getAttribute('student_name')
                    : '—',
                'matricule' => is_string($mark->getAttribute('student_matricule'))
                    ? $mark->getAttribute('student_matricule')
                    : '',
                'score' => $mark->score,
                'state' => $mark->state->value,
                'workflow_state' => $mark->workflow_state->value,
                'comment' => $mark->comment,
                'version' => $mark->version,
            ];
        }

        return $grid;
    }

    /**
     * "Is this mark's enrollment in the selected class group TODAY" — the open
     * segment (07-students §5.2). A student who transferred out in January is
     * not on this teacher's grid.
     */
    private function openSegmentOfClassGroup(QueryBuilder $query): QueryBuilder
    {
        return $query->selectRaw('1')
            ->from('enrollment_segments as seg')
            ->whereColumn('seg.enrollment_id', 'marks.enrollment_id')
            ->whereNull('seg.ends_on')
            ->where('seg.class_group_id', '=', $this->classGroup);
    }

    /**
     * The expression is `literal-string` on purpose: it lands in `selectRaw`,
     * so accepting a plain string here would be the door an interpolated value
     * walks through. Every caller passes a constant.
     *
     * @param  literal-string  $expression
     */
    private function studentColumn(string $expression): QueryBuilder
    {
        return DB::table('enrollments as e')
            ->join('students as st', 'st.id', '=', 'e.student_id')
            ->whereColumn('e.id', 'marks.enrollment_id')
            ->selectRaw($expression)
            ->limit(1);
    }

    /**
     * §6.3's precedence chain as the grid needs it: the allocation's override
     * wins, otherwise the component's own maximum. The framework maximum is
     * the chain's last resort and never reached here, because a component row
     * always carries one.
     *
     * The number is shown on the column header and drives Alpine's
     * out-of-range warning. `SaveMarkBatch` resolves the same chain again
     * server-side — the client figure is a courtesy, never the check.
     */
    private function effectiveMax(): ?string
    {
        if ($this->allocation <= 0 || $this->component <= 0) {
            return null;
        }

        $override = DB::table('subject_allocations')
            ->where('id', $this->allocation)
            ->value('max_score_override');

        if (is_string($override)) {
            return Score::of($override)->toDisplayString();
        }

        $componentMax = DB::table('assessment_components')
            ->where('id', $this->component)
            ->value('max_score');

        return is_string($componentMax) ? Score::of($componentMax)->toDisplayString() : null;
    }

    /**
     * The scope selector's allocation list, restricted to what this actor may
     * actually enter. Derived from the marks themselves rather than from
     * `subject_allocations`, so the select can never offer a scope the grid
     * would then refuse to show.
     *
     * @return list<array{id: int, label: string}>
     */
    private function allocationOptions(): array
    {
        $ids = Mark::query()
            ->enterableBy()
            ->distinct()
            ->pluck('subject_allocation_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($ids === []) {
            return [];
        }

        $rows = DB::table('subject_allocations as sa')
            ->join('subjects as s', 's.id', '=', 'sa.subject_id')
            ->join('class_levels as cl', 'cl.id', '=', 'sa.class_level_id')
            ->whereIn('sa.id', $ids)
            ->orderBy('cl.order_index')
            ->orderBy('s.name')
            ->select(['sa.id as id', 's.name as subject', 'cl.name as level'])
            ->get();

        $options = [];

        foreach ($rows as $row) {
            /** @var object{id: int|string, subject: string, level: string} $row */
            $options[] = [
                'id' => (int) $row->id,
                'label' => (string) $row->subject.' — '.(string) $row->level,
            ];
        }

        return $options;
    }

    /**
     * Class groups that actually hold a mark this actor may enter, for the
     * same reason as above.
     *
     * @return list<array{id: int, label: string}>
     */
    private function classGroupOptions(): array
    {
        $query = Mark::query()->enterableBy();

        if ($this->allocation > 0) {
            $query->where('subject_allocation_id', $this->allocation);
        }

        $enrollmentIds = $query->distinct()->pluck('enrollment_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($enrollmentIds === []) {
            return [];
        }

        $rows = DB::table('enrollment_segments as seg')
            ->join('class_groups as cg', 'cg.id', '=', 'seg.class_group_id')
            ->whereIn('seg.enrollment_id', $enrollmentIds)
            ->whereNull('seg.ends_on')
            ->groupBy('cg.id', 'cg.name')
            ->orderBy('cg.name')
            ->select(['cg.id as id', 'cg.name as name'])
            ->get();

        $options = [];

        foreach ($rows as $row) {
            /** @var object{id: int|string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'label' => (string) $row->name];
        }

        return $options;
    }

    /**
     * Leaf periods carrying a mark in scope. §4.1 forbids a mark under a
     * non-leaf period, so no filtering for that is needed — if it is here, it
     * is a leaf.
     *
     * @return list<array{id: int, label: string}>
     */
    private function periodOptions(): array
    {
        $query = Mark::query()->enterableBy();

        if ($this->allocation > 0) {
            $query->where('subject_allocation_id', $this->allocation);
        }

        $ids = $query->distinct()->pluck('assessment_period_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($ids === []) {
            return [];
        }

        $rows = DB::table('assessment_periods')
            ->whereIn('id', $ids)
            ->orderBy('order_index')
            ->select(['id', 'name', 'name_fr'])
            ->get();

        $french = app()->getLocale() === 'fr';
        $options = [];

        foreach ($rows as $row) {
            /** @var object{id: int|string, name: string, name_fr: string} $row */
            $options[] = [
                'id' => (int) $row->id,
                'label' => $french ? (string) $row->name_fr : (string) $row->name,
            ];
        }

        return $options;
    }

    /**
     * Components declared on this allocation (§5.1's `required_components`),
     * which is why an exam-only subject shows no phantom CA column.
     *
     * @return list<array{id: int, label: string, max: string}>
     */
    private function componentOptions(): array
    {
        if ($this->allocation <= 0) {
            return [];
        }

        $declared = DB::table('subject_allocations')
            ->where('id', $this->allocation)
            ->value('required_components');

        $ids = [];

        if (is_string($declared)) {
            /** @var mixed $decoded */
            $decoded = json_decode($declared, true);

            if (is_array($decoded)) {
                foreach ($decoded as $id) {
                    if (is_numeric($id)) {
                        $ids[] = (int) $id;
                    }
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        $rows = DB::table('assessment_components')
            ->whereIn('id', $ids)
            ->orderBy('order_index')
            ->select(['id', 'code', 'name', 'name_fr', 'max_score'])
            ->get();

        $french = app()->getLocale() === 'fr';
        $options = [];

        foreach ($rows as $row) {
            /** @var object{id: int|string, code: string, name: string, name_fr: string, max_score: string} $row */
            $options[] = [
                'id' => (int) $row->id,
                'label' => ($french ? (string) $row->name_fr : (string) $row->name).' ('.(string) $row->code.')',
                'max' => Score::of((string) $row->max_score)->toDisplayString(),
            ];
        }

        return $options;
    }

    /**
     * The four states §17's single-key shortcuts set, in shortcut order.
     *
     * `pending` is present as the CLEAR action rather than as a fifth choice:
     * §6.4 makes "nobody has said what happened here" a real state that blocks
     * publication, so Del restores it rather than writing a zero.
     *
     * @return list<array{value: string, key: string, label: string, marker: string}>
     */
    private function stateControls(): array
    {
        $controls = [];

        foreach (MarkState::enterableCases() as $state) {
            $controls[] = [
                'value' => $state->value,
                'key' => match ($state) {
                    MarkState::AbsentUnjustified => 'a',
                    MarkState::AbsentJustified => 'j',
                    MarkState::Exempt => 'x',
                    default => '',
                },
                'label' => __('opes.assessment_screen.state_'.$state->value),
                'marker' => $state->printedMarker() ?? '',
            ];
        }

        return $controls;
    }

    public function render(): mixed
    {
        return view('livewire.assessment.marks-entry', [
            'grid' => $this->grid(),
            'effectiveMax' => $this->effectiveMax(),
            'allocationOptions' => $this->allocationOptions(),
            'classGroupOptions' => $this->classGroupOptions(),
            'periodOptions' => $this->periodOptions(),
            'componentOptions' => $this->componentOptions(),
            'stateControls' => $this->stateControls(),
            'canSubmit' => Gate::allows(Permission::MarksEnter->value)
                && $this->allocation > 0 && $this->period > 0 && $this->classGroup > 0,
        ]);
    }
}
