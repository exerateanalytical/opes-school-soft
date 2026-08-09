<?php

declare(strict_types=1);

namespace App\Modules\Operations\Actions\Rollover;

use App\Modules\Academics\Actions\CreateClassGroup;
use App\Modules\Operations\Actions\Rollover\Support\RolloverStepMechanics;
use App\Modules\Operations\Domain\RolloverStep;
use App\Modules\Operations\Models\RolloverRun;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Wizard step 2 (docs/specs/08-operations.md §6.2): copy the outgoing year's
 * ClassGroup shells to the new year - names, streams, capacities and rooms
 * preserved. Class teachers are copied too but their class-group ids are
 * recorded in `step_states` as flagged-for-review (§6.2: "class teachers
 * copied but flagged for review") - staffing changes over the holidays.
 *
 * Reads via DB::table, writes via Academics\Actions\CreateClassGroup (the
 * owning module's door). Idempotent by the (year, level, name) natural key:
 * an existing target row is skipped; only rows this rollover creates enter
 * the undo ledger, so undo can never delete a group the school made by hand.
 */
final class CopyClassGroupsStep
{
    public function __construct(private readonly CreateClassGroup $createClassGroup)
    {
    }

    public function handle(RolloverRun $run, Actor $actor): RolloverRun
    {
        Gate::authorize(StartRolloverRun::PERMISSION);
        RolloverStepMechanics::assertRunnable($run, RolloverStep::CopyClassGroups);

        $toYearId = RolloverStepMechanics::targetYearId($run);

        $source = DB::table('class_groups')
            ->where('academic_year_id', $run->academic_year_from_id)
            ->orderBy('id')
            ->get();

        $created = 0;
        $skipped = 0;
        $reviewClassTeacherIds = [];

        foreach ($source as $row) {
            $exists = DB::table('class_groups')
                ->where('academic_year_id', $toYearId)
                ->where('class_level_id', (int) $row->class_level_id)
                ->where('name', (string) $row->name)
                ->first();

            if ($exists !== null) {
                $skipped++;

                continue;
            }

            $group = $this->createClassGroup->handle(
                classLevelId: (int) $row->class_level_id,
                academicYearId: $toYearId,
                name: (string) $row->name,
                capacity: (int) $row->capacity,
                streamId: $row->stream_id === null ? null : (int) $row->stream_id,
                roomId: $row->room_id === null ? null : (int) $row->room_id,
                classTeacherStaffId: $row->class_teacher_staff_id === null ? null : (int) $row->class_teacher_staff_id,
                status: (string) $row->status,
            );

            $groupId = (int) $group->getKey();
            RolloverStepMechanics::recordArtifact($run, RolloverStep::CopyClassGroups, 'class_groups', $groupId);
            $created++;

            if ($row->class_teacher_staff_id !== null) {
                $reviewClassTeacherIds[] = $groupId;
            }
        }

        RolloverStepMechanics::completeStep($run, RolloverStep::CopyClassGroups, [
            'created' => $created,
            'skipped_existing' => $skipped,
            'class_teacher_review_ids' => $reviewClassTeacherIds,
        ]);

        return $run->refresh();
    }
}
