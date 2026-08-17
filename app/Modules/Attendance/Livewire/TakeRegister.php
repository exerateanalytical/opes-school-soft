<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Livewire;

use App\Modules\Attendance\Actions\AmendAttendanceRegister;
use App\Modules\Attendance\Actions\OpenAttendanceRegister;
use App\Modules\Attendance\Actions\SubmitAttendanceRegister;
use App\Modules\Attendance\Domain\AttendanceStatus;
use App\Modules\Attendance\Domain\RegisterSession;
use App\Modules\Attendance\Domain\RegisterStatus;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Models\AttendanceRegister;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use stdClass;

/**
 * Take Attendance — 09-ui §8.7, after the 'Attendance.png' mockup: class +
 * date + session picker, roster with Present/Absent/Late/Excused radios,
 * Mark All Present, Clear All, one Save.
 *
 * The radios are plain deferred wire:model state — nothing round-trips until
 * Save, which is ONE request that opens the header and submits the batch
 * (07-students §9.9's ≤1-request contract). The KPI chips are counted
 * client-side by a few lines of Alpine so they track taps without a request.
 *
 * All reads of class groups, students, enrollments go through DB::table —
 * they are other modules' rows (ModuleBoundaryTest).
 */
#[Layout('layouts.app')]
final class TakeRegister extends Component
{
    #[Url]
    public string $classGroupId = '';

    #[Url]
    public string $date = '';

    public string $session = 'full_day';

    public string $timetableSlotId = '';

    /** @var array<int, string> enrollment id => attendance status value */
    public array $marks = [];

    /** @var array<int, string> enrollment id => minutes late */
    public array $minutesLate = [];

    public string $overrideReason = '';

    public string $amendReason = '';

    public function mount(): void
    {
        Gate::authorize(Permission::AttendanceTake->value);

        if ($this->date === '') {
            $this->date = now()->toDateString();
        }
    }

    public function updatedClassGroupId(): void
    {
        $this->reset(['marks', 'minutesLate', 'timetableSlotId', 'amendReason']);
        $this->resetErrorBag();
    }

    public function updatedDate(): void
    {
        $this->reset(['marks', 'minutesLate', 'amendReason']);
        $this->resetErrorBag();
    }

    public function updatedSession(): void
    {
        $this->reset(['marks', 'minutesLate', 'amendReason']);
        $this->resetErrorBag();
    }

    public function markAllPresent(): void
    {
        foreach ($this->marks as $enrollmentId => $status) {
            // §9.5: `suspended` is a roster fact, not a mark the bulk
            // shortcuts may overwrite.
            if ($status === AttendanceStatus::Suspended->value) {
                continue;
            }

            $this->marks[$enrollmentId] = AttendanceStatus::Present->value;
        }
        $this->minutesLate = [];
    }

    public function clearAll(): void
    {
        foreach ($this->marks as $enrollmentId => $status) {
            if ($status === AttendanceStatus::Suspended->value) {
                continue;
            }

            $this->marks[$enrollmentId] = '';
        }
        $this->minutesLate = [];
    }

    /**
     * The one-request save: open the header (roster frozen in-transaction)
     * and submit the batch.
     */
    public function save(
        OpenAttendanceRegister $open,
        SubmitAttendanceRegister $submit,
    ): void {
        Gate::authorize(Permission::AttendanceTake->value);

        try {
            $register = $open->handle(
                classGroupId: (int) $this->classGroupId,
                date: $this->date,
                session: RegisterSession::from($this->session),
                timetableSlotId: $this->timetableSlotId === '' ? null : (int) $this->timetableSlotId,
                overrideReason: $this->overrideReason === '' ? null : $this->overrideReason,
            );

            $submit->handle((int) $register->getKey(), $this->payload());
        } catch (AuthorizationException $exception) {
            $this->addError('save', $exception->getMessage());

            return;
        }

        session()->flash('status', __('attendance.saved'));
    }

    /** Amendment after submit — reason required (07-students §9.9). */
    public function amend(AmendAttendanceRegister $amendAction): void
    {
        Gate::authorize(Permission::AttendanceAmend->value);

        $register = $this->existingRegister();

        if ($register === null) {
            return;
        }

        if (trim($this->amendReason) === '') {
            throw ValidationException::withMessages([
                'amendReason' => __('attendance.amend_reason_required'),
            ]);
        }

        $amendAction->handle(
            (int) $register->getKey(),
            $this->payload(),
            $this->amendReason,
        );

        $this->reset('amendReason');
        session()->flash('status', __('attendance.amended'));
    }

    /**
     * @return array<int, array{enrollment_id: int, status: string, minutes_late?: int|null}>
     */
    private function payload(): array
    {
        $payload = [];

        foreach ($this->marks as $enrollmentId => $status) {
            if ($status === '') {
                // An untouched row after Clear All defaults back to present
                // (§9.9 — default all present; the teacher taps exceptions).
                $status = AttendanceStatus::Present->value;
            }

            $entry = ['enrollment_id' => (int) $enrollmentId, 'status' => $status];

            if ($status === AttendanceStatus::Late->value) {
                $minutes = $this->minutesLate[$enrollmentId] ?? '';
                $entry['minutes_late'] = $minutes === '' ? null : (int) $minutes;
            }

            $payload[] = $entry;
        }

        return $payload;
    }

