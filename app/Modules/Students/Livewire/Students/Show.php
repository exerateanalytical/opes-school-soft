<?php

declare(strict_types=1);

namespace App\Modules\Students\Livewire\Students;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Students\Actions\ReinstateEnrollment;
use App\Modules\Students\Actions\SuspendEnrollment;
use App\Modules\Students\Actions\TransferStudentClass;
use App\Modules\Students\Actions\UpdateStudent;
use App\Modules\Students\Actions\WithdrawStudent;
use App\Modules\Students\Domain\EnrollmentStatus;
use App\Modules\Students\Domain\SegmentReason;
use App\Modules\Students\Models\Enrollment;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Models\StudentDocument;
use App\Modules\Students\Models\StudentMedicalRecord;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Student Profile, docs/specs/07-students.md 11.2.
 *
 * ── Which of the eight tabs are real ──────────────────────────────────────
 *
 * Four are live because their data exists in Phase 2:
 *   General   - students table (3.1)
 *   Guardians - student_guardians, rendered by a Guardians-owned nested
 *               component (see below)
 *   Documents - student_documents (8.1)
 *   Medical   - student_medical_records (8.2)
 *
 * The other seven mockup tabs - Overview, Academic Records, Attendance,
 * Examinations, Fees & Payments, Discipline, Activity Log - render DISABLED,
 * with the same aria-disabled + "arrives later" treatment the shell uses for
 * an unbuilt nav item. Their sources are 01-assessment, 04-fees, the register
 * (9) and Welfare, none of which exist yet. A tab that renders a plausible
 * empty grid is worse than one that says it is not here: the first is read as
 * "this child has no marks", the second as "this is not built".
 *
 * Activity Log is on that list even though `student_activity_logs` exists,
 * because 8.3 specifies a paginated viewer over a closed event taxonomy that
 * nothing writes to yet; an always-empty log presented as a feature would
 * claim a completeness the module does not have.
 *
 * ── Why guardians come through a nested component ─────────────────────────
 *
 * 7.3's validity predicate and the 7.5 matrix live on
 * Guardians\Models\StudentGuardian (isValid()/authorises()), and
 * tests/Architecture/ModuleBoundaryTest.php forbids this module from using
 * App\Modules\Guardians\Models. Re-deriving the predicate here would be the
 * one thing 7.5 forbids outright ("Nothing else may make this decision"), so
 * the tab mounts <livewire:students.guardians-panel/>, which lives in the
 * Guardians module and calls those methods on their home ground.
 *
 * ── Bounded, not paginated ────────────────────────────────────────────────
 *
 * 00-core 6.2 rule 8 forbids an unbounded collection query in a view. Each tab
 * list is capped and reports the true total beside the cap, rather than
 * carrying three independent paginators into one screen.
 */
#[Layout('layouts.app')]
final class Show extends Component
{
    /** Cap per tab list. See the class header. */
    private const TAB_LIST_LIMIT = 50;

    public Student $student;

    /** One of the LIVE tab keys only; anything else falls back to `general`. */
    #[Url]
    public string $tab = 'general';

    /** @var list<string> */
    public const LIVE_TABS = ['general', 'guardians', 'documents', 'medical'];

    /**
     * The seven mockup tabs of 11.2 that Phase 2 cannot fill, in the mockup's
     * order. Rendered inert; never with invented content.
     *
     * @var list<string>
     */
    public const DISABLED_TABS = [
        'overview',
        'academic_records',
        'attendance',
        'examinations',
        'fees',
        'discipline',
        'activity_log',
    ];

    // ── Edit profile (UpdateStudent) ──────────────────────────────────────
    public bool $showEditForm = false;

    public string $edit_first_name = '';

    public string $edit_middle_name = '';

    public string $edit_last_name = '';

    public string $edit_date_of_birth = '';

    public string $edit_gender = '';

    public string $edit_phone = '';

    public string $edit_email = '';

    // ── Withdraw (WithdrawStudent) ────────────────────────────────────────
    public bool $showWithdrawForm = false;

    public string $withdraw_on = '';

    public string $withdraw_reason = '';

