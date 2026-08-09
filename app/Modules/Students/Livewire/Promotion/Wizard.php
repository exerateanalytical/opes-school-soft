<?php

declare(strict_types=1);

namespace App\Modules\Students\Livewire\Promotion;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Students\Actions\ApplyPromotionRun;
use App\Modules\Students\Actions\EvaluatePromotionRun;
use App\Modules\Students\Actions\OverridePromotionDecision;
use App\Modules\Students\Domain\PromotionOutcome;
use App\Modules\Students\Domain\PromotionRunStatus;
use App\Modules\Students\Models\PromotionCriteriaSet;
use App\Modules\Students\Models\PromotionDecision;
use App\Modules\Students\Models\PromotionRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The Promotion Wizard (docs/specs/07-students.md §10; docs/plans/phase-08.md
 * F4): evaluate → review/override → apply.
 *
 * Wizard conventions follow Admissions\Livewire\Wizard exactly: layouts.app,
 * #[Url] run id so a reload resumes the same run, validation lives in the
 * Actions and surfaces through Livewire's error bag, Gate checked in mount()
 * AND on every write (a Livewire component is reachable without its route).
 *
 * No mockup exists for this screen; the chrome mirrors the admission wizard's
 * numbered rail + card sections, per the phase plan's UI-fidelity rule.
 */
#[Layout('layouts.app')]
final class Wizard extends Component
{
    /** String, not ?int: a query parameter arrives as text. */
    #[Url(as: 'run')]
    public string $runId = '';

    public string $academic_year_id = '';

    public string $class_group_id = '';

    public string $criteria_set_id = '';

    public string $target_academic_year_id = '';

    public string $on_indeterminate = PromotionRun::ON_INDETERMINATE_BLOCK;

    // --- Review-step override form ---------------------------------------

    /** Decision id the override form is open for; 0 = closed. */
    public int $overridingDecisionId = 0;

    public string $override_outcome = '';

    public string $override_reason = '';

    public string $statusMessage = '';

    public function mount(): void
    {
        Gate::authorize(Permission::PromotionEvaluate->value);

        if ($this->runId !== '' && $this->run() === null) {
            $this->runId = '';
        }

        if ($this->academic_year_id === '') {
            $currentYearId = DB::table('academic_years')->where('is_current', true)->value('id');

            if (is_numeric($currentYearId)) {
                $this->academic_year_id = (string) (int) $currentYearId;
            }
        }
    }

    public function evaluate(): void
    {
        Gate::authorize(Permission::PromotionEvaluate->value);

        $this->statusMessage = '';

        if (! is_numeric($this->class_group_id)
            || ! is_numeric($this->criteria_set_id)
            || ! is_numeric($this->target_academic_year_id)) {
            throw ValidationException::withMessages([
                'class_group_id' => 'Choose a class group, a criteria set and a target year.',
            ]);
        }

        $run = app(EvaluatePromotionRun::class)->handle(
            classGroupId: (int) $this->class_group_id,
            criteriaSetId: (int) $this->criteria_set_id,
            targetAcademicYearId: (int) $this->target_academic_year_id,
            onIndeterminate: $this->on_indeterminate,
        );

        $this->runId = (string) $run->getKey();
        $this->statusMessage = 'Evaluation complete — review the proposed decisions below.';
    }

    public function reevaluate(): void
    {
        $run = $this->run();

        if ($run === null) {
            return;
        }

        $this->class_group_id = (string) $run->class_group_id;
        $this->criteria_set_id = (string) $run->criteria_set_id;
        $this->target_academic_year_id = (string) $run->target_academic_year_id;
        $this->on_indeterminate = $run->on_indeterminate;

        $this->evaluate();
    }

    public function openOverride(int $decisionId): void
    {
        $this->overridingDecisionId = $decisionId;
        $this->override_outcome = '';
        $this->override_reason = '';
    }

    public function cancelOverride(): void
    {
        $this->overridingDecisionId = 0;
        $this->override_outcome = '';
        $this->override_reason = '';
    }

