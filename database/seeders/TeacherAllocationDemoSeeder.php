<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Assessment\Actions\AssignAllocationTeacher;
use App\Modules\Identity\Models\User;
use App\Support\Audit\Actor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Links demo.teacher@opeschool.test to a real subject_allocation_teachers
 * row.
 *
 * Without this, the demo teacher login could not set homework, take
 * attendance, or enter marks for any class - `subject_allocation_teachers`
 * is user-keyed (Attendance\Actions\OpenAttendanceRegister's own precedent),
 * and the table had zero rows in the demo database. A login that exists
 * purely to demonstrate RBAC "teacher" but cannot actually teach anything
 * is a broken demo, not a working one.
 *
 * Idempotent: skips if the teacher already has any allocation.
 */
final class TeacherAllocationDemoSeeder extends Seeder
{
    public function run(): void
    {
        $teacher = User::query()->where('email', 'demo.teacher@opeschool.test')->first();

        if ($teacher === null) {
            $this->command?->warn('TeacherAllocationDemoSeeder: demo teacher missing; skipping.');

            return;
        }

        $already = DB::table('subject_allocation_teachers')
            ->where('user_id', $teacher->getKey())
            ->exists();

        if ($already) {
            $this->command?->info('demo.teacher already has a subject allocation; skipping.');

            return;
        }

        $allocationId = DB::table('subject_allocations')
            ->where('is_active', true)
            ->orderBy('id')
            ->value('id');

        if ($allocationId === null) {
            $this->command?->warn('No active subject_allocations exist; skipping.');

            return;
        }

        $admin = User::query()->where('email', 'demo.admin@opeschool.test')->first() ?? $teacher;
        Auth::setUser($admin);

        app(AssignAllocationTeacher::class)->handle(
            (int) $allocationId,
            (int) $teacher->getKey(),
            new Actor((int) $admin->getKey(), (string) $admin->name),
        );

        $this->command?->info("Allocated demo.teacher to subject_allocation {$allocationId}.");
    }
}