    public string $withdraw_to = 'withdrawn';

    // ── Suspend (SuspendEnrollment) ───────────────────────────────────────
    public bool $showSuspendForm = false;

    public string $suspend_reason = '';

    // ── Reinstate (ReinstateEnrollment) ───────────────────────────────────
    public bool $showReinstateForm = false;

    public string $reinstate_reason = '';

    // ── Transfer class (TransferStudentClass) ─────────────────────────────
    public bool $showTransferForm = false;

    public string $transfer_class_group_id = '';

    public string $transfer_effective_on = '';

    public function mount(Student $student): void
    {
        // Mirrors routes/web.php: the route already requires students.view,
        // and the component refuses on its own anyway (00-core 6.2).
        Gate::authorize(Permission::StudentsView->value);

        $this->student = $student;
    }

    public function selectTab(string $tab): void
    {
        $this->tab = in_array($tab, self::LIVE_TABS, true) ? $tab : 'general';
    }

    // ---------------------------------------------------------- lifecycle UI

    /** True when the caller may edit core identity fields (UpdateStudent's own gate). */
    public function canEditStudent(): bool
    {
        return Gate::allows(Permission::StudentsManage->value);
    }

    /**
     * True when the caller may suspend/reinstate an enrollment - mirrors
     * SuspendEnrollment/ReinstateEnrollment's own Gate::any check, which
     * additionally admits discipline.manage holders.
     */
    public function canManageEnrollmentLifecycle(): bool
    {
        return Gate::any([
            Permission::StudentsManage->value,
            Permission::DisciplineManage->value,
        ]);
    }

    /** Mirrors SuspendEnrollment/ReinstateEnrollment's own Gate::any check. */
    private function authorizeEnrollmentLifecycle(): void
    {
        if (! $this->canManageEnrollmentLifecycle()) {
            throw new AuthorizationException();
        }
    }

    public function toggleEditForm(): void
    {
        Gate::authorize(Permission::StudentsManage->value);

        $this->showEditForm = ! $this->showEditForm;

        if ($this->showEditForm) {
            $this->edit_first_name = $this->student->first_name;
            $this->edit_middle_name = (string) ($this->student->middle_name ?? '');
            $this->edit_last_name = $this->student->last_name;
            $this->edit_date_of_birth = $this->student->date_of_birth?->toDateString() ?? '';
            $this->edit_gender = $this->student->gender->value;
            $this->edit_phone = (string) ($this->student->phone ?? '');
            $this->edit_email = (string) ($this->student->email ?? '');
        }
    }

    public function saveEdit(UpdateStudent $updateStudent): void
    {
        Gate::authorize(Permission::StudentsManage->value);

        try {
            $updateStudent->handle((int) $this->student->getKey(), [
                'first_name' => $this->edit_first_name,
                'middle_name' => $this->edit_middle_name === '' ? null : $this->edit_middle_name,
                'last_name' => $this->edit_last_name,
                'date_of_birth' => $this->edit_date_of_birth === '' ? null : $this->edit_date_of_birth,
                'gender' => $this->edit_gender,
                'phone' => $this->edit_phone === '' ? null : $this->edit_phone,
                'email' => $this->edit_email === '' ? null : $this->edit_email,
            ]);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError('edit_'.$key, (string) ($messages[0] ?? $e->getMessage()));
            }

            return;
        } catch (DomainException $e) {
            $this->addError('showEditForm', $e->getMessage());

            return;
        }

