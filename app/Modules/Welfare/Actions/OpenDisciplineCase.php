<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Students\Actions\LogStudentActivity;
use App\Modules\Students\Domain\StudentActivityEvent;
use App\Modules\Welfare\Domain\DisciplineCaseStatus;
use App\Modules\Welfare\Domain\DisciplineVisibility;
use App\Modules\Welfare\Models\DisciplineCase;
use App\Modules\Welfare\Models\DisciplineCategory;
use App\Support\Audit\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Opens a discipline case — or a positive behaviour entry, which is the same
 * first-class row with `is_positive = 1` (10-documents DISC-ACTION).
 *
 * C3 (docs/specs/07-students.md 3.4): the case keys BOTH `student_id` (the
 * ladder's cross-year history) and `enrollment_id` (the year filter for
 * promotion and the conduct block). The enrollment is resolved HERE, not
 * passed by the form, by finding the student's live enrollment whose
 * academic year contains `occurred_on`; a case raised outside any enrolled
 * period is stored with `enrollment_id = NULL` and simply never counts
 * against a promotion year.
 *
 * Student and enrollment rows cross the boundary via DB::table — their
 * Models are Students-owned (ModuleBoundaryTest).
 */
final class OpenDisciplineCase
{
    public function handle(
        int $studentId,
        int $categoryId,
        string $occurredOn,
        string $description,
        DisciplineVisibility $visibility = DisciplineVisibility::Internal,
        bool $isPositive = false,
    ): DisciplineCase {
        Gate::authorize(Permission::DisciplineManage->value);

        if (trim($description) === '') {
            throw ValidationException::withMessages([
                'description' => 'A case must describe what happened.',
            ]);
        }

        $occurred = Carbon::parse($occurredOn)->startOfDay();

        if ($occurred->gt(now()->endOfDay())) {
            throw ValidationException::withMessages([
                'occurred_on' => 'An incident cannot be recorded before it happens.',
            ]);
        }

        return DB::transaction(function () use (
            $studentId, $categoryId, $occurred, $description, $visibility, $isPositive
        ): DisciplineCase {
            $student = DB::table('students')->where('id', $studentId)->first(['id']);

            if ($student === null) {
                throw ValidationException::withMessages([
                    'student_id' => 'The selected student does not exist.',
                ]);
            }

            /** @var DisciplineCategory|null $category */
            $category = DisciplineCategory::query()->find($categoryId);

            if ($category === null || ! $category->is_active) {
                throw ValidationException::withMessages([
                    'discipline_category_id' => 'The offence category is unknown or retired.',
                ]);
            }

            $actor = $this->currentActor();

            $case = DisciplineCase::query()->create([
                'student_id' => $studentId,
                'enrollment_id' => $this->resolveEnrollmentId($studentId, $occurred),
                'discipline_category_id' => $categoryId,
                'occurred_on' => $occurred->toDateString(),
                'reported_by' => $actor->id ?? $this->requireActorId(),
                'description' => $description,
                'status' => DisciplineCaseStatus::Open,
                'visibility' => $visibility,
                'is_positive' => $isPositive,
            ]);

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Created,
                module: 'Welfare',
                auditableType: DisciplineCase::class,
                auditableId: (int) $case->getKey(),
                before: null,
                after: [
                    'student_id' => $studentId,
                    'enrollment_id' => $case->enrollment_id,
                    'category' => $category->name,
                    'occurred_on' => $occurred->toDateString(),
                    'visibility' => $visibility->value,
                    'is_positive' => $isPositive,
                ],
                actor: $actor,
            );

            app(LogStudentActivity::class)->handle(
                studentId: $studentId,
                event: StudentActivityEvent::DisciplineCaseOpened,
                summary: ($isPositive ? 'Positive behaviour entry: ' : 'Discipline case opened: ')
                    .$category->name.' on '.$occurred->toDateString(),
                enrollmentId: $case->enrollment_id,
                relatedType: DisciplineCase::class,
                relatedId: (int) $case->getKey(),
                actor: $actor,
            );

            return $case;
        });
    }

    /**
     * The student's live enrollment whose academic year contains the
     * incident date. Live = pending/active/suspended (07-students 3.3) — a
     * suspended student can absolutely earn a second case.
     */
    private function resolveEnrollmentId(int $studentId, Carbon $occurred): ?int
    {
        $id = DB::table('enrollments')
            ->join('academic_years', 'academic_years.id', '=', 'enrollments.academic_year_id')
            ->where('enrollments.student_id', $studentId)
            ->whereIn('enrollments.status', ['pending', 'active', 'suspended'])
            ->whereDate('academic_years.starts_on', '<=', $occurred->toDateString())
            ->whereDate('academic_years.ends_on', '>=', $occurred->toDateString())
            ->value('enrollments.id');

        return $id === null ? null : (int) $id;
    }

    /**
     * `reported_by` is NOT NULL by design — an anonymous accusation cannot
     * start casework against a child.
     */
    private function requireActorId(): never
    {
        throw ValidationException::withMessages([
            'reported_by' => 'A discipline case must name the staff member reporting it.',
        ]);
    }

    private function currentActor(): Actor
    {
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
