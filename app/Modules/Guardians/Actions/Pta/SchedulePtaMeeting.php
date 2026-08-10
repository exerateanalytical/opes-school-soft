<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Actions\Pta;

use App\Modules\Guardians\Models\PtaMeeting;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Support\Facades\Gate;

/**
 * Schedules a general meeting of the Parent-Teacher Association.
 */
final class SchedulePtaMeeting
{
    public function handle(
        string $title,
        string $meetingDate,
        int $createdBy,
        ?string $location = null,
        ?string $agenda = null,
        ?int $chairedByOfficerId = null,
    ): PtaMeeting {
        Gate::authorize(Permission::GuardiansManage->value);

        return PtaMeeting::query()->create([
            'title' => $title,
            'meeting_date' => $meetingDate,
            'location' => $location,
            'agenda' => $agenda,
            'chaired_by_officer_id' => $chairedByOfficerId,
            'status' => 'scheduled',
            'created_by' => $createdBy,
        ]);
    }
}