        $this->student->refresh();
        $this->showEditForm = false;
        session()->flash('status', 'Student profile updated.');
    }

    public function toggleWithdrawForm(): void
    {
        Gate::authorize(Permission::StudentsManage->value);

        $this->showWithdrawForm = ! $this->showWithdrawForm;

        if ($this->showWithdrawForm && $this->withdraw_on === '') {
            $this->withdraw_on = now()->toDateString();
        }
    }

    public function saveWithdraw(WithdrawStudent $withdrawStudent): void
    {
        Gate::authorize(Permission::StudentsManage->value);

        $enrollment = $this->currentEnrollment();

        if ($enrollment === null) {
            $this->addError('showWithdrawForm', 'This student has no live enrollment to withdraw.');

            return;
        }

        $to = EnrollmentStatus::tryFrom($this->withdraw_to) ?? EnrollmentStatus::Withdrawn;

        try {
            $withdrawStudent->handle(
                (int) $enrollment->getKey(),
                $this->withdraw_on,
                $this->withdraw_reason,
                $to,
            );
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError('withdraw_'.$key, (string) ($messages[0] ?? $e->getMessage()));
            }

            return;
        } catch (DomainException $e) {
            $this->addError('showWithdrawForm', $e->getMessage());

            return;
        }

        $this->student->refresh();
        $this->reset(['showWithdrawForm', 'withdraw_on', 'withdraw_reason']);
        $this->withdraw_to = 'withdrawn';
        session()->flash('status', 'Enrollment withdrawn.');
    }

    public function toggleSuspendForm(): void
    {
        $this->authorizeEnrollmentLifecycle();

        $this->showSuspendForm = ! $this->showSuspendForm;
    }

    public function saveSuspend(SuspendEnrollment $suspendEnrollment): void
    {
        $this->authorizeEnrollmentLifecycle();

        $enrollment = $this->currentEnrollment();

        if ($enrollment === null) {
            $this->addError('showSuspendForm', 'This student has no active enrollment to suspend.');

            return;
        }

        try {
            $suspendEnrollment->handle((int) $enrollment->getKey(), $this->suspend_reason);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError('suspend_'.$key, (string) ($messages[0] ?? $e->getMessage()));
            }

            return;
        } catch (DomainException $e) {
            $this->addError('showSuspendForm', $e->getMessage());

            return;
        }

        $this->student->refresh();
        $this->reset(['showSuspendForm', 'suspend_reason']);
        session()->flash('status', 'Enrollment suspended.');
    }

    public function toggleReinstateForm(): void
    {
        $this->authorizeEnrollmentLifecycle();

        $this->showReinstateForm = ! $this->showReinstateForm;
    }

    public function saveReinstate(ReinstateEnrollment $reinstateEnrollment): void
    {
        $this->authorizeEnrollmentLifecycle();

        $enrollment = $this->currentEnrollment();

        if ($enrollment === null) {
            $this->addError('showReinstateForm', 'This student has no suspended enrollment to reinstate.');

            return;
        }

        try {
            $reinstateEnrollment->handle((int) $enrollment->getKey(), $this->reinstate_reason);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError('reinstate_'.$key, (string) ($messages[0] ?? $e->getMessage()));
            }

            return;
        } catch (DomainException $e) {
            $this->addError('showReinstateForm', $e->getMessage());

            return;
        }

        $this->student->refresh();
        $this->reset(['showReinstateForm', 'reinstate_reason']);
        session()->flash('status', 'Enrollment reinstated.');
    }

    public function toggleTransferForm(): void
    {
        Gate::authorize(Permission::StudentsManage->value);

        $this->showTransferForm = ! $this->showTransferForm;

        if ($this->showTransferForm && $this->transfer_effective_on === '') {
            $this->transfer_effective_on = now()->toDateString();
        }
    }

    public function saveTransfer(TransferStudentClass $transferStudentClass): void
    {
        Gate::authorize(Permission::StudentsManage->value);

        $enrollment = $this->currentEnrollment();

        if ($enrollment === null) {
            $this->addError('showTransferForm', 'This student has no live enrollment to transfer.');

            return;
        }

        if ($this->transfer_class_group_id === '') {
            $this->addError('transfer_class_group_id', 'Choose a target class group.');

            return;
        }

        try {
            $transferStudentClass->handle(
                (int) $enrollment->getKey(),
                (int) $this->transfer_class_group_id,
                $this->transfer_effective_on,
                SegmentReason::ClassTransfer,
            );
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError('transfer_'.$key, (string) ($messages[0] ?? $e->getMessage()));
            }

            return;
        } catch (DomainException $e) {
            $this->addError('showTransferForm', $e->getMessage());

            return;
        }

        $this->student->refresh();
        $this->reset(['showTransferForm', 'transfer_class_group_id', 'transfer_effective_on']);
        session()->flash('status', 'Student transferred to the new class group.');
    }

    /**
     * The enrollment lifecycle buttons all act on the student's one LIVE
     * enrollment (pending/active/suspended - 4.2 invariant "no second live
     * enrollment in one year", C1). Eloquent, not the query builder: Enrollment
     * is a Students-owned model, so no module-boundary rule is in play here.
     */
    private function currentEnrollment(): ?Enrollment
    {
        return Enrollment::query()
            ->where('student_id', $this->student->id)
            ->whereIn('status', array_map(
                static fn (EnrollmentStatus $status): string => $status->value,
                EnrollmentStatus::live(),
            ))
            ->orderByDesc('enrolled_on')
            ->first();
    }

    /**
     * @return array<int, string>
     */
    private function transferClassGroupOptions(?Enrollment $enrollment): array
    {
        if ($enrollment === null) {
            return [];
        }

        /** @var array<int, string> $rows */
        $rows = DB::table('class_groups')
            ->where('academic_year_id', $enrollment->academic_year_id)
            ->where('class_level_id', $enrollment->class_level_id)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->map(static fn (mixed $label): string => (string) $label)
            ->all();

        return $rows;
    }

    private function activeTab(): string
    {
        return in_array($this->tab, self::LIVE_TABS, true) ? $this->tab : 'general';
    }

    /**
     * "What class is this student in today" - the class group of the OPEN
     * segment of the live enrollment (5.2). Query builder, because
     * `class_groups` is an Academics table and this module may not use its
     * models; the Enrollment model's own header documents that this is the
     * sanctioned way round.
     */
    private function currentClassName(): ?string
    {
        $name = DB::table('enrollment_segments as seg')
            ->join('enrollments as enr', 'enr.id', '=', 'seg.enrollment_id')
            ->join('class_groups as cg', 'cg.id', '=', 'seg.class_group_id')
            ->where('enr.student_id', '=', $this->student->id)
            ->whereNull('seg.ends_on')
            ->whereIn('enr.status', ['pending', 'active', 'suspended'])
            ->orderByDesc('seg.starts_on')
            ->value('cg.name');

        return is_string($name) ? $name : null;
    }

    /** Same reasoning as currentClassName(): `houses` belongs to Academics. */
    private function houseName(): ?string
    {
        if ($this->student->house_id === null) {
            return null;
        }

        $name = DB::table('houses')->where('id', '=', $this->student->house_id)->value('name');

        return is_string($name) ? $name : null;
    }

    /**
     * @return Collection<int, StudentDocument>
     */
    private function documents(): Collection
    {
        return $this->student->documents()
            ->notArchived()
            ->orderByDesc('created_at')
            ->limit(self::TAB_LIST_LIMIT)
            ->get();
    }

    /**
     * @return Collection<int, StudentMedicalRecord>
     */
    private function medicalRecords(): Collection
    {
        return $this->student->medicalRecords()
            ->orderByDesc('recorded_at')
            ->limit(self::TAB_LIST_LIMIT)
            ->get();
    }

    public function render(): mixed
    {
        $tab = $this->activeTab();
        $enrollment = $this->currentEnrollment();

        return view('livewire.students.show', [
            'tab' => $tab,
            'currentClassName' => $this->currentClassName(),
            'houseName' => $this->houseName(),
            'documents' => $tab === 'documents' ? $this->documents() : new Collection(),
            'documentsTotal' => $tab === 'documents'
                ? $this->student->documents()->notArchived()->count()
                : 0,
            'medicalRecords' => $tab === 'medical' ? $this->medicalRecords() : new Collection(),
            'medicalTotal' => $tab === 'medical' ? $this->student->medicalRecords()->count() : 0,
            'listLimit' => self::TAB_LIST_LIMIT,
            'currentEnrollment' => $enrollment,
            'canEditStudent' => $this->canEditStudent(),
            'canManageEnrollmentLifecycle' => $this->canManageEnrollmentLifecycle(),
            'transferClassGroupOptions' => $this->showTransferForm ? $this->transferClassGroupOptions($enrollment) : [],
        ]);
    }
}
