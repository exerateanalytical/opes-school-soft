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
use App\Modules\Students\Models\StudentActivityLog;
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
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Student Profile, docs/specs/07-students.md 11.2.
 *
 * ── Which of the tabs are real ────────────────────────────────────────────
 *
 * Ten are live. Four have been since Phase 2 (General, Guardians, Documents,
 * Medical); the other six were inert on the reasoning that a tab rendering a
 * plausible empty grid is worse than one that says it is not here - the first
 * reads as "this child has no marks", the second as "this is not built".
 *
 * That reasoning was correct when it was written and has expired. Assessment,
 * Attendance, Fees, Welfare/Discipline and the activity log all shipped in the
 * phases since, so leaving those tabs disabled is now the same lie pointing
 * the other way: it tells the operator the platform cannot show them something
 * it can. Where a child genuinely has no rows, the tab shows a designed empty
 * state naming the reason - which is a true statement about THIS CHILD rather
 * than a claim about the platform, and that is exactly the distinction the
 * original decision was protecting.
 *
 * EXAMINATIONS IS GONE, not implemented. The audit at
 * docs/superpowers/audits/2026-08-15-inert-controls.md checked the schema:
 * there is no examination-entry or examination-result table. `exams` is a
 * scheduled sitting and `exam_seatings` is a seat number; neither carries an
 * outcome, and a candidate's marks are already what the Academic Records tab
 * reports. A tab promising results and showing a seat number is precisely the
 * failure the rule above exists to prevent, so the control is removed rather
 * than shipped hollow.
 *
 * ── Cross-module reads ────────────────────────────────────────────────────
 *
 * Six of the ten tabs read tables owned by Assessment, Attendance, Fees and
 * Welfare. Every one of those reads is a `DB::table` query:
 * tests/Architecture/ModuleBoundaryTest.php forbids this module from importing
 * their Models and permits exactly this.
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
    use WithFileUploads;

    /** Cap per tab list. See the class header. */
    private const TAB_LIST_LIMIT = 50;

    public Student $student;

    /** One of the LIVE tab keys only; anything else falls back to `general`. */
    #[Url]
    public string $tab = 'general';

    /**
     * The tabs backed by data that EXISTS, in the mockup's order.
     *
     * @var list<string>
     */
    public const LIVE_TABS = [
        'overview', 'general', 'guardians', 'academic_records', 'attendance',
        'fees', 'discipline', 'documents', 'medical', 'activity_log',
    ];

    /**
     * Nothing is inert any more. The constant stays so the blade's loop and
     * any external reference keep working, and so re-introducing an unbuilt
     * tab (`examinations`, when an examination-results table exists) has an
     * obvious home rather than being wired straight into LIVE_TABS.
     *
     * @var list<string>
     */
    public const DISABLED_TABS = [];

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
            throw new AuthorizationException;
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

    // ── Attach a document (Task 38) ───────────────────────────────────────

    public ?TemporaryUploadedFile $documentUpload = null;

    public string $documentTitle = '';

    /**
     * Attach a document to the student record. The control was inert with a
     * comment saying a file input "would write somewhere unspecified"; the
     * Phase 1 upload work specified it - the same disk, size and type
     * discipline the branding uploads use.
     *
     * PDFs and images only: this is a birth certificate or a transfer letter,
     * not an arbitrary file store, and an unrestricted upload on a registrar's
     * screen is the widest attack surface in the product.
     *
     * `file_hash` is NOT NULL on `student_documents` and is the column 8.1's
     * duplicate detection keys on, so it is computed here rather than left to
     * a later backfill.
     */
    public function saveDocument(): void
    {
        Gate::authorize(Permission::StudentsManage->value);

        $this->validate([
            'documentUpload' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg,webp', 'max:5120'],
            'documentTitle' => ['required', 'string', 'max:160'],
        ], [
            'documentUpload.required' => (string) __('opes.students_screen.document_required'),
            'documentUpload.mimes' => (string) __('opes.students_screen.document_wrong_type'),
            'documentUpload.max' => (string) __('opes.students_screen.document_too_large'),
            'documentTitle.required' => (string) __('opes.students_screen.document_title_required'),
        ]);

        $upload = $this->documentUpload;

        if (! $upload instanceof TemporaryUploadedFile) {
            return;
        }

        $hash = (string) hash_file('sha256', $upload->getRealPath());
        $path = $upload->store('student-documents/'.$this->student->getKey(), 'public');

        StudentDocument::query()->create([
            'student_id' => (int) $this->student->getKey(),
            'title' => $this->documentTitle,
            'file_path' => is_string($path) ? $path : '',
            'file_hash' => $hash,
            'mime' => (string) $upload->getMimeType(),
            'size_bytes' => (int) $upload->getSize(),
            'uploaded_by' => auth()->id() === null ? null : (int) auth()->id(),
        ]);

        $upload->delete();
        $this->reset(['documentUpload', 'documentTitle']);

        session()->flash('status', __('opes.students_screen.document_saved'));
    }

    /**
     * The enrollment ids of this student, which every cross-module read below
     * keys on. Cached per request: five tabs would otherwise repeat it.
     *
     * @return list<int>
     */
    private function enrollmentIds(): array
    {
        return $this->enrollmentIds ??= array_values(DB::table('enrollments')
            ->where('student_id', $this->student->getKey())
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all());
    }

    /** @var list<int>|null */
    private ?array $enrollmentIds = null;

    /**
     * The Overview tab's summary. Every figure is NULLABLE and null means
     * "not recorded", never zero (09-ui 3.3): a child for whom no register has
     * been taken has not been absent, and a 0% attendance figure on a profile
     * is how a screen starts lying about a person.
     *
     * @return array{attendance_rate: string|null, marks_count: int|null, outstanding_balance: int|null, discipline_cases: int|null, documents: int|null}
     */
    private function overviewSummary(): array
    {
        $studentId = (int) $this->student->getKey();
        $enrollmentIds = $this->enrollmentIds();

        // Attendance: (present + late) over everything the register counted,
        // from SUBMITTED registers only - a draft register is a teacher's
        // working state, not a fact about a child.
        $attendanceRate = null;

        if ($enrollmentIds !== []) {
            $counts = DB::table('attendance_records as r')
                ->join('attendance_registers as reg', 'reg.id', '=', 'r.attendance_register_id')
                ->whereIn('r.enrollment_id', $enrollmentIds)
                ->whereIn('reg.status', ['submitted', 'amended'])
                ->selectRaw("SUM(r.status = 'present') as present_count")
                ->selectRaw("SUM(r.status = 'late') as late_count")
                ->selectRaw("SUM(r.status <> 'suspended') as counted")
                ->first();

            $counted = (int) ($counts->counted ?? 0);

            if ($counted > 0) {
                $attendanceRate = number_format(
                    (((int) ($counts->present_count ?? 0) + (int) ($counts->late_count ?? 0)) / $counted) * 100,
                    1,
                ).'%';
            }
        }

        // Fees. `invoices` carries no amount columns at all: the total is the
        // sum of its lines and what has been settled is the sum of its
        // un-reversed allocations. Null when the child has no issued invoice -
        // "owes nothing" and "has never been billed" are different facts.
        $outstanding = null;

        $invoiceIds = DB::table('invoices')
            ->where('student_id', $studentId)
            ->where('status', 'issued')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($invoiceIds !== []) {
            $invoiced = (int) DB::table('invoice_lines')
                ->whereIn('invoice_id', $invoiceIds)
                ->sum(DB::raw('amount + tax_amount'));

            $paid = (int) DB::table('payment_allocations')
                ->whereIn('invoice_id', $invoiceIds)
                ->whereNull('reversed_at')
                ->sum('amount');

            $outstanding = max(0, $invoiced - $paid);
        }

        $marks = $enrollmentIds === [] ? 0 : DB::table('marks')
            ->whereIn('enrollment_id', $enrollmentIds)
            ->where('workflow_state', 'validated')
            ->count();

        $discipline = $this->canViewDiscipline()
            ? DB::table('discipline_cases')->where('student_id', $studentId)->count()
            : null;

        return [
            'attendance_rate' => $attendanceRate,
            'marks_count' => $marks > 0 ? $marks : null,
            'outstanding_balance' => $outstanding,
            'discipline_cases' => $discipline,
            'documents' => ($count = $this->student->documents()->notArchived()->count()) > 0 ? $count : null,
        ];
    }

    /**
     * The student's attendance rows, newest first. Bounded by TAB_LIST_LIMIT
     * with the true total reported beside it (00-core 6.2 rule 8) rather than
     * carrying another paginator onto this screen.
     *
     * @return Collection<int, \stdClass>
     */
    private function attendanceRows(): Collection
    {
        if ($this->enrollmentIds() === []) {
            return new Collection;
        }

        return DB::table('attendance_records as r')
            ->join('attendance_registers as reg', 'reg.id', '=', 'r.attendance_register_id')
            ->leftJoin('class_groups as cg', 'cg.id', '=', 'reg.class_group_id')
            ->whereIn('r.enrollment_id', $this->enrollmentIds())
            ->whereIn('reg.status', ['submitted', 'amended'])
            ->orderByDesc('reg.date')
            ->limit(self::TAB_LIST_LIMIT)
            ->select(['r.id', 'reg.date', 'reg.session', 'r.status', 'r.remark', 'cg.name as class_name'])
            ->get();
    }

    private function attendanceTotal(): int
    {
        if ($this->enrollmentIds() === []) {
            return 0;
        }

        return DB::table('attendance_records as r')
            ->join('attendance_registers as reg', 'reg.id', '=', 'r.attendance_register_id')
            ->whereIn('r.enrollment_id', $this->enrollmentIds())
            ->whereIn('reg.status', ['submitted', 'amended'])
            ->count();
    }

    /**
     * Fee invoices and their settlement state. Amounts are integer minor units
     * throughout this platform (NumericPolicyTest), summed in SQL from the
     * lines and the allocations and formatted in the view, never divided here.
     *
     * @return Collection<int, \stdClass>
     */
    private function feeRows(): Collection
    {
        return DB::table('invoices as i')
            ->where('i.student_id', $this->student->getKey())
            ->orderByDesc('i.issue_date')
            ->orderByDesc('i.id')
            ->limit(self::TAB_LIST_LIMIT)
            ->select(['i.id', 'i.invoice_no', 'i.issue_date', 'i.due_date', 'i.status'])
            ->selectSub(
                DB::table('invoice_lines')
                    ->whereColumn('invoice_lines.invoice_id', 'i.id')
                    ->selectRaw('COALESCE(SUM(amount + tax_amount), 0)'),
                'total_amount',
            )
            ->selectSub(
                DB::table('payment_allocations')
                    ->whereColumn('payment_allocations.invoice_id', 'i.id')
                    ->whereNull('payment_allocations.reversed_at')
                    ->selectRaw('COALESCE(SUM(amount), 0)'),
                'paid_amount',
            )
            ->get();
    }

    private function feeTotal(): int
    {
        return DB::table('invoices')->where('student_id', $this->student->getKey())->count();
    }

    /**
     * Discipline cases. Gated on the caller's own discipline permission rather
     * than on students.view: a conduct record is not ordinary directory data,
     * and a front-desk clerk looking up a phone number has no business reading
     * it.
     *
     * The severity lives on the CATEGORY, not the case - `discipline_cases`
     * has no severity, reference or summary column, whatever the mockups
     * suggested.
     *
     * @return Collection<int, \stdClass>
     */
    private function disciplineRows(): Collection
    {
        if (! $this->canViewDiscipline()) {
            return new Collection;
        }

        return DB::table('discipline_cases as c')
            ->leftJoin('discipline_categories as cat', 'cat.id', '=', 'c.discipline_category_id')
            ->where('c.student_id', $this->student->getKey())
            ->orderByDesc('c.occurred_on')
            ->orderByDesc('c.id')
            ->limit(self::TAB_LIST_LIMIT)
            ->select([
                'c.id', 'c.occurred_on', 'c.status', 'c.description', 'c.is_positive',
                'cat.name as category_name', 'cat.severity as category_severity',
            ])
            ->get();
    }

    public function canViewDiscipline(): bool
    {
        return Gate::allows(Permission::DisciplineView->value);
    }

    /**
     * Published report cards - the academic record a school will actually
     * stand behind. Report-card snapshots exist only for PUBLISHED periods,
     * which is what makes this the right source: an unpublished period's marks
     * are a working figure, and showing them on a profile a guardian may be
     * reading over a shoulder publishes them by accident.
     *
     * Superseded snapshots are excluded: an amended report card replaces its
     * predecessor, and listing both invites the wrong one being handed over.
     *
     * @return Collection<int, \stdClass>
     */
    private function academicRows(): Collection
    {
        if ($this->enrollmentIds() === []) {
            return new Collection;
        }

        return DB::table('report_card_snapshots as s')
            ->leftJoin('assessment_periods as p', 'p.id', '=', 's.assessment_period_id')
            ->whereIn('s.enrollment_id', $this->enrollmentIds())
            ->whereNull('s.superseded_by_snapshot_id')
            ->orderByDesc('s.issued_at')
            ->orderByDesc('s.id')
            ->limit(self::TAB_LIST_LIMIT)
            ->select(['s.id', 's.issued_at', 's.generation', 'p.name as period_name'])
            ->get();
    }

    /**
     * The student activity log (07-students 8.3).
     *
     * This tab was excluded originally on the reasoning that "nothing writes
     * to student_activity_logs yet", and an always-empty log presented as a
     * feature claims a completeness the module does not have.
     * LogStudentActivity now exists and is called from Fees, Assessment and
     * Welfare, so the log renders what is there.
     *
     * StudentActivityLog is a Students-owned model, so Eloquent is correct
     * here and no boundary rule is in play.
     *
     * @return Collection<int, StudentActivityLog>
     */
    private function activityRows(): Collection
    {
        return StudentActivityLog::query()
            ->where('student_id', $this->student->getKey())
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
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
            'documents' => $tab === 'documents' ? $this->documents() : new Collection,
            'documentsTotal' => $tab === 'documents'
                ? $this->student->documents()->notArchived()->count()
                : 0,
            'medicalRecords' => $tab === 'medical' ? $this->medicalRecords() : new Collection,
            'medicalTotal' => $tab === 'medical' ? $this->student->medicalRecords()->count() : 0,
            'overviewSummary' => $tab === 'overview' ? $this->overviewSummary() : null,
            'attendanceRows' => $tab === 'attendance' ? $this->attendanceRows() : new Collection,
            'attendanceTotal' => $tab === 'attendance' ? $this->attendanceTotal() : 0,
            'feeRows' => $tab === 'fees' ? $this->feeRows() : new Collection,
            'feeTotal' => $tab === 'fees' ? $this->feeTotal() : 0,
            'disciplineRows' => $tab === 'discipline' ? $this->disciplineRows() : new Collection,
            'canViewDiscipline' => $this->canViewDiscipline(),
            'academicRows' => $tab === 'academic_records' ? $this->academicRows() : new Collection,
            'activityRows' => $tab === 'activity_log' ? $this->activityRows() : new Collection,
            'listLimit' => self::TAB_LIST_LIMIT,
            'currentEnrollment' => $enrollment,
            'canEditStudent' => $this->canEditStudent(),
            'canManageEnrollmentLifecycle' => $this->canManageEnrollmentLifecycle(),
            'transferClassGroupOptions' => $this->showTransferForm ? $this->transferClassGroupOptions($enrollment) : [],
        ]);
    }
}
