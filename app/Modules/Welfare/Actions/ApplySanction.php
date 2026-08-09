<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Students\Actions\LogStudentActivity;
use App\Modules\Students\Actions\SuspendEnrollment;
use App\Modules\Students\Domain\StudentActivityEvent;
use App\Modules\Welfare\Domain\SanctionLadder;
use App\Modules\Welfare\Domain\SanctionType;
use App\Modules\Welfare\Models\DisciplineCase;
use App\Modules\Welfare\Models\DisciplineSanction;
use App\Support\Audit\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Applies a sanction to a live case.
 *
 * The SanctionLadder suggestion (design doc "Discipline") is exposed by
 * `suggestionFor()` for the form to display and is NEVER consulted here:
 * advisory only. The human picks `$type`; this Action only checks the pick
 * is coherent (dates in order, suspension bounded and enrollment-backed).
 *
 * A `suspension` flips the enrollment through the Students door
 * `SuspendEnrollment` in the SAME transaction — this module never writes
 * `enrollments`, and the door writes the lifecycle audit/activity rows so
 * the student's history shows both the sanction and the suspension it
 * caused.
 */
final class ApplySanction
{
    /** Ladder lookback: prior cases within this many days count. */
    public const LOOKBACK_DAYS = 365;

    public function handle(
        int $caseId,
        SanctionType $type,
        string $startsOn,
        ?string $endsOn = null,
        ?string $notes = null,
    ): DisciplineSanction {
        Gate::authorize(Permission::DisciplineManage->value);

        $starts = Carbon::parse($startsOn)->startOfDay();
        $ends = $endsOn === null ? null : Carbon::parse($endsOn)->startOfDay();

        if ($ends !== null && $ends->lt($starts)) {
            throw ValidationException::withMessages([
                'ends_on' => 'A sanction cannot end before it starts.',
            ]);
        }

        if ($type->suspendsEnrollment() && $ends === null) {
            // A suspension with no end date is an exclusion wearing the
            // wrong label; make the school say which it means.
            throw ValidationException::withMessages([
                'ends_on' => 'A suspension must state when the student returns; '
                    .'an indefinite removal is an exclusion.',
            ]);
        }

        return DB::transaction(function () use ($caseId, $type, $starts, $ends, $notes): DisciplineSanction {
            /** @var DisciplineCase $case */
            $case = DisciplineCase::query()->lockForUpdate()->findOrFail($caseId);

            if ($case->status->isTerminal()) {
                throw ValidationException::withMessages([
                    'discipline_case_id' => "A {$case->status->value} case cannot receive a new sanction.",
                ]);
            }

            if ($case->is_positive) {
                throw ValidationException::withMessages([
                    'discipline_case_id' => 'A positive behaviour entry cannot carry a sanction.',
                ]);
            }

            $actor = $this->currentActor();

            $sanction = DisciplineSanction::query()->create([
                'discipline_case_id' => $caseId,
                'type' => $type,
                'starts_on' => $starts->toDateString(),
                'ends_on' => $ends?->toDateString(),
                'applied_by' => $actor->id ?? $this->requireActorId(),
                'notes' => $notes,
            ]);

            if ($type->suspendsEnrollment()) {
                if ($case->enrollment_id === null) {
                    throw ValidationException::withMessages([
                        'type' => 'A suspension needs a current enrollment to suspend; '
                            .'this case is not linked to one.',
                    ]);
                }

                app(SuspendEnrollment::class)->handle(
                    $case->enrollment_id,
                    'Discipline case #'.$caseId.' sanction: suspension '
                    .$starts->toDateString().' to '.($ends?->toDateString() ?? '—'),
                );
            }

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Created,
                module: 'Welfare',
                auditableType: DisciplineSanction::class,
                auditableId: (int) $sanction->getKey(),
                before: null,
                after: [
                    'discipline_case_id' => $caseId,
                    'type' => $type->value,
                    'starts_on' => $starts->toDateString(),
                    'ends_on' => $ends?->toDateString(),
                ],
                actor: $actor,
            );

            app(LogStudentActivity::class)->handle(
                studentId: $case->student_id,
                event: StudentActivityEvent::SanctionApplied,
                summary: 'Sanction applied: '.$type->value.' from '.$starts->toDateString(),
                enrollmentId: $case->enrollment_id,
                relatedType: DisciplineSanction::class,
                relatedId: (int) $sanction->getKey(),
                actor: $actor,
            );

            return $sanction;
        });
    }

    /**
     * The ladder's ADVISORY suggestion for a case: prior countable cases for
     * the same STUDENT (cross-year — the reason C3 keys student_id) within
     * the lookback window, excluding the case itself, dismissed cases and
     * positive entries; escalated from the category's default.
     */
    public function suggestionFor(int $caseId): SanctionType
    {
        /** @var DisciplineCase $case */
        $case = DisciplineCase::query()->with('category')->findOrFail($caseId);

        $windowStart = $case->occurred_on->copy()->subDays(self::LOOKBACK_DAYS);

        $priorCount = DisciplineCase::query()
            ->where('student_id', $case->student_id)
            ->where('id', '!=', $case->getKey())
            ->where('is_positive', false)
            ->where('status', '!=', 'dismissed')
            ->whereDate('occurred_on', '>=', $windowStart->toDateString())
            ->whereDate('occurred_on', '<=', $case->occurred_on->toDateString())
            ->count();

        return (new SanctionLadder())->suggest(
            $priorCount,
            $case->category->default_sanction_type,
        );
    }

    private function requireActorId(): never
    {
        throw ValidationException::withMessages([
            'applied_by' => 'A sanction must name the staff member applying it.',
        ]);
    }

    private function currentActor(): Actor
    {
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
