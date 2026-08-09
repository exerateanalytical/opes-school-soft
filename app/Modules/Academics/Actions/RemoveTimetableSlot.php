<?php

declare(strict_types=1);

namespace App\Modules\Academics\Actions;

use App\Modules\Academics\Models\TimetableSlot;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Clear one cell of the grid. A hard delete on purpose: v1 keeps ONE live
 * grid per year (see the slots migration), so replacing a cell is
 * remove + assign, and the audit entry preserves what the cell held.
 * Registers reference slots with FK RESTRICT — once per-lesson attendance
 * has been taken against a slot, MySQL refuses the delete and the timetable
 * keeps its history.
 */
final class RemoveTimetableSlot
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(int $slotId): void
    {
        Gate::authorize(Permission::TimetableManage->value);

        DB::transaction(function () use ($slotId): void {
            /** @var TimetableSlot $slot */
            $slot = TimetableSlot::query()->lockForUpdate()->findOrFail($slotId);

            $before = [
                'class_group_id' => $slot->class_group_id,
                'day_of_week' => $slot->day_of_week,
                'timetable_period_id' => $slot->timetable_period_id,
                'subject_id' => $slot->subject_id,
                'staff_member_id' => $slot->staff_member_id,
                'room_id' => $slot->room_id,
            ];

            $slot->delete();

            $this->audit->handle(
                action: AuditAction::Deleted,
                module: 'Academics',
                auditableType: TimetableSlot::class,
                auditableId: $slotId,
                before: $before,
                actor: auth()->user()?->toAuditActor() ?? Actor::system(),
            );
        });
    }
}
