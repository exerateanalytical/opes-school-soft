<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Portal;

use App\Modules\Guardians\Actions\RequestGuardianMeeting;
use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Domain\MeetingType;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use App\Modules\Guardians\Support\PortalContext;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `/portal/children/{student}/meeting` - 07-students.md 7.5 row 27.
 *
 * The time a parent picks is a PREFERENCE, not a booking - the Action records
 * it with `requested_by = guardian` so the office can tell an ask from a
 * commitment, and the copy says so before they submit. A parent who believed
 * they had reserved a slot and turned up to an empty office would rightly be
 * furious.
 *
 * Row 27 needs custody, which is why this is a separate screen rather than a
 * button on every child page.
 */
#[Layout('layouts.portal')]
final class Meeting extends Component
{
    public int $studentId;

    public string $childName = '';

    public string $preferredAt = '';

    public string $meetingType = 'parent_teacher';

    public string $agenda = '';

    public function mount(int $student): void
    {
        app(GuardianPortalPolicy::class)
            ->authorize(GuardianCapability::R27RequestGuardianMeeting, $student);

        $row = DB::table('students')->where('id', $student)->first(['first_name', 'last_name']);

        if ($row === null) {
            throw new NotFoundHttpException();
        }

        $this->studentId = $student;
        $this->childName = trim($row->first_name.' '.$row->last_name);
        $this->preferredAt = now()->addWeek()->format('Y-m-d\TH:i');
    }

    public function submit(): void
    {
        $this->validate([
            'preferredAt' => ['required', 'date', 'after:now'],
            'meetingType' => ['required', 'string', 'in:parent_teacher,disciplinary,financial,admission,other'],
            'agenda' => ['nullable', 'string', 'max:2000'],
        ]);

        $context = PortalContext::current();

        if ($context === null) {
            return;
        }

        // The Action re-checks row 27 and row 1 itself, which is the real
        // control - this component's mount() only decided whether to render.
        app(RequestGuardianMeeting::class)->handle(
            guardianId: (int) $context->guardian->getKey(),
            studentId: $this->studentId,
            preferredAt: str_replace('T', ' ', $this->preferredAt).':00',
            type: MeetingType::from($this->meetingType),
            agenda: $this->agenda === '' ? null : $this->agenda,
            createdBy: (int) (auth()->id() ?? 0),
            actor: auth()->user()?->toAuditActor(),
        );

        $this->agenda = '';
        session()->flash('portal-status', __('opes.guardian_portal.meeting_sent'));
    }

    public function render(): mixed
    {
        return view('livewire.guardians.portal.meeting', [
            'studentId' => $this->studentId,
            'childName' => $this->childName,
        ]);
    }
}
