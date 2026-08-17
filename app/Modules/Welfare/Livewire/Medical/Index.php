<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Livewire\Medical;

use App\Modules\Welfare\Actions\CloseReferral;
use App\Modules\Welfare\Actions\MedicalDashboardStats;
use App\Modules\Welfare\Actions\RecordConsultation;
use App\Modules\Welfare\Actions\RecordReferral;
use App\Modules\Welfare\Domain\ConsultationOutcome;
use App\Modules\Welfare\Domain\ConsultationSeverity;
use App\Modules\Welfare\Domain\MedicalPermission;
use App\Modules\Welfare\Models\MedicalConsultation;
use App\Modules\Welfare\Models\MedicalReferral;
use DomainException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Medical desk at /welfare/medical (route wired by W5), gated
 * `medical.view`; 09-ui §Medical dashboard: KPI cards (Today's Visits ·
 * Active Treatments · Medical Records · Referrals), consultation log,
 * record form, and the Recent Medical Alerts rail. No dedicated mockup
 * exists for this screen, so it mirrors the Transport/Hostel Phase 10
 * chrome exactly (x-list-screen + x-kpi-card + x-status-pill).
 *
 * Clinical text (complaint/diagnosis/treatment, referral reason) is
 * encrypted at rest, so the LOG reads go through Welfare's OWN models -
 * decrypting a page at a time - while student identity comes from a
 * DB::table lookup keyed on the page's student_ids (never Students'
 * Models: ModuleBoundaryTest). All writes go through the W3 Actions, which
 * re-check `medical.manage` (rule 17: enforced in Actions, not menus).
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    /** Which table is showing: consultations | referrals. */
    #[Url]
    public string $tab = 'consultations';

    #[Url]
    public string $severity = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    // ── Record-consultation form ────────────────────────────────────────
    public bool $showForm = false;

    public string $formMatricule = '';

    public string $formVisitedAt = '';

    public string $formComplaint = '';

    public string $formDiagnosis = '';

    public string $formTreatment = '';

    public string $formSeverity = 'low';

    public string $formOutcome = 'returned_to_class';

    // ── Refer form (per consultation row) ───────────────────────────────
    public ?int $referConsultationId = null;

    public string $referTo = '';

    public string $referReason = '';

    /**
     * The date the referral was written up. RecordReferral has always taken
     * it; the panel used to hardcode "today", which mis-dates a referral
     * entered the morning after the consultation.
     */
    public string $referOn = '';

    // ── Close-referral form (per referral row) ──────────────────────────
    public ?int $closeReferralId = null;

    public string $closeNotes = '';

    /**
     * The date the follow-up actually happened. CloseReferral compares it
     * against the referral's `referred_on`, a guard that could never trip
     * while the panel hardcoded "now".
     */
    public string $closeFollowedUpOn = '';

    public function mount(): void
    {
        Gate::authorize(MedicalPermission::VIEW);
    }

    public function selectTab(string $tab): void
    {
        $this->tab = in_array($tab, ['consultations', 'referrals'], true) ? $tab : 'consultations';
        $this->status = '';
        $this->referConsultationId = null;
        $this->closeReferralId = null;
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['severity', 'status', 'search']);
        $this->resetPage();
    }

    public function updatedSeverity(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function toggleForm(): void
    {
        Gate::authorize(MedicalPermission::MANAGE);

        $this->showForm = ! $this->showForm;

        if ($this->showForm && $this->formVisitedAt === '') {
            $this->formVisitedAt = Carbon::now()->format('Y-m-d\TH:i');
        }
    }

    public function saveConsultation(RecordConsultation $record): void
    {
        Gate::authorize(MedicalPermission::MANAGE);

        $matricule = trim($this->formMatricule);

        if ($matricule === '') {
            $this->addError('formMatricule', 'Enter the student\'s matricule.');

            return;
        }

        $studentId = DB::table('students')->where('matricule', $matricule)->value('id');

        if ($studentId === null) {
            $this->addError('formMatricule', 'No student carries this matricule.');

            return;
        }

        $severity = ConsultationSeverity::tryFrom($this->formSeverity) ?? ConsultationSeverity::Low;
        $outcome = ConsultationOutcome::tryFrom($this->formOutcome) ?? ConsultationOutcome::ReturnedToClass;

        // The child's ACTIVE enrollment, when one exists, ties the visit to
        // the school year; a between-years visit records NULL.
        $enrollmentId = DB::table('enrollments')
            ->where('student_id', (int) $studentId)
            ->where('status', 'active')
            ->value('id');

        try {
            $record->handle(
                (int) $studentId,
                $enrollmentId === null ? null : (int) $enrollmentId,
                $this->formVisitedAt === '' ? Carbon::now() : Carbon::parse($this->formVisitedAt),
                $this->formComplaint,
                $this->formDiagnosis === '' ? null : $this->formDiagnosis,
                $this->formTreatment === '' ? null : $this->formTreatment,
                $severity,
                $outcome,
                $this->actor(),
            );
        } catch (ValidationException $e) {
            $this->addError('formComplaint', $e->getMessage());

            return;
        } catch (DomainException $e) {
            $this->addError('formMatricule', $e->getMessage());

            return;
        }

        $this->reset([
            'showForm', 'formMatricule', 'formVisitedAt', 'formComplaint',
            'formDiagnosis', 'formTreatment', 'formSeverity', 'formOutcome',
        ]);
        $this->tab = 'consultations';
        $this->resetPage();
        session()->flash('status', 'Consultation recorded.');
    }

    public function startReferral(int $consultationId): void
    {
        Gate::authorize(MedicalPermission::MANAGE);

        $this->referConsultationId = $consultationId;
        $this->referTo = '';
        $this->referReason = '';
        $this->referOn = Carbon::today()->toDateString();
    }

    public function cancelReferral(): void
    {
        $this->referConsultationId = null;
    }

    public function saveReferral(RecordReferral $record): void
    {
        Gate::authorize(MedicalPermission::MANAGE);

        if ($this->referConsultationId === null) {
            return;
        }

        try {
            $record->handle(
                $this->referConsultationId,
                $this->referTo,
                $this->referReason,
                $this->referOn === '' ? Carbon::today() : Carbon::parse($this->referOn),
                $this->actor(),
            );
        } catch (ValidationException $e) {
            $this->addError('referTo', $e->getMessage());

            return;
        }

        $this->referConsultationId = null;
        session()->flash('status', 'Referral recorded.');
    }

    public function startClose(int $referralId): void
    {
        Gate::authorize(MedicalPermission::MANAGE);

        $this->closeReferralId = $referralId;
        $this->closeNotes = '';
        $this->closeFollowedUpOn = Carbon::today()->toDateString();
    }

    public function cancelClose(): void
    {
        $this->closeReferralId = null;
    }

    public function confirmClose(CloseReferral $close): void
    {
        Gate::authorize(MedicalPermission::MANAGE);

        if ($this->closeReferralId === null) {
            return;
        }

        try {
            $close->handle(
                $this->closeReferralId,
                $this->closeFollowedUpOn === '' ? Carbon::now() : Carbon::parse($this->closeFollowedUpOn),
                $this->closeNotes === '' ? null : $this->closeNotes,
                $this->actor(),
            );
        } catch (DomainException $e) {
            $this->addError('closeNotes', $e->getMessage());

            return;
        }

        $this->closeReferralId = null;
        session()->flash('status', 'Referral closed.');
    }

    private function actor(): \App\Support\Audit\Actor
    {
        /** @var \App\Modules\Identity\Models\User $user */
        $user = auth()->user();

        return $user->toAuditActor();
    }

    private function resetPage(): void
    {
        $this->page = 1;
    }

    /**
     * Student ids whose name or matricule matches the search box - the
     * cross-module identity lookup, DB::table only.
     *
     * @return list<int>
     */
    private function matchingStudentIds(): array
    {
        $ids = [];

        $rows = DB::table('students')
            ->where(function ($q): void {
                $q->where('first_name', 'like', '%'.$this->search.'%')
                    ->orWhere('last_name', 'like', '%'.$this->search.'%')
                    ->orWhere('matricule', 'like', '%'.$this->search.'%');
            })
            ->limit(500)
            ->pluck('id');

        foreach ($rows as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    /**
     * @return LengthAwarePaginator<int, MedicalConsultation>
     */
    private function consultationRows(): LengthAwarePaginator
    {
        return MedicalConsultation::query()
            ->when($this->severity !== '', fn ($q) => $q->where('severity', $this->severity))
            ->when($this->status !== '', fn ($q) => $q->where('outcome', $this->status))
            ->when($this->search !== '', fn ($q) => $q->whereIn('student_id', $this->matchingStudentIds()))
            ->orderByDesc('visited_at')
            ->orderByDesc('id')
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * @return LengthAwarePaginator<int, MedicalReferral>
     */
    private function referralRows(): LengthAwarePaginator
    {
        return MedicalReferral::query()
            ->with('consultation')
            ->when($this->status === 'open', fn ($q) => $q->whereNull('followed_up_at'))
            ->when($this->status === 'closed', fn ($q) => $q->whereNotNull('followed_up_at'))
            ->when($this->search !== '', function ($q): void {
                $q->whereHas('consultation', fn ($c) => $c->whereIn('student_id', $this->matchingStudentIds()));
            })
            ->orderByDesc('referred_on')
            ->orderByDesc('id')
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * Identity for the page's students, keyed by student id.
     *
     * @param  list<int>  $studentIds
     * @return array<int, array{name: string, matricule: string}>
     */
    private function studentIdentities(array $studentIds): array
    {
        if ($studentIds === []) {
            return [];
        }

        $identities = [];

        $rows = DB::table('students')
            ->whereIn('id', array_values(array_unique($studentIds)))
            ->get(['id', 'first_name', 'last_name', 'matricule']);

        foreach ($rows as $row) {
            /** @var object{id: int|string, first_name: string, last_name: string, matricule: string} $row */
            $identities[(int) $row->id] = [
                'name' => trim($row->first_name.' '.$row->last_name),
                'matricule' => $row->matricule,
            ];
        }

        return $identities;
    }

    /**
     * The rail's "Recent Medical Alerts": high-severity visits in the last
     * 30 days, newest first. Identity only - no clinical text.
     *
     * @return list<array{student: string, severity: string, visited_at: string, outcome: string}>
     */
    private function recentAlerts(): array
    {
        $rows = DB::table('medical_consultations')
            ->where('severity', ConsultationSeverity::High->value)
            ->where('visited_at', '>=', Carbon::today()->subDays(30)->startOfDay())
            ->orderByDesc('visited_at')
            ->limit(5)
            ->get(['student_id', 'severity', 'visited_at', 'outcome']);

        $studentIds = [];

        foreach ($rows as $row) {
            /** @var object{student_id: int|string} $row */
            $studentIds[] = (int) $row->student_id;
        }

        $identities = $this->studentIdentities($studentIds);
        $alerts = [];

        foreach ($rows as $row) {
            /** @var object{student_id: int|string, severity: string, visited_at: string, outcome: string} $row */
            $alerts[] = [
                'student' => $identities[(int) $row->student_id]['name'] ?? ('Student #'.$row->student_id),
                'severity' => $row->severity,
                'visited_at' => substr($row->visited_at, 0, 16),
                'outcome' => $row->outcome,
            ];
        }

        return $alerts;
    }

    public function render(MedicalDashboardStats $stats): mixed
    {
        $rows = $this->tab === 'referrals' ? $this->referralRows() : $this->consultationRows();

        $studentIds = [];

        foreach ($rows->items() as $row) {
            if ($row instanceof MedicalConsultation) {
                $studentIds[] = $row->student_id;

                continue;
            }

            if ($row->consultation !== null) {
                $studentIds[] = $row->consultation->student_id;
            }
        }

        $tabCounts = [
            'consultations' => (int) DB::table('medical_consultations')->count(),
            'referrals' => (int) DB::table('medical_referrals')->count(),
        ];

        return view('livewire.welfare.medical.index', [
            'rows' => $rows,
            'kpis' => $stats->handle(),
            'tabCounts' => $tabCounts,
            'students' => $this->studentIdentities($studentIds),
            'recentAlerts' => $this->recentAlerts(),
            'canManage' => Gate::allows(MedicalPermission::MANAGE),
        ]);
    }
}
