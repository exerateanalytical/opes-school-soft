<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Pta;

use App\Modules\Guardians\Actions\Pta\AppointPtaOfficer;
use App\Modules\Guardians\Actions\Pta\RecordPtaMeetingMinutes;
use App\Modules\Guardians\Actions\Pta\SchedulePtaMeeting;
use App\Modules\Guardians\Models\PtaMeeting;
use App\Modules\Guardians\Models\PtaOfficer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The Parent-Teacher Association: standing officers and general meetings,
 * distinct from an individual guardian's meeting with the school.
 */
final class Index extends Component
{
    public string $tab = 'meetings';

    public bool $showMeetingForm = false;

    public string $title = '';

    public string $meetingDate = '';

    public string $location = '';

    public string $agenda = '';

    public array $minutesDrafts = [];

    public array $attendeeDrafts = [];

    public bool $showOfficerForm = false;

    public string $officerGuardianId = '';

    public string $office = '';

    public string $termStartsOn = '';

    public string $message = '';

    public string $error = '';

    public function mount(): void
    {
        $this->meetingDate = now()->addWeek()->toDateString();
        $this->termStartsOn = now()->toDateString();
    }

    public function scheduleMeeting(): void
    {
        $this->message = '';
        $this->error = '';

        try {
            app(SchedulePtaMeeting::class)->handle(
                $this->title, $this->meetingDate, (int) Auth::id(),
                $this->location !== '' ? $this->location : null,
                $this->agenda !== '' ? $this->agenda : null,
            );

            $this->showMeetingForm = false;
            $this->title = '';
            $this->location = '';
            $this->agenda = '';
            $this->message = __('opes.pta_screen.scheduled');
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function recordMinutes(int $meetingId): void
    {
        $this->message = '';
        $this->error = '';

        try {
            app(RecordPtaMeetingMinutes::class)->handle(
                $meetingId,
                $this->minutesDrafts[$meetingId] ?? '',
                (int) ($this->attendeeDrafts[$meetingId] ?? 0),
            );
            $this->message = __('opes.pta_screen.recorded');
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function appointOfficer(): void
    {
        $this->message = '';
        $this->error = '';

        try {
            app(AppointPtaOfficer::class)->handle(
                (int) $this->officerGuardianId, $this->office, $this->termStartsOn,
            );

            $this->showOfficerForm = false;
            $this->officerGuardianId = '';
            $this->office = '';
            $this->message = __('opes.pta_screen.appointed');
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render(): View
    {
        return view('livewire.guardians.pta.index', [
            'guardians' => DB::table('guardians')->orderBy('last_name')->limit(200)->get(),
            'meetings' => PtaMeeting::query()->orderByDesc('meeting_date')->limit(30)->get(),
            'officers' => PtaOfficer::query()
                ->join('guardians as g', 'g.id', '=', 'pta_officers.guardian_id')
                ->whereNull('pta_officers.term_ends_on')
                ->orderBy('pta_officers.office')
                ->get(['pta_officers.id', 'pta_officers.office', 'pta_officers.term_starts_on', 'g.first_name', 'g.last_name']),
        ]);
    }
}
