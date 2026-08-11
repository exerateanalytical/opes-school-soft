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
 * `/portal/children/{student}/timetable` - 07-students.md 7.5 row 26.
 *
 * Granted on any valid link, so this is the one child screen almost every
 * parent can reach. Slots are grouped into days here rather than in the reader,
 * because the API wants them flat and the web wants them as a week - the
 * QUERY is shared, the presentation is not.
 */
#[Layout('layouts.portal')]
final class Timetable extends Component
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
        $slots = app(ChildAcademics::class)->timetable($this->studentId);

        /** @var array<int, list<\stdClass>> $byDay */
        $byDay = [];

        foreach ($slots as $slot) {
            $byDay[(int) $slot->day_of_week][] = $slot;
        }

        ksort($byDay);

        return view('livewire.guardians.portal.timetable', [
            'studentId' => $this->studentId,
            'childName' => $this->childName,
            'byDay' => $byDay,
        ]);
    }
}
