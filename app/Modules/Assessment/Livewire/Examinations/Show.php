<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Livewire\Examinations;

use App\Modules\Assessment\Models\Exam;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Exam detail screen at /examinations/{exam} — read-only companion to
 * Index.php's list (docs/specs/01-assessment.md 16.1). Shows the header for
 * one sitting, its full invigilator roster, its seating summary if
 * generated, and a print-preview "Exam Slip/Notice".
 *
 * Mirrors Index.php's own boundary discipline: every join crosses into
 * Academics/HR-owned tables (subjects, class_groups, rooms, staff_members),
 * so all reads go through DB::table() query builder calls rather than
 * another module's Eloquent models (tests/Architecture/ModuleBoundaryTest.php).
 * `Exam` itself is this module's own model, so it is fetched with Eloquent.
 *
 * Gated on the same `assessment.configure` permission Index.php uses — there
 * is still no exam-specific permission case (see Index.php's own header).
 */
#[Layout('layouts.app')]
final class Show extends Component
{
    public Exam $exam;

    public function mount(Exam $exam): void
    {
        Gate::authorize(Permission::AssessmentConfigure);

        $this->exam = $exam;
    }

    /**
     * The header line: subject, class, room, exam type — joined once here so
     * the Blade view never has to reach for another module's model.
     *
     * @return object{subject_name: string, class_group_name: string, room_name: string|null, room_code: string|null}|null
     */
    private function header(): ?object
    {
        /** @var object{subject_name: string, class_group_name: string, room_name: string|null, room_code: string|null}|null $row */
        $row = DB::table('exams as ex')
            ->join('subject_allocations as sa', 'sa.id', '=', 'ex.subject_allocation_id')
            ->join('subjects as sub', 'sub.id', '=', 'sa.subject_id')
            ->join('class_groups as cg', 'cg.id', '=', 'ex.class_group_id')
            ->leftJoin('rooms as r', 'r.id', '=', 'ex.room_id')
            ->where('ex.id', $this->exam->getKey())
            ->select([
                'sub.name as subject_name',
                'cg.name as class_group_name',
                'r.name as room_name',
                'r.code as room_code',
            ])
            ->first();

        return $row;
    }

    /**
     * The full invigilator roster for this sitting (unbounded is fine — one
     * exam has at most a handful of invigilators, unlike Index.php's
     * dataset-wide lists which are capped/paginated).
     *
     * @return Collection<int, object{id: int, role: string, first_name: string, last_name: string}>
     */
    private function invigilators(): Collection
    {
        /** @var Collection<int, object{id: int, role: string, first_name: string, last_name: string}> $rows */
        $rows = DB::table('exam_invigilators as ei')
            ->join('staff_members as sm', 'sm.id', '=', 'ei.staff_id')
            ->where('ei.exam_id', $this->exam->getKey())
            ->orderByDesc('ei.role')
            ->select(['ei.id', 'ei.role', 'sm.first_name', 'sm.last_name'])
            ->get();

        return $rows;
    }

    /**
     * The seating summary for this sitting, grouped by room — same shape as
     * Index.php's "Seating" tab, scoped to this one exam.
     *
     * @return Collection<int, object{room_id: int, room_name: string, room_code: string, room_capacity: int, seats_filled: int}>
     */
    private function seatingSummary(): Collection
    {
        /** @var Collection<int, object{room_id: int, room_name: string, room_code: string, room_capacity: int, seats_filled: int}> $rows */
        $rows = DB::table('exam_seatings as es')
            ->join('rooms as r', 'r.id', '=', 'es.room_id')
            ->where('es.exam_id', $this->exam->getKey())
            ->groupBy('r.id', 'r.name', 'r.code', 'r.capacity')
            ->select([
                'r.id as room_id', 'r.name as room_name', 'r.code as room_code',
                'r.capacity as room_capacity',
                DB::raw('COUNT(*) as seats_filled'),
            ])
            ->get();

        return $rows;
    }

    public function render(): mixed
    {
        return view('livewire.assessment.examinations.show', [
            'header' => $this->header(),
            'invigilators' => $this->invigilators(),
            'seating' => $this->seatingSummary(),
        ]);
    }
}
