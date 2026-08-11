<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Support\Portal;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The child's medical records at the scope the caller is entitled to -
 * 07-students.md 7.5 rows 3 and 4 - read once for both doors.
 *
 * Rows 3 and 4 are separate rows for a concrete reason: a school can give a
 * non-custodial emergency contact the records that matter in an ambulance
 * (`is_emergency_relevant`) WITHOUT handing over the child's clinical history.
 * The narrowing is a column filter AND a row filter, and both live here so the
 * portal and the API cannot drift into showing different amounts.
 *
 * `detail` - the free-text clinical note - is the field row 4 exists to
 * protect, and it is never selected for an emergency-scope caller. Selecting
 * it and hiding it in the view would put it on the wire.
 */
final class ChildMedical
{
    /**
     * @return Collection<int, \stdClass>
     */
    public function records(int $studentId, bool $canFull): Collection
    {
        if (! Schema::hasTable('student_medical_records')) {
            return collect();
        }

        $query = DB::table('student_medical_records')
            ->where('student_id', $studentId)
            ->orderByDesc('recorded_at');

        if ($canFull) {
            return $query->get([
                'condition_type', 'summary', 'detail', 'severity', 'is_emergency_relevant', 'recorded_at',
            ]);
        }

        return $query
            ->where('is_emergency_relevant', true)
            ->get(['condition_type', 'summary', 'severity', 'recorded_at']);
    }
}
