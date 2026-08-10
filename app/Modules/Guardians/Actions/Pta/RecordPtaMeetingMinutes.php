<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Actions\Pta;

use App\Modules\Guardians\Models\PtaMeeting;
use App\Modules\Identity\Domain\Permission;
use DomainException;
use Illuminate\Support\Facades\Gate;

/**
 * Records a PTA general meeting as held, with its minutes.
 */
final class RecordPtaMeetingMinutes
{
    public function handle(int $meetingId, string $minutes, int $attendeeCount): PtaMeeting
    {
        Gate::authorize(Permission::GuardiansManage->value);

        /** @var PtaMeeting $meeting */
        $meeting = PtaMeeting::query()->findOrFail($meetingId);

        if ($meeting->status !== 'scheduled') {
            throw new DomainException("Meeting {$meetingId} already has an outcome recorded.");
        }

        if (trim($minutes) === '') {
            throw new DomainException('A meeting recorded as held needs minutes.');
        }

        $meeting->forceFill([
            'status' => 'held',
            'minutes' => $minutes,
            'attendee_count' => $attendeeCount,
        ])->save();

        return $meeting->refresh();
    }
}
