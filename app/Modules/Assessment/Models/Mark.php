<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Models;

use App\Modules\Assessment\Domain\MarkState;
use App\Modules\Assessment\Domain\WorkflowState;
use App\Modules\Identity\Domain\Permission;
use App\Support\Clock\BusinessDate;
use App\Support\Score\Score;
use Database\Factories\MarkFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/**
 * One component mark for one enrollment in one period
 * (docs/specs/01-assessment.md §6.1).
 *
 * It carries TWO independent state axes and never derives one from the other:
 *
 *   - `state` (MarkState)          — what happened in the classroom;
 *   - `workflow_state` (WorkflowState) — who has signed it off.
 *
 * No Eloquent relations to `enrollments`, `subject_allocations` or
 * `assessment_periods` are declared even though the foreign keys exist:
 * 00-core §6.2 rule 2 forbids reaching into another module's Models, and
 * `Enrollment` lives in Students while the allocation and the period live in
 * Academics. Cross-module reads go through the query builder, which is the
 * precedent Phase 2's Enrollment set.
 *
 * @property int $id
 * @property int $enrollment_id
 * @property int $subject_allocation_id
 * @property int $assessment_period_id
 * @property int $component_id
 * @property numeric-string|null $score
 * @property MarkState $state
 * @property WorkflowState $workflow_state
 * @property int|null $entered_by
 * @property Carbon|null $entered_at
 * @property int|null $submitted_by
 * @property Carbon|null $submitted_at
 * @property int|null $validated_by
 * @property Carbon|null $validated_at
 * @property numeric-string|null $raw_score
 * @property int|null $moderation_id
 * @property int $attempt_no
 * @property string|null $comment
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Mark extends Model
{
    /** @use HasFactory<MarkFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'enrollment_id',
        'subject_allocation_id',
        'assessment_period_id',
        'component_id',
        'score',
        'state',
        'workflow_state',
        'entered_by',
        'entered_at',
        'submitted_by',
        'submitted_at',
        'validated_by',
        'validated_at',
        'raw_score',
        'moderation_id',
        'attempt_no',
        'comment',
        'version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enrollment_id' => 'int',
            'subject_allocation_id' => 'int',
            'assessment_period_id' => 'int',
            'component_id' => 'int',
            // decimal:3, never float — App\Support\Score is integer
            // thousandths and DECIMAL(6,3) is its exact storage form
            // (00-core §7.1).
            'score' => 'decimal:3',
            'raw_score' => 'decimal:3',
            'state' => MarkState::class,
            'workflow_state' => WorkflowState::class,
            'entered_by' => 'int',
            'entered_at' => 'datetime',
            'submitted_by' => 'int',
            'submitted_at' => 'datetime',
            'validated_by' => 'int',
            'validated_at' => 'datetime',
            'moderation_id' => 'int',
            'attempt_no' => 'int',
            'version' => 'int',
        ];
    }

    protected static function newFactory(): MarkFactory
    {
        return MarkFactory::new();
    }

    /**
     * The stored mark as a Score, or NULL when there is no number.
     *
     * NULL here answers "is there a number", never "what happened": `pending`,
     * `absent_justified` and `exempt` are all score-less and mean three
     * different things (§6.4). Read `state` for that.
     */
    public function scoreValue(): ?Score
    {
        return $this->score === null ? null : Score::of($this->score);
    }

    public function rawScoreValue(): ?Score
    {
        return $this->raw_score === null ? null : Score::of($this->raw_score);
    }

    // -----------------------------------------------------------------------
    // Marks-entry authorisation — §7.5, §17, T22.
    //
    // Deliberately ONE gate serving both the read path and the write path. A
    // teacher reading another class's grid is a privacy breach, not merely an
    // authorisation miss, so the two must not be able to drift apart: the
    // Actions call assertMayEnter(), the entry screen filters with
    // scopeEnterableBy(), and both resolve through mayEnter().
    // -----------------------------------------------------------------------

    /**
     * §7.5: entry is permitted where the actor is the assigned teacher for the
     * allocation OR an active delegation names them — on top of the
     * `marks.enter` permission, which is the outer gate and not the whole
     * check.
     *
     * The exams office is the documented exception: 00-core §9.1's role table
     * has the Exams Officer "fill gaps in entry", and §7.6 lets that office
     * open override windows, so a holder of `assessment.configure` is not
     * scoped to an assignment. Everybody else is, and with no assignment
     * record they are DENIED — deny by default, never "allow because we cannot
     * tell yet".
     */
    public static function mayEnter(int $subjectAllocationId, ?int $userId = null): bool
    {
        if (Gate::denies(Permission::MarksEnter->value)) {
            return false;
        }

        if (Gate::allows(Permission::AssessmentConfigure->value)) {
            return true;
        }

        $userId ??= auth()->id();

        if (! is_int($userId)) {
            return false;
        }

        return in_array($subjectAllocationId, self::allocationIdsEnterableBy($userId), true);
    }

    /**
     * @throws AuthorizationException
     */
    public static function assertMayEnter(int $subjectAllocationId, ?int $userId = null): void
    {
        if (! self::mayEnter($subjectAllocationId, $userId)) {
            throw new AuthorizationException(
                'You are not assigned or delegated to enter marks for this subject allocation.'
            );
        }
    }

    /**
     * The read side of the same gate: marks the actor is entitled to SEE.
     *
     * @param  Builder<Mark>  $query
     * @return Builder<Mark>
     */
    public function scopeEnterableBy(Builder $query, ?int $userId = null): Builder
    {
        if (Gate::denies(Permission::MarksEnter->value)) {
            return $query->whereRaw('1 = 0');
        }

        if (Gate::allows(Permission::AssessmentConfigure->value)) {
            return $query;
        }

        $userId ??= auth()->id();

        if (! is_int($userId)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('subject_allocation_id', self::allocationIdsEnterableBy($userId));
    }

    /**
     * Resolve the allocations this user teaches or has been delegated.
     *
     * Both sources are read through the query builder, never another module's
     * Models. Both are also SCHEMA-GUARDED: `MarkEntryDelegation` (§7.5) and
     * the allocation's teacher column belong to workstreams that land
     * separately, and until they exist this returns an empty set — which
     * denies, which is the correct failure direction for a privacy gate.
     *
     * @return list<int>
     */
    private static function allocationIdsEnterableBy(int $userId): array
    {
        $ids = [];

        if (Schema::hasTable('subject_allocation_teachers')) {
            /** @var list<int> $assigned */
            $assigned = DB::table('subject_allocation_teachers')
                ->where('user_id', $userId)
                ->pluck('subject_allocation_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            $ids = array_merge($ids, $assigned);
        }

        if (Schema::hasTable('mark_entry_delegations')) {
            $today = BusinessDate::today();

            /** @var list<int> $delegated */
            $delegated = DB::table('mark_entry_delegations')
                ->where('delegate_user_id', $userId)
                ->where(static function (QueryBuilder $q) use ($today): void {
                    $q->whereNull('valid_from')->orWhere('valid_from', '<=', $today);
                })
                ->where(static function (QueryBuilder $q) use ($today): void {
                    $q->whereNull('valid_to')->orWhere('valid_to', '>=', $today);
                })
                ->pluck('subject_allocation_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            $ids = array_merge($ids, $delegated);
        }

        return array_values(array_unique($ids));
    }
}
