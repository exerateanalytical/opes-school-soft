<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Portal;

use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use App\Modules\Guardians\Support\Portal\ChildAcademics;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `/portal/children/{s}/assignments` - mobile/assignments.png.
 *
 * Homework EXISTS in the platform (there is an `Assessment\Homework` module and
 * an assignment_tables migration) but has no guardian endpoint, no matrix row
 * and therefore no guardian-scoped reader. Wiring this screen to the staff
 * tables would be a hole: it would bypass the 32-row table entirely.
 *
 * So it shows what the portal CAN truthfully answer about "what does my child
 * have on" - the class timetable, row 26 - and states the gap in words. That
 * is a P1 API decision (a new row and a new operation), not something a screen
 * may improvise.
 */
#[Layout('layouts.portal')]
final class Assignments extends Component
{
    public int $studentId;

    public string $childName = '';

    public function mount(int $student): void
    {
        app(GuardianPortalPolicy::class)
            ->authorize(GuardianCapability::R26ViewTimetableAndAnnouncements, $student);

        $row = DB::table('students')->where('id', $student)->first(['first_name', 'last_name']);

        if ($row === null) {
            throw new NotFoundHttpException();
        }

        $this->studentId = $student;
        $this->childName = trim($row->first_name.' '.$row->last_name);
    }

    public function render(): mixed
    {
        return view('livewire.guardians.portal.assignments', [
            'studentId' => $this->studentId,
            'childName' => $this->childName,
            'timetableSlots' => collect(app(ChildAcademics::class)->timetable($this->studentId)),
        ]);
    }
}
