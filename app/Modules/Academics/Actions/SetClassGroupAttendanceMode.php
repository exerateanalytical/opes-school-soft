<?php

declare(strict_types=1);

namespace App\Modules\Academics\Actions;

use App\Modules\Academics\Domain\AttendanceMode;
use App\Modules\Academics\Models\ClassGroup;
use App\Modules\Academics\Models\ClassLevel;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/07-students.md §9.7: "Per-lesson attendance is mandatory, not
 * optional, for any class group whose framework requires absence hours.
 * Enabling a MINESEC framework on a class group with attendance_mode='daily'
 * is a configuration error rejected at save, with the message naming the
 * report-card blocks that would print blank."
 *
 * The framework is read through DB::table('assessment_frameworks') — it is
 * an Assessment-owned table and the module-boundary test forbids importing
 * its Model here. The section's default framework speaks for the section;
 * absent a default, any active framework that requires per-lesson attendance
 * still vetoes `daily` (a stricter framework existing at all means bulletins
 * will need the hours).
 */
final class SetClassGroupAttendanceMode
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(int $classGroupId, AttendanceMode $mode): ClassGroup
    {
        Gate::authorize(Permission::AcademicsManage->value);

        return DB::transaction(function () use ($classGroupId, $mode): ClassGroup {
            /** @var ClassGroup $classGroup */
            $classGroup = ClassGroup::query()->lockForUpdate()->findOrFail($classGroupId);

            if ($mode === AttendanceMode::Daily) {
                $this->rejectDailyUnderMinesecFramework($classGroup);
            }

            $before = $classGroup->attendance_mode->value;

            if ($before === $mode->value) {
                return $classGroup;
            }

            $classGroup->attendance_mode = $mode;
            $classGroup->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Academics',
                auditableType: ClassGroup::class,
                auditableId: (int) $classGroup->getKey(),
                before: ['attendance_mode' => $before],
                after: ['attendance_mode' => $mode->value],
                actor: auth()->user()?->toAuditActor() ?? Actor::system(),
            );

            return $classGroup;
        });
    }

    private function rejectDailyUnderMinesecFramework(ClassGroup $classGroup): void
    {
        /** @var ClassLevel $level */
        $level = ClassLevel::query()->findOrFail($classGroup->class_level_id);

        $requiresPerLesson = DB::table('assessment_frameworks')
            ->where('school_section_id', $level->school_section_id)
            ->where('academic_year_id', $classGroup->academic_year_id)
            ->where('is_active', true)
            ->where('requires_per_lesson_attendance', true)
            ->orderByDesc('is_default')
            ->first();

        if ($requiresPerLesson !== null) {
            throw new DomainException(
                'Daily attendance cannot yield heures d\'absence: framework "'
                .(string) $requiresPerLesson->code.'" requires per-lesson attendance, and the '
                .'bulletin blocks "heures d\'absence justifiées / non justifiées" would print '
                .'blank for every student in '.$classGroup->name.'.'
            );
        }
    }
}
