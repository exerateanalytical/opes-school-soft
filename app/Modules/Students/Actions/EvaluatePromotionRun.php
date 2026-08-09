<?php

declare(strict_types=1);

namespace App\Modules\Students\Actions;

use App\Modules\Assessment\Actions\GetAnnualAveragesForEnrollments;
use App\Modules\Attendance\Actions\GetAttendanceRateForEnrollments;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Students\Domain\Comparator;
use App\Modules\Students\Domain\CriterionType;
use App\Modules\Students\Domain\PromotionOutcome;
use App\Modules\Students\Domain\PromotionRunStatus;
use App\Modules\Students\Models\Enrollment;
use App\Modules\Students\Models\PromotionCriteriaSet;
use App\Modules\Students\Models\PromotionCriterion;
use App\Modules\Students\Models\PromotionDecision;
use App\Modules\Students\Models\PromotionRun;
use App\Modules\Students\Support\PromotionInputsHasher;
use App\Modules\Welfare\Actions\GetDisciplineCountsForEnrollments;
use App\Support\Audit\Actor;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/07-students.md §10 — evaluate a class group against a criteria
 * set and PERSIST the result: decisions with per-criterion explanations, and
 * the §10.3 inputs hash that ApplyPromotionRun re-validates days later.
 *
 * Every number comes through a door, never a re-derivation:
 *  - annual average: Assessment's GetAnnualAveragesForEnrollments — "the
 *    same annual-average service the report card uses" (§10.4, the v1
 *    13.13-vs-13.28 defect);
 *  - attendance rate: Attendance's GetAttendanceRateForEnrollments (§9.6).
 *    NULL rate ⇒ INDETERMINATE, never a pass (C5);
 *  - discipline: Welfare's GetDisciplineCountsForEnrollments;
 *  - fee balance: 04-fees tables via DB::table, ADVISORY by default;
 *  - conseil: where a ConseilDecision exists it OVERRIDES every computed
 *    criterion (§10.4 last row).
 *
 * `FOR UPDATE` on the run row for the duration (00-core §11); re-evaluation
 * of an un-applied run is the supported "offer re-evaluation" path of §10.3.
 */
final class EvaluatePromotionRun
{
    public const PERMISSION = Permission::PromotionEvaluate->value;

    public function __construct(
        private readonly GetAnnualAveragesForEnrollments $annualAverages,
        private readonly GetAttendanceRateForEnrollments $attendanceRates,
        private readonly GetDisciplineCountsForEnrollments $disciplineCounts,
        private readonly PromotionInputsHasher $hasher,
        private readonly WriteAuditEntry $audit,
    ) {}

