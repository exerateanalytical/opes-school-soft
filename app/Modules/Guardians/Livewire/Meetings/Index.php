<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Meetings;

use App\Modules\Guardians\Actions\RecordMeetingOutcome;
use App\Modules\Guardians\Actions\ScheduleGuardianMeeting;
use App\Modules\Guardians\Domain\MeetingRequestedBy;
use App\Modules\Guardians\Domain\MeetingStatus;
use App\Modules\Guardians\Domain\MeetingType;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Individual guardian meetings - parent-teacher, disciplinary, financial,
 * admission - scheduled and recorded with minutes.
 */
final class Index extends Component
{
    public bool $showForm = false;

    public string $guardianId = '';

    public string $scheduledAt = '';

    public string $type = 'parent_teacher';

    public string $location = '';

    public string $agenda = '';

    public array $minutesDrafts = [];

    public string $message = '';

    public string $error = '';

    public function mount(): void
    {
        $this->scheduledAt = now()->addDays(3)->format('Y-m-d\TH:i');
    }

    public function schedule(): void
    {
        $this->message = '';
        $this->error = '';

        try {
            app(ScheduleGuardianMeeting::class)->handle(
                (int) $this->guardianId,
                null,
                $this->scheduledAt,
                MeetingType::from($this->type),
                MeetingRequestedBy::School,
                (int) Auth::id(),
                $this->location !== '' ? $this->location : null,
                $this->agenda !== '' ? $this->agenda : null,
            );

            $this->showForm = false;
            $this->guardianId = '';
            $this->location = '';
            $this->agenda = '';
            $this->message = __('opes.meetings_screen.scheduled');
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function recordHeld(int $meetingId): void
    {
        $this->message = '';
        $this->error = '';

        $minutes = $this->minutesDrafts[$meetingId] ?? '';

        try {
            app(RecordMeetingOutcome::class)->handle($meetingId, MeetingStatus::Held, $minutes);
            $this->message = __('opes.meetings_screen.recorded');
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render(): View
    {
        $meetings = DB::table('guardian_meetings as m')
            ->join('guardians as g', 'g.id', '=', 'm.guardian_id')
            ->orderByDesc('m.scheduled_at')
            ->limit(50)
            ->get(['m.id', 'm.scheduled_at', 'm.meeting_type', 'm.status', 'm.agenda', 'g.first_name', 'g.last_name']);

        return view('livewire.guardians.meetings.index', [
            'guardians' => DB::table('guardians')->orderBy('last_name')->limit(200)->get(),
            'types' => MeetingType::cases(),
            'meetings' => $meetings,
        ]);
    }
}