    /**
     * @return list<stdClass>
     */
    private function roster(): array
    {
        if ($this->classGroupId === '') {
            return [];
        }

        /** @var list<stdClass> */
        return DB::table('enrollments as e')
            ->join('enrollment_segments as s', 's.enrollment_id', '=', 'e.id')
            ->join('students as st', 'st.id', '=', 'e.student_id')
            ->where('s.class_group_id', (int) $this->classGroupId)
            ->whereDate('s.starts_on', '<=', $this->date)
            ->where(function (Builder $query): void {
                $query->whereNull('s.ends_on')->orWhereDate('s.ends_on', '>=', $this->date);
            })
            ->whereIn('e.status', ['active', 'suspended'])
            ->whereDate('e.enrolled_on', '<=', $this->date)
            ->where(function (Builder $query): void {
                $query->whereNull('e.left_on')->orWhereDate('e.left_on', '>=', $this->date);
            })
            ->orderBy('st.last_name')
            ->orderBy('st.first_name')
            ->get([
                'e.id as enrollment_id',
                'e.status as enrollment_status',
                'st.matricule',
                'st.admission_no',
                'st.first_name',
                'st.last_name',
            ])
            ->values()
            ->all();
    }

    private function existingRegister(): ?AttendanceRegister
    {
        if ($this->classGroupId === '') {
            return null;
        }

        return AttendanceRegister::query()
            ->where('class_group_id', (int) $this->classGroupId)
            ->whereDate('date', $this->date)
            ->where('session', $this->session)
            ->where(
                'timetable_slot_id',
                $this->timetableSlotId === '' ? AttendanceRegister::SLOT_NONE : (int) $this->timetableSlotId,
            )
            ->first();
    }

    public function render(): mixed
    {
        $classGroups = DB::table('class_groups as cg')
            ->join('academic_years as y', 'y.id', '=', 'cg.academic_year_id')
            ->where('y.is_current', true)
            ->orderBy('cg.name')
            ->get(['cg.id', 'cg.name', 'cg.attendance_mode']);

        $selectedGroup = $classGroups->firstWhere('id', (int) $this->classGroupId);
        $perLesson = $selectedGroup !== null && (string) $selectedGroup->attendance_mode === 'per_lesson';

        $slots = [];

        if ($perLesson && $this->classGroupId !== '') {
            $dayOfWeek = Carbon::parse($this->date)->dayOfWeekIso;
            $slots = DB::table('timetable_slots as ts')
                ->join('timetable_periods as tp', 'tp.id', '=', 'ts.timetable_period_id')
                ->join('subjects as sub', 'sub.id', '=', 'ts.subject_id')
                ->where('ts.class_group_id', (int) $this->classGroupId)
                ->where('ts.day_of_week', $dayOfWeek)
                ->orderBy('tp.sequence')
                ->get(['ts.id', 'tp.name as period_name', 'sub.name as subject_name'])
                ->all();
        }

        $roster = $this->roster();
        $register = $this->existingRegister();
        $isTaken = $register !== null && $register->status !== RegisterStatus::Open;

        /** @var array<int, AttendanceRecord> $existingRecords */
        $existingRecords = [];

        if ($register !== null) {
            foreach ($register->records as $record) {
                $existingRecords[$record->enrollment_id] = $record;
            }
        }

        // Seed the radio state: saved statuses when a register exists,
        // default-all-present otherwise (§9.9).
        //
        // A suspended enrollment is NOT a teacher's choice (§9.5): it stays in
        // expected and is recorded as `suspended`. Its row renders a hidden
        // input rather than radios, so whatever is seeded here is what the
        // save posts — seeding it `present` (as this did) silently recorded
        // suspended students as present and left the double-punishment rule
        // with nothing to exclude.
        foreach ($roster as $row) {
            $enrollmentId = (int) $row->enrollment_id;
            $isSuspended = (string) $row->enrollment_status === 'suspended';

            if ($isSuspended) {
                $this->marks[$enrollmentId] = AttendanceStatus::Suspended->value;

                continue;
            }

            if (! array_key_exists($enrollmentId, $this->marks)) {
                $record = $existingRecords[$enrollmentId] ?? null;
                $this->marks[$enrollmentId] = $isTaken
                    ? ($record?->status->value ?? AttendanceStatus::Present->value)
                    : AttendanceStatus::Present->value;
            }
        }

        return view('livewire.attendance.take-register', [
            'classGroups' => $classGroups,
            'perLesson' => $perLesson,
            'slots' => $slots,
            'roster' => $roster,
            'register' => $register,
            'isTaken' => $isTaken,
            'existingRecords' => $existingRecords,
            'canAmend' => Gate::allows(Permission::AttendanceAmend->value),
            'statusOptions' => [
                AttendanceStatus::Present,
                AttendanceStatus::Absent,
                AttendanceStatus::Late,
                AttendanceStatus::Excused,
                AttendanceStatus::Sick,
            ],
        ]);
    }
}