    public function handle(
        int $classGroupId,
        int $criteriaSetId,
        int $targetAcademicYearId,
        string $onIndeterminate = PromotionRun::ON_INDETERMINATE_BLOCK,
        ?string $idempotencyKey = null,
        ?Actor $actor = null,
    ): PromotionRun {
        Gate::authorize(self::PERMISSION);

        if (! in_array($onIndeterminate, [
            PromotionRun::ON_INDETERMINATE_BLOCK,
            PromotionRun::ON_INDETERMINATE_MANUAL_REVIEW,
        ], true)) {
            throw ValidationException::withMessages([
                'on_indeterminate' => "'{$onIndeterminate}' is not an on_indeterminate policy.",
            ]);
        }

        $writer = $actor ?? $this->currentActor();

        if ($writer->id === null) {
            // promotion_decisions.decided_by is NOT NULL by Phase 7's schema:
            // a promotion verdict is never unattributable.
            throw ValidationException::withMessages([
                'actor' => 'A promotion evaluation must be attributed to a user.',
            ]);
        }

        return DB::transaction(function () use (
            $classGroupId, $criteriaSetId, $targetAcademicYearId, $onIndeterminate, $idempotencyKey, $writer
        ): PromotionRun {
            $group = $this->classGroup($classGroupId);
            $academicYearId = (int) $group['academic_year_id'];
            $classLevelId = (int) $group['class_level_id'];

            $set = $this->criteriaSet($criteriaSetId, $academicYearId, $classLevelId);

            if ($targetAcademicYearId === $academicYearId
                || ! DB::table('academic_years')->where('id', $targetAcademicYearId)->exists()) {
                throw ValidationException::withMessages([
                    'target_academic_year_id' => 'The target academic year must exist and differ from the year being closed.',
                ]);
            }

            $run = $this->lockedRun(
                $classGroupId,
                $academicYearId,
                $targetAcademicYearId,
                $criteriaSetId,
                $onIndeterminate,
                $idempotencyKey ?? "promotion-{$classGroupId}-{$academicYearId}",
            );

            $yearEndsOn = (string) DB::table('academic_years')
                ->where('id', $academicYearId)
                ->value('ends_on');

            $roster = $this->roster($academicYearId, $classGroupId, $yearEndsOn);

            if ($roster === []) {
                throw ValidationException::withMessages([
                    'class_group_id' => 'No live enrollment finishes the year in this class group; there is nobody to evaluate.',
                ]);
            }

            $enrollmentIds = array_keys($roster);

            /** @var list<PromotionCriterion> $criteria */
            $criteria = $set->criteria()->get()->all();

            $inputs = $this->collectInputs($academicYearId, $enrollmentIds, $criteria);

            $isExamClass = (bool) DB::table('class_levels')
                ->where('id', $classLevelId)
                ->value('is_exam_class');

            $nextLevelId = $this->nextClassLevelId($classLevelId);

            $now = Carbon::now();

            $hashed = $this->hasher->handle(
                $academicYearId,
                $classGroupId,
                (int) $set->getKey(),
                $set->version,
                $enrollmentIds,
                $inputs['annual'],
            );

            foreach ($roster as $enrollmentId => $enrollment) {
                $evaluated = $this->evaluateOne(
                    $enrollmentId,
                    $criteria,
                    $inputs,
                    $isExamClass,
                    $onIndeterminate,
                    $nextLevelId,
                );

                /** @var PromotionOutcome $outcome */
                $outcome = $evaluated['outcome'];

                $targetLevelId = match ($outcome) {
                    PromotionOutcome::Repeat => $classLevelId,
                    PromotionOutcome::Promote, PromotionOutcome::ConditionalPromote => $nextLevelId,
                    default => null,
                };

                PromotionDecision::query()->updateOrCreate(
                    ['enrollment_id' => $enrollmentId],
                    [
                        'promotion_run_id' => (int) $run->getKey(),
                        'outcome' => $outcome,
                        'computed_outcome' => $outcome->value,
                        // The legacy Phase 7 column both consumers read; NULL
                        // for undecided outcomes, which is what keeps the
                        // rollover wizard's "undecided students" guard honest.
                        'decision' => $outcome->legacyDecision(),
                        'target_class_group_key' => null,
                        'target_class_level_id' => $targetLevelId,
                        'target_class_group_id' => null,
                        'overridden' => false,
                        'override_reason' => null,
                        'overridden_by' => null,
                        'criteria_results' => [
                            'criteria' => $evaluated['results'],
                            'inputs_fingerprint' => $hashed['fingerprints'][$enrollmentId] ?? null,
                        ],
                        'annual_average' => $inputs['annual'][$enrollmentId] ?? null,
                        'attendance_rate' => $inputs['attendance_pct'][$enrollmentId] ?? null,
                        'applied_enrollment_id' => null,
                        'decided_by' => $writer->id,
                        'decided_at' => $now,
                    ],
                );
            }

            $run->forceFill([
                'status' => PromotionRunStatus::Evaluated,
                'inputs_hash' => $hashed['hash'],
                'evaluated_at' => $now,
                'evaluated_by' => $writer->id,
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Students',
                auditableType: PromotionRun::class,
                auditableId: (int) $run->getKey(),
                after: [
                    'class_group_id' => $classGroupId,
                    'academic_year_id' => $academicYearId,
                    'criteria_set_id' => $criteriaSetId,
                    'enrollments' => count($enrollmentIds),
                    'inputs_hash' => $hashed['hash'],
                    'status' => PromotionRunStatus::Evaluated->value,
                ],
                actor: $writer,
            );

            return $run->refresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function classGroup(int $classGroupId): array
    {
        $row = DB::table('class_groups')->where('id', $classGroupId)->lockForUpdate()->first();

        if ($row === null) {
            throw ValidationException::withMessages([
                'class_group_id' => 'The class group does not exist.',
            ]);
        }

        return (array) $row;
    }

    private function criteriaSet(int $criteriaSetId, int $academicYearId, int $classLevelId): PromotionCriteriaSet
    {
        /** @var PromotionCriteriaSet|null $set */
        $set = PromotionCriteriaSet::query()->find($criteriaSetId);

        if ($set === null || ! $set->is_active) {
            throw ValidationException::withMessages([
                'criteria_set_id' => 'The criteria set does not exist or is inactive.',
            ]);
        }

        if ($set->academic_year_id !== $academicYearId) {
            throw ValidationException::withMessages([
                'criteria_set_id' => 'The criteria set belongs to a different academic year than the class group.',
            ]);
        }

        $sectionOfLevel = DB::table('class_levels')->where('id', $classLevelId)->value('school_section_id');

        if ((int) $sectionOfLevel !== $set->school_section_id) {
            throw ValidationException::withMessages([
                'criteria_set_id' => 'The criteria set belongs to a different school section.',
            ]);
        }

        if ($set->class_level_id !== null && $set->class_level_id !== $classLevelId) {
            throw ValidationException::withMessages([
                'criteria_set_id' => 'The criteria set is scoped to a different class level.',
            ]);
        }

        return $set;
    }

    /**
     * Find-or-create under uq_promotion_run, FOR UPDATE. A run that has been
     * applied (or is mid-apply) is immutable — §10.2's UNIQUE is what
     * prevents the second run for the same (group, year).
     */
    private function lockedRun(
        int $classGroupId,
        int $academicYearId,
        int $targetAcademicYearId,
        int $criteriaSetId,
        string $onIndeterminate,
        string $idempotencyKey,
    ): PromotionRun {
        /** @var PromotionRun|null $run */
        $run = PromotionRun::query()
            ->where('class_group_id', $classGroupId)
            ->where('academic_year_id', $academicYearId)
            ->lockForUpdate()
            ->first();

        if ($run === null) {
            return PromotionRun::query()->create([
                'academic_year_id' => $academicYearId,
                'class_group_id' => $classGroupId,
                'target_academic_year_id' => $targetAcademicYearId,
                'criteria_set_id' => $criteriaSetId,
                'status' => PromotionRunStatus::Evaluating,
                'on_indeterminate' => $onIndeterminate,
                'idempotency_key' => $idempotencyKey,
            ]);
        }

        if (! $run->status->allowsEvaluation()) {
            throw ValidationException::withMessages([
                'promotion_run_id' => "This class group's promotion run is {$run->status->value}; "
                    .'an applied run is never silently re-evaluated (§10.6).',
            ]);
        }

        $run->forceFill([
            'target_academic_year_id' => $targetAcademicYearId,
            'criteria_set_id' => $criteriaSetId,
            'on_indeterminate' => $onIndeterminate,
            'status' => PromotionRunStatus::Evaluating,
        ])->save();

        return $run;
    }

    /**
     * §12.6's owning rule turned into the run roster: the LIVE enrollments
     * whose segment covering the year's last day is this class group.
     *
     * @return array<int, Enrollment>
     */
    private function roster(int $academicYearId, int $classGroupId, string $yearEndsOn): array
    {
        $enrollments = Enrollment::query()
            ->where('academic_year_id', $academicYearId)
            ->whereIn('status', ['pending', 'active', 'suspended'])
            ->whereExists(function (QueryBuilder $query) use ($classGroupId, $yearEndsOn): void {
                $query->from('enrollment_segments')
                    ->whereColumn('enrollment_segments.enrollment_id', 'enrollments.id')
                    ->where('enrollment_segments.class_group_id', $classGroupId)
                    ->where('enrollment_segments.starts_on', '<=', $yearEndsOn)
                    ->where(function (QueryBuilder $inner) use ($yearEndsOn): void {
                        $inner->whereNull('enrollment_segments.ends_on')
                            ->orWhere('enrollment_segments.ends_on', '>=', $yearEndsOn);
                    });
            })
            ->orderBy('id')
            ->get();

        $roster = [];

        foreach ($enrollments as $enrollment) {
            $roster[(int) $enrollment->getKey()] = $enrollment;
        }

        return $roster;
    }

    /**
     * One batched read per source, all through doors or DB::table.
     *
     * @param  list<int>  $enrollmentIds
     * @param  list<PromotionCriterion>  $criteria
     * @return array{
     *     annual: array<int, string|null>,
     *     attendance_pct: array<int, string|null>,
     *     unjustified_hours: array<int, string>,
     *     discipline: array<int, array{count: int, max_severity: int}>,
     *     fee_balance: array<int, string>,
     *     subject: array<int, array<int, string|null>>,
     *     conseil: array<int, string|null>,
     * }
     */
    private function collectInputs(int $academicYearId, array $enrollmentIds, array $criteria): array
    {
        $annual = $this->annualAverages->handle($academicYearId, $enrollmentIds);

        $rates = $this->attendanceRates->handle($academicYearId, $enrollmentIds);
        $attendancePct = [];

        foreach ($rates as $enrollmentId => $rate) {
            $attendancePct[$enrollmentId] = $rate === null
                ? null
                : number_format($rate * 100, 3, '.', '');
        }

        $subject = [];

        foreach ($criteria as $criterion) {
            if ($criterion->type === CriterionType::SubjectMinimum && $criterion->subject_id !== null) {
                $subject[$criterion->subject_id] = $this->annualAverages->subjectAnnualAverages(
                    $academicYearId,
                    $enrollmentIds,
                    $criterion->subject_id,
                );
            }
        }

        return [
            'annual' => $annual,
            'attendance_pct' => $attendancePct,
            'unjustified_hours' => $this->unjustifiedHours($academicYearId, $enrollmentIds),
            'discipline' => $this->disciplineCounts->handle($academicYearId, $enrollmentIds),
            'fee_balance' => $this->feeBalances($enrollmentIds),
            'subject' => $subject,
            'conseil' => $this->conseilDecisions($enrollmentIds),
        ];
    }

    /**
     * §9.7 heures d'absence non justifiées, summed over the year's periods
     * from the persisted summaries (an Attendance table — DB::table read).
     *
     * @param  list<int>  $enrollmentIds
     * @return array<int, string>
     */
    private function unjustifiedHours(int $academicYearId, array $enrollmentIds): array
    {
        $hours = [];

        foreach ($enrollmentIds as $id) {
            $hours[$id] = '0.000';
        }

        if ($enrollmentIds === []) {
            return $hours;
        }

        $rows = DB::table('attendance_summaries')
            ->join(
                'assessment_periods',
                'assessment_periods.id', '=', 'attendance_summaries.assessment_period_id',
            )
            ->where('assessment_periods.academic_year_id', $academicYearId)
            ->whereIn('attendance_summaries.enrollment_id', $enrollmentIds)
            ->groupBy('attendance_summaries.enrollment_id')
            ->selectRaw(
                'attendance_summaries.enrollment_id as enrollment_id, '
                .'SUM(attendance_summaries.hours_absent_unjustified) as unjustified'
            )
            ->get();

        foreach ($rows as $row) {
            // MySQL SUM() comes back as a string — normalised, not cast to
            // float, because the comparator works on decimal strings.
            $hours[(int) $row->enrollment_id] = number_format((float) $row->unjustified, 3, '.', '');
        }

        return $hours;
    }

    /**
     * 04-fees outstanding balance, ADVISORY by default (§10.4): gross issued
     * invoices minus live allocations, in minor units. Read via DB::table —
     * the criterion needs one number, not the full statement door, and must
     * not require the evaluator to hold fee.view.
     *
     * @param  list<int>  $enrollmentIds
     * @return array<int, string>
     */
    private function feeBalances(array $enrollmentIds): array
    {
        $balances = [];

        foreach ($enrollmentIds as $id) {
            $balances[$id] = '0.000';
        }

        if ($enrollmentIds === []) {
            return $balances;
        }

        $invoiced = DB::table('invoices as i')
            ->join('invoice_lines as l', 'l.invoice_id', '=', 'i.id')
            ->whereIn('i.enrollment_id', $enrollmentIds)
            ->where('i.status', 'issued')
            ->groupBy('i.enrollment_id')
            ->selectRaw('i.enrollment_id as enrollment_id, CAST(SUM(l.amount + l.tax_amount) AS SIGNED) as total')
            ->pluck('total', 'enrollment_id');

        $allocated = DB::table('payment_allocations as pa')
            ->join('invoices as i', 'i.id', '=', 'pa.invoice_id')
            ->whereIn('i.enrollment_id', $enrollmentIds)
            ->whereNull('pa.reversed_at')
            ->groupBy('i.enrollment_id')
            ->selectRaw('i.enrollment_id as enrollment_id, CAST(SUM(pa.amount) AS SIGNED) as total')
            ->pluck('total', 'enrollment_id');

        foreach ($enrollmentIds as $id) {
            $balance = (int) ($invoiced[$id] ?? 0) - (int) ($allocated[$id] ?? 0);

            $balances[$id] = number_format($balance, 3, '.', '');
        }

        return $balances;
    }

    /**
     * §10.4 last row: where the framework requires a conseil, its decision
     * OVERRIDES every computed criterion. The conseil tables belong to a
     * later Assessment workstream — guarded like RenderReportCard guards the
     * same read, so this module neither invents nor blocks on them.
     *
     * @param  list<int>  $enrollmentIds
     * @return array<int, string|null>
     */
    private function conseilDecisions(array $enrollmentIds): array
    {
        $decisions = [];

        foreach ($enrollmentIds as $id) {
            $decisions[$id] = null;
        }

        if ($enrollmentIds === [] || ! Schema::hasTable('conseil_decisions')) {
            return $decisions;
        }

        $rows = DB::table('conseil_decisions')
            ->whereIn('enrollment_id', $enrollmentIds)
            ->whereNotNull('decided_at')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            /** @var array<string, mixed> $data */
            $data = (array) $row;
            $value = $data['decision'] ?? $data['outcome'] ?? null;

            if (is_string($value) && $value !== '') {
                $decisions[(int) $data['enrollment_id']] = $value;
            }
        }

        return $decisions;
    }

    /**
     * §10.4's verdict fold: each criterion yields pass / fail / indeterminate
     * and `is_blocking` decides — no weighted score by default. A conseil
     * decision, where present, overrides the fold entirely.
     *
     * @param  list<PromotionCriterion>  $criteria
     * @param  array{
     *     annual: array<int, string|null>,
     *     attendance_pct: array<int, string|null>,
     *     unjustified_hours: array<int, string>,
     *     discipline: array<int, array{count: int, max_severity: int}>,
     *     fee_balance: array<int, string>,
     *     subject: array<int, array<int, string|null>>,
     *     conseil: array<int, string|null>,
     * }  $inputs
     * @return array{outcome: PromotionOutcome, results: list<array<string, mixed>>}
     */
    private function evaluateOne(
        int $enrollmentId,
        array $criteria,
        array $inputs,
        bool $isExamClass,
        string $onIndeterminate,
        ?int $nextLevelId,
    ): array {
        $results = [];
        $blockingFail = false;
        $blockingIndeterminate = false;
        $conseilOutcome = null;

        foreach ($criteria as $criterion) {
            $value = $this->valueFor($criterion, $enrollmentId, $inputs);

            if ($criterion->type === CriterionType::ConseilDecision) {
                $verdict = $value === null ? 'indeterminate' : 'pass';

                if ($value !== null) {
                    $conseilOutcome = $this->mapConseil($value);
                }
            } elseif ($value === null) {
                // C5's rule generalised: a missing input is INDETERMINATE,
                // never a silent pass and never a zero.
                $verdict = 'indeterminate';
            } else {
                $verdict = $criterion->comparator->compare($value, $criterion->threshold)
                    ? 'pass'
                    : 'fail';
            }

            if ($criterion->is_blocking) {
                $blockingFail = $blockingFail || $verdict === 'fail';
                $blockingIndeterminate = $blockingIndeterminate || $verdict === 'indeterminate';
            }

            $results[] = [
                'type' => $criterion->type->value,
                'subject_id' => $criterion->subject_id,
                'comparator' => $criterion->comparator->value,
                'threshold' => $criterion->threshold,
                'value' => $value,
                'is_blocking' => $criterion->is_blocking,
                'verdict' => $verdict,
            ];
        }

        if ($conseilOutcome !== null) {
            // The conseil signed; its word beats every computed number.
            return ['outcome' => $conseilOutcome, 'results' => $results];
        }

        if ($blockingIndeterminate) {
            $outcome = $onIndeterminate === PromotionRun::ON_INDETERMINATE_MANUAL_REVIEW
                ? PromotionOutcome::ManualReview
                : PromotionOutcome::Indeterminate;

            return ['outcome' => $outcome, 'results' => $results];
        }

        if ($blockingFail) {
            return ['outcome' => PromotionOutcome::Repeat, 'results' => $results];
        }

        if ($isExamClass) {
            return ['outcome' => PromotionOutcome::Graduate, 'results' => $results];
        }

        if ($nextLevelId === null) {
            // Passed everything but the section has no higher level and this
            // is not an exam class: there is nowhere to promote TO, and
            // guessing would enrol the student into a level nobody configured.
            return ['outcome' => PromotionOutcome::ManualReview, 'results' => $results];
        }

        return ['outcome' => PromotionOutcome::Promote, 'results' => $results];
    }

    /**
     * @param  array{
     *     annual: array<int, string|null>,
     *     attendance_pct: array<int, string|null>,
     *     unjustified_hours: array<int, string>,
     *     discipline: array<int, array{count: int, max_severity: int}>,
     *     fee_balance: array<int, string>,
     *     subject: array<int, array<int, string|null>>,
     *     conseil: array<int, string|null>,
     * }  $inputs
     */
    private function valueFor(PromotionCriterion $criterion, int $enrollmentId, array $inputs): ?string
    {
        return match ($criterion->type) {
            CriterionType::AnnualAverage => $inputs['annual'][$enrollmentId] ?? null,
            CriterionType::SubjectMinimum => $criterion->subject_id === null
                ? null
                : ($inputs['subject'][$criterion->subject_id][$enrollmentId] ?? null),
            CriterionType::AttendanceRate => $inputs['attendance_pct'][$enrollmentId] ?? null,
            CriterionType::UnjustifiedAbsenceHours => $inputs['unjustified_hours'][$enrollmentId] ?? '0.000',
            CriterionType::Discipline => (string) ($inputs['discipline'][$enrollmentId]['count'] ?? 0),
            CriterionType::FeeClearance => $inputs['fee_balance'][$enrollmentId] ?? '0.000',
            CriterionType::ConseilDecision => $inputs['conseil'][$enrollmentId] ?? null,
        };
    }

    private function mapConseil(string $decision): ?PromotionOutcome
    {
        return match (strtolower($decision)) {
            'promote', 'promoted', 'admis', 'passe' => PromotionOutcome::Promote,
            'conditional_promote', 'conditional' => PromotionOutcome::ConditionalPromote,
            'repeat', 'redouble' => PromotionOutcome::Repeat,
            'graduate', 'graduated' => PromotionOutcome::Graduate,
            'exclude', 'excluded', 'exclu' => PromotionOutcome::Exclude,
            default => null,
        };
    }

    /**
     * The next level of the SAME section by order_index — where a `promote`
     * lands (§10.5's target_class_level_id).
     */
    private function nextClassLevelId(int $classLevelId): ?int
    {
        $level = DB::table('class_levels')->where('id', $classLevelId)->first();

        if ($level === null) {
            return null;
        }

        $next = DB::table('class_levels')
            ->where('school_section_id', (int) $level->school_section_id)
            ->where('order_index', '>', (int) $level->order_index)
            ->orderBy('order_index')
            ->value('id');

        return is_numeric($next) ? (int) $next : null;
    }

    private function currentActor(): Actor
    {
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