    public function saveOverride(): void
    {
        Gate::authorize(Permission::PromotionEvaluate->value);

        $run = $this->run();

        if ($run === null || $this->overridingDecisionId === 0) {
            return;
        }

        $decision = PromotionDecision::query()->findOrFail($this->overridingDecisionId);

        $outcome = PromotionOutcome::tryFrom($this->override_outcome);

        if ($outcome === null) {
            throw ValidationException::withMessages([
                'override_outcome' => 'Choose the outcome the conseil decided.',
            ]);
        }

        app(OverridePromotionDecision::class)->handle(
            promotionRunId: (int) $run->getKey(),
            enrollmentId: $decision->enrollment_id,
            outcome: $outcome,
            reason: $this->override_reason,
        );

        $this->cancelOverride();
        $this->statusMessage = 'Override recorded — the computed outcome stays visible beside it.';
    }

    public function apply(): void
    {
        Gate::authorize(Permission::PromotionApply->value);

        $run = $this->run();

        if ($run === null) {
            return;
        }

        app(ApplyPromotionRun::class)->handle((int) $run->getKey());

        $this->statusMessage = 'Promotion applied: the year is closed for this class and next-year enrollments exist.';
    }

    public function render(): mixed
    {
        $run = $this->run();

        $years = DB::table('academic_years')->orderByDesc('starts_on')->get(['id', 'code', 'is_current']);

        $groups = is_numeric($this->academic_year_id)
            ? DB::table('class_groups as cg')
                ->join('class_levels as cl', 'cl.id', '=', 'cg.class_level_id')
                ->where('cg.academic_year_id', (int) $this->academic_year_id)
                ->orderBy('cl.order_index')->orderBy('cg.name')
                ->get(['cg.id', 'cg.name', 'cl.name as level_name'])
            : collect();

        $criteriaSets = is_numeric($this->academic_year_id)
            ? PromotionCriteriaSet::query()
                ->where('academic_year_id', (int) $this->academic_year_id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
            : collect();

        $decisions = collect();
        $counts = ['promote' => 0, 'repeat' => 0, 'graduate' => 0, 'undecided' => 0, 'other' => 0];

        if ($run !== null) {
            $decisions = PromotionDecision::query()
                ->where('promotion_run_id', (int) $run->getKey())
                ->join('enrollments', 'enrollments.id', '=', 'promotion_decisions.enrollment_id')
                ->join('students', 'students.id', '=', 'enrollments.student_id')
                ->orderBy('students.last_name')
                ->orderBy('students.first_name')
                ->get([
                    'promotion_decisions.*',
                    'students.first_name',
                    'students.last_name',
                    'students.matricule',
                ]);

            foreach ($decisions as $decision) {
                $outcome = $decision->outcome;

                if ($outcome === null || $outcome->isUndecided()) {
                    $counts['undecided']++;
                } elseif ($outcome === PromotionOutcome::Promote || $outcome === PromotionOutcome::ConditionalPromote) {
                    $counts['promote']++;
                } elseif ($outcome === PromotionOutcome::Repeat) {
                    $counts['repeat']++;
                } elseif ($outcome === PromotionOutcome::Graduate) {
                    $counts['graduate']++;
                } else {
                    $counts['other']++;
                }
            }
        }

        $step = 1;

        if ($run !== null) {
            $step = $run->status === PromotionRunStatus::Applied ? 3 : 2;
        }

        return view('livewire.students.promotion-wizard', [
            'run' => $run,
            'years' => $years,
            'groups' => $groups,
            'criteriaSets' => $criteriaSets,
            'decisions' => $decisions,
            'counts' => $counts,
            'step' => $step,
            'canApply' => Gate::allows(Permission::PromotionApply->value),
            'overrideOutcomes' => [
                PromotionOutcome::Promote,
                PromotionOutcome::ConditionalPromote,
                PromotionOutcome::Repeat,
                PromotionOutcome::Graduate,
                PromotionOutcome::Exclude,
            ],
        ]);
    }

    private function run(): ?PromotionRun
    {
        if (! is_numeric($this->runId)) {
            return null;
        }

        /** @var PromotionRun|null $run */
        $run = PromotionRun::query()->find((int) $this->runId);

        return $run;
    }
}
