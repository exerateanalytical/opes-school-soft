<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Livewire\Insurance;

use App\Modules\Welfare\Actions\EnrollStudentsInPolicy;
use App\Modules\Welfare\Actions\RecordClaim;
use App\Modules\Welfare\Actions\SavePolicy;
use App\Modules\Welfare\Actions\SettleClaim;
use App\Modules\Welfare\Domain\ClaimStatus;
use App\Modules\Welfare\Domain\InsuranceCoverType;
use App\Modules\Welfare\Domain\InsurancePermission;
use App\Support\Audit\Actor;
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
 * Student Insurance at /welfare/insurance (route wired by the integration
 * pass), gated `insurance.view`. No dedicated mockup exists in
 * 'frontend images/', so this mirrors the Hostel Management screen's
 * chrome exactly (phase-10 plan §5): KPI strip, filter bar, four tabs
 * (Policies / Insured Students / Claims / Uninsured), and a rail with the
 * coverage meter, claims summary and expiring policies.
 *
 * Cross-module reads (student names, class levels, academic years) go
 * through DB::table joins only - never another module's Models
 * (ModuleBoundaryTest). One paginated query per render plus the KPI
 * aggregates; no unbounded collection reaches the view.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    /** Which table is showing: policies | insured | claims | uninsured. */
    #[Url]
    public string $tab = 'policies';

    #[Url]
    public string $policy = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    // ── Enroll students form ────────────────────────────────────────────
    public bool $showEnrollForm = false;

    public string $enrollPolicyId = '';

    public string $enrollEnrollmentIds = '';

    public string $enrollEnrolledOn = '';

    // ── Record claim form ───────────────────────────────────────────────
    public bool $showClaimForm = false;

    public string $claimPolicyId = '';

    public string $claimStudentInsuranceId = '';

    public string $claimIncidentDate = '';

    public string $claimDescription = '';

    public string $claimAmount = '';

    // ── Save policy form ────────────────────────────────────────────────
    public bool $showPolicyForm = false;

    public string $policyProvider = '';

    public string $policyNo = '';

    public string $policyCoverType = 'student';

    public string $policyPremiumPerStudent = '';

    public string $policyCoverageStart = '';

    public string $policyCoverageEnd = '';

    public string $policyAcademicYearId = '';

    // ── Settle claim form ───────────────────────────────────────────────
    public bool $showSettleForm = false;

    public int $settleClaimId = 0;

    public string $settleOutcome = 'settled';

    public string $settleAmount = '';

    public string $settleDecidedOn = '';

    public function mount(): void
    {
        Gate::authorize(InsurancePermission::VIEW);
    }

    public function selectTab(string $tab): void
    {
        $this->tab = in_array($tab, ['policies', 'insured', 'claims', 'uninsured'], true)
            ? $tab
            : 'policies';
        $this->status = '';
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['policy', 'status', 'search']);
        $this->resetPage();
    }

    public function updatedPolicy(): void
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

    private function resetPage(): void
    {
        $this->page = 1;
    }

    private function actor(): Actor
    {
        /** @var \App\Modules\Identity\Models\User $user */
        $user = auth()->user();

        return $user->toAuditActor();
    }

    public function toggleEnrollForm(): void
    {
        Gate::authorize(InsurancePermission::MANAGE);

        $this->showEnrollForm = ! $this->showEnrollForm;

        if ($this->showEnrollForm && $this->enrollEnrolledOn === '') {
            $this->enrollEnrolledOn = Carbon::now()->format('Y-m-d');
        }
    }

    public function saveEnrollment(EnrollStudentsInPolicy $enroll): void
    {
        Gate::authorize(InsurancePermission::MANAGE);

        $this->validate([
            'enrollPolicyId' => ['required', 'integer', 'min:1'],
            'enrollEnrollmentIds' => ['required', 'string'],
            'enrollEnrolledOn' => ['required', 'date'],
        ], [], [
            'enrollPolicyId' => 'policy',
            'enrollEnrollmentIds' => 'enrollment ids',
            'enrollEnrolledOn' => 'enrolled on',
        ]);

        $enrollmentIds = array_values(array_filter(array_map(
            static fn (string $id): int => (int) trim($id),
            explode(',', $this->enrollEnrollmentIds)
        ), static fn (int $id): bool => $id > 0));

        if ($enrollmentIds === []) {
            $this->addError('enrollEnrollmentIds', 'Enter at least one valid enrollment ID.');

            return;
        }

        try {
            $summary = $enroll->handle(
                (int) $this->enrollPolicyId,
                $enrollmentIds,
                Carbon::parse($this->enrollEnrolledOn),
                $this->actor(),
            );
        } catch (ValidationException $e) {
            $this->addError('enrollEnrollmentIds', $e->getMessage());

            return;
        } catch (DomainException $e) {
            $this->addError('enrollPolicyId', $e->getMessage());

            return;
        }

        $this->reset(['showEnrollForm', 'enrollPolicyId', 'enrollEnrollmentIds', 'enrollEnrolledOn']);
        $this->tab = 'insured';
        $this->resetPage();
        session()->flash(
            'status',
            "Enrollment complete: {$summary['enrolled']} enrolled, {$summary['already_covered']} already covered, {$summary['skipped']} skipped."
        );
    }

    public function toggleClaimForm(): void
    {
        Gate::authorize(InsurancePermission::MANAGE);

        $this->showClaimForm = ! $this->showClaimForm;

        if ($this->showClaimForm && $this->claimIncidentDate === '') {
            $this->claimIncidentDate = Carbon::now()->format('Y-m-d');
        }
    }

    public function saveClaim(RecordClaim $recordClaim): void
    {
        Gate::authorize(InsurancePermission::MANAGE);

        $this->validate([
            'claimPolicyId' => ['required', 'integer', 'min:1'],
            'claimStudentInsuranceId' => ['nullable', 'integer', 'min:1'],
            'claimIncidentDate' => ['required', 'date'],
            'claimDescription' => ['required', 'string', 'min:1'],
            'claimAmount' => ['required', 'integer', 'min:1'],
        ], [], [
            'claimPolicyId' => 'policy',
            'claimStudentInsuranceId' => 'certificate',
            'claimIncidentDate' => 'incident date',
            'claimDescription' => 'description',
            'claimAmount' => 'amount claimed',
        ]);

        try {
            $recordClaim->handle(
                (int) $this->claimPolicyId,
                Carbon::parse($this->claimIncidentDate),
                $this->claimDescription,
                (int) $this->claimAmount,
                $this->actor(),
                $this->claimStudentInsuranceId === '' ? null : (int) $this->claimStudentInsuranceId,
            );
        } catch (ValidationException $e) {
            $this->addError('claimDescription', $e->getMessage());

            return;
        } catch (DomainException $e) {
            $this->addError('claimPolicyId', $e->getMessage());

            return;
        }

        $this->reset([
            'showClaimForm', 'claimPolicyId', 'claimStudentInsuranceId',
            'claimIncidentDate', 'claimDescription', 'claimAmount',
        ]);
        $this->tab = 'claims';
        $this->resetPage();
        session()->flash('status', 'Claim recorded.');
    }

    public function togglePolicyForm(): void
    {
        Gate::authorize(InsurancePermission::MANAGE);

        $this->showPolicyForm = ! $this->showPolicyForm;

        if ($this->showPolicyForm && $this->policyCoverageStart === '') {
            $this->policyCoverageStart = Carbon::now()->format('Y-m-d');
        }
    }

    public function savePolicy(SavePolicy $savePolicy): void
    {
        Gate::authorize(InsurancePermission::MANAGE);

        $this->validate([
            'policyProvider' => ['required', 'string', 'min:1'],
            'policyNo' => ['required', 'string', 'min:1'],
            'policyCoverType' => ['required', 'string'],
            'policyPremiumPerStudent' => ['nullable', 'integer', 'min:0'],
            'policyCoverageStart' => ['required', 'date'],
            'policyCoverageEnd' => ['required', 'date'],
            'policyAcademicYearId' => ['required', 'integer', 'min:1'],
        ], [], [
            'policyProvider' => 'provider',
            'policyNo' => 'policy number',
            'policyCoverType' => 'cover type',
            'policyPremiumPerStudent' => 'premium per student',
            'policyCoverageStart' => 'coverage start',
            'policyCoverageEnd' => 'coverage end',
            'policyAcademicYearId' => 'academic year',
        ]);

        $coverType = InsuranceCoverType::tryFrom($this->policyCoverType);

        if ($coverType === null) {
            $this->addError('policyCoverType', 'Unknown cover type; expected student or asset.');

            return;
        }

        try {
            $savePolicy->handle(null, [
                'provider' => $this->policyProvider,
                'policy_no' => $this->policyNo,
                'cover_type' => $coverType,
                'premium_per_student' => $this->policyPremiumPerStudent === '' ? null : (int) $this->policyPremiumPerStudent,
                'coverage_start' => Carbon::parse($this->policyCoverageStart),
                'coverage_end' => Carbon::parse($this->policyCoverageEnd),
                'academic_year_id' => (int) $this->policyAcademicYearId,
            ], $this->actor());
        } catch (ValidationException $e) {
            $this->addError('policyNo', $e->getMessage());

            return;
        } catch (DomainException $e) {
            $this->addError('policyProvider', $e->getMessage());

            return;
        }

        $this->reset([
            'showPolicyForm', 'policyProvider', 'policyNo', 'policyCoverType',
            'policyPremiumPerStudent', 'policyCoverageStart', 'policyCoverageEnd', 'policyAcademicYearId',
        ]);
        $this->policyCoverType = 'student';
        $this->tab = 'policies';
        $this->resetPage();
        session()->flash('status', 'Policy saved.');
    }

    public function toggleSettleForm(int $claimId): void
    {
        Gate::authorize(InsurancePermission::MANAGE);

        if ($this->showSettleForm && $this->settleClaimId === $claimId) {
            $this->reset(['showSettleForm', 'settleClaimId', 'settleOutcome', 'settleAmount', 'settleDecidedOn']);

            return;
        }

        $this->showSettleForm = true;
        $this->settleClaimId = $claimId;
        $this->settleOutcome = 'settled';
        $this->settleAmount = '';
        $this->settleDecidedOn = Carbon::now()->format('Y-m-d');
    }

    public function settleClaim(SettleClaim $settleClaim): void
    {
        Gate::authorize(InsurancePermission::MANAGE);

        $this->validate([
            'settleClaimId' => ['required', 'integer', 'min:1'],
            'settleOutcome' => ['required', 'in:settled,rejected'],
            'settleAmount' => ['nullable', 'integer', 'min:1'],
            'settleDecidedOn' => ['required', 'date'],
        ], [], [
            'settleOutcome' => 'outcome',
            'settleAmount' => 'settled amount',
            'settleDecidedOn' => 'decision date',
        ]);

        $outcome = ClaimStatus::tryFrom($this->settleOutcome);

        if ($outcome === null) {
            $this->addError('settleOutcome', 'Unknown outcome; expected settled or rejected.');

            return;
        }

        try {
            $settleClaim->handle(
                $this->settleClaimId,
                $outcome,
                $this->settleAmount === '' ? null : (int) $this->settleAmount,
                Carbon::parse($this->settleDecidedOn),
                $this->actor(),
            );
        } catch (ValidationException $e) {
            $this->addError('settleAmount', $e->getMessage());

            return;
        } catch (DomainException $e) {
            $this->addError('settleOutcome', $e->getMessage());

            return;
        }

        $this->reset(['showSettleForm', 'settleClaimId', 'settleOutcome', 'settleAmount', 'settleDecidedOn']);
        $this->resetPage();
        session()->flash('status', 'Claim decision recorded.');
    }

    /**
     * The year the "uninsured" question is asked about: the flagged current
     * academic year, falling back to the newest one on file.
     */
    private function reportYearId(): ?int
    {
        $current = DB::table('academic_years')->where('is_current', true)->value('id');

        if ($current !== null) {
            return (int) $current;
        }

        $latest = DB::table('academic_years')->orderByDesc('starts_on')->value('id');

        return $latest === null ? null : (int) $latest;
    }

    /**
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function rows(): LengthAwarePaginator
    {
        return match ($this->tab) {
            'insured' => $this->insuredRows(),
            'claims' => $this->claimRows(),
            'uninsured' => $this->uninsuredRows(),
            default => $this->policyRows(),
        };
    }

    /**
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function policyRows(): LengthAwarePaginator
    {
        return DB::table('insurance_policies as p')
            ->join('academic_years as y', 'y.id', '=', 'p.academic_year_id')
            ->when($this->policy !== '', fn ($q) => $q->where('p.id', (int) $this->policy))
            ->when($this->status !== '', fn ($q) => $q->where('p.status', $this->status))
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('p.provider', 'like', '%'.$this->search.'%')
                        ->orWhere('p.policy_no', 'like', '%'.$this->search.'%');
                });
            })
            ->orderByDesc('p.coverage_start')->orderBy('p.policy_no')
            ->select([
                'p.id', 'p.provider', 'p.policy_no', 'p.cover_type',
                'p.premium_per_student', 'p.coverage_start', 'p.coverage_end',
                'p.status', 'y.name as year_name',
            ])
            ->selectSub(
                DB::table('student_insurances')
                    ->whereColumn('policy_id', 'p.id')
                    ->where('status', 'active')
                    ->selectRaw('COUNT(*)'),
                'insured_count'
            )
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function insuredRows(): LengthAwarePaginator
    {
        return DB::table('student_insurances as si')
            ->join('insurance_policies as p', 'p.id', '=', 'si.policy_id')
            ->join('enrollments as e', 'e.id', '=', 'si.enrollment_id')
            ->join('students as s', 's.id', '=', 'e.student_id')
            ->leftJoin('class_levels as cl', 'cl.id', '=', 'e.class_level_id')
            ->when($this->policy !== '', fn ($q) => $q->where('si.policy_id', (int) $this->policy))
            ->when($this->status !== '', fn ($q) => $q->where('si.status', $this->status))
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('s.first_name', 'like', '%'.$this->search.'%')
                        ->orWhere('s.last_name', 'like', '%'.$this->search.'%')
                        ->orWhere('s.matricule', 'like', '%'.$this->search.'%')
                        ->orWhere('si.certificate_no', 'like', '%'.$this->search.'%');
                });
            })
            ->orderBy('s.last_name')->orderBy('s.first_name')
            ->select([
                'si.id', 'si.enrolled_on', 'si.certificate_no', 'si.status',
                'p.policy_no', 'p.provider',
                's.first_name', 's.last_name', 's.matricule',
                'cl.name as class_level',
            ])
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function claimRows(): LengthAwarePaginator
    {
        return DB::table('insurance_claims as c')
            ->join('insurance_policies as p', 'p.id', '=', 'c.policy_id')
            ->leftJoin('student_insurances as si', 'si.id', '=', 'c.student_insurance_id')
            ->leftJoin('enrollments as e', 'e.id', '=', 'si.enrollment_id')
            ->leftJoin('students as s', 's.id', '=', 'e.student_id')
            ->when($this->policy !== '', fn ($q) => $q->where('c.policy_id', (int) $this->policy))
            ->when($this->status !== '', fn ($q) => $q->where('c.status', $this->status))
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('c.description', 'like', '%'.$this->search.'%')
                        ->orWhere('p.policy_no', 'like', '%'.$this->search.'%')
                        ->orWhere('s.first_name', 'like', '%'.$this->search.'%')
                        ->orWhere('s.last_name', 'like', '%'.$this->search.'%');
                });
            })
            ->orderByDesc('c.incident_date')->orderByDesc('c.id')
            ->select([
                'c.id', 'c.incident_date', 'c.description', 'c.amount_claimed',
                'c.amount_settled', 'c.status', 'c.settled_on',
                'p.policy_no', 's.first_name', 's.last_name',
            ])
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * The uninsured list: active enrollments of the report year with no
     * active certificate under any active student policy of that year -
     * the same shape UninsuredStudentsReport serves, paginated for the
     * screen.
     *
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function uninsuredRows(): LengthAwarePaginator
    {
        $yearId = $this->reportYearId();

        return DB::table('enrollments as e')
            ->join('students as s', 's.id', '=', 'e.student_id')
            ->leftJoin('class_levels as cl', 'cl.id', '=', 'e.class_level_id')
            ->where('e.academic_year_id', $yearId ?? 0)
            ->where('e.status', 'active')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('student_insurances as si')
                    ->join('insurance_policies as p', 'p.id', '=', 'si.policy_id')
                    ->whereColumn('si.enrollment_id', 'e.id')
                    ->where('si.status', 'active')
                    ->where('p.cover_type', 'student')
                    ->where('p.status', 'active')
                    ->whereColumn('p.academic_year_id', 'e.academic_year_id')
                    ->when($this->policy !== '', fn ($q) => $q->where('p.id', (int) $this->policy));
            })
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('s.first_name', 'like', '%'.$this->search.'%')
                        ->orWhere('s.last_name', 'like', '%'.$this->search.'%')
                        ->orWhere('s.matricule', 'like', '%'.$this->search.'%');
                });
            })
            ->orderBy('s.last_name')->orderBy('s.first_name')
            ->select([
                'e.id', 's.matricule', 's.first_name', 's.last_name',
                'cl.name as class_level',
            ])
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * The KPI strip, dataset-wide: active policies, live certificates,
     * uninsured students (report year), open claims, settled total.
     *
     * @return array{policies: int, insured: int, uninsured: int, open_claims: int, settled_total: int}
     */
    private function kpis(): array
    {
        $yearId = $this->reportYearId();

        return [
            'policies' => (int) DB::table('insurance_policies')->where('status', 'active')->count(),
            'insured' => (int) DB::table('student_insurances')->where('status', 'active')->count(),
            'uninsured' => (int) DB::table('enrollments as e')
                ->where('e.academic_year_id', $yearId ?? 0)
                ->where('e.status', 'active')
                ->whereNotExists(function ($query): void {
                    $query->select(DB::raw(1))
                        ->from('student_insurances as si')
                        ->join('insurance_policies as p', 'p.id', '=', 'si.policy_id')
                        ->whereColumn('si.enrollment_id', 'e.id')
                        ->where('si.status', 'active')
                        ->where('p.cover_type', 'student')
                        ->where('p.status', 'active')
                        ->whereColumn('p.academic_year_id', 'e.academic_year_id');
                })
                ->count(),
            'open_claims' => (int) DB::table('insurance_claims')
                ->whereIn('status', ['draft', 'submitted'])
                ->count(),
            // (int)-cast: MySQL SUM() comes back as a string.
            'settled_total' => (int) DB::table('insurance_claims')
                ->where('status', 'settled')
                ->sum('amount_settled'),
        ];
    }

    /**
     * The rail's claims picture: counts per status plus the money totals.
     *
     * @return array{draft: int, submitted: int, settled: int, rejected: int, claimed_total: int, settled_total: int}
     */
    private function claimsSummary(): array
    {
        /** @var array<string, int|string> $counts */
        $counts = DB::table('insurance_claims')
            ->selectRaw('status, COUNT(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status')
            ->all();

        return [
            'draft' => (int) ($counts['draft'] ?? 0),
            'submitted' => (int) ($counts['submitted'] ?? 0),
            'settled' => (int) ($counts['settled'] ?? 0),
            'rejected' => (int) ($counts['rejected'] ?? 0),
            'claimed_total' => (int) DB::table('insurance_claims')->sum('amount_claimed'),
            'settled_total' => (int) DB::table('insurance_claims')->where('status', 'settled')->sum('amount_settled'),
        ];
    }

    /**
     * The rail's follow-up list: active policies whose coverage ends within
     * 60 days (or already ended), soonest first.
     *
     * @return list<array{policy_no: string, provider: string, coverage_end: string}>
     */
    private function expiringPolicies(): array
    {
        $rows = DB::table('insurance_policies')
            ->where('status', 'active')
            ->where('coverage_end', '<=', now()->addDays(60)->toDateString())
            ->orderBy('coverage_end')
            ->limit(5)
            ->get(['policy_no', 'provider', 'coverage_end']);

        $list = [];

        foreach ($rows as $row) {
            /** @var object{policy_no: string, provider: string, coverage_end: string} $row */
            $list[] = [
                'policy_no' => $row->policy_no,
                'provider' => $row->provider,
                'coverage_end' => $row->coverage_end,
            ];
        }

        return $list;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function policyOptions(): array
    {
        $options = [];

        foreach (DB::table('insurance_policies')->orderBy('policy_no')->get(['id', 'policy_no', 'provider']) as $row) {
            /** @var object{id: int|string, policy_no: string, provider: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => $row->policy_no.' - '.$row->provider];
        }

        return $options;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function academicYearOptions(): array
    {
        $options = [];

        foreach (DB::table('academic_years')->orderByDesc('starts_on')->get(['id', 'name']) as $row) {
            /** @var object{id: int|string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => $row->name];
        }

        return $options;
    }

    /**
     * Per-tab status filter choices (the WORD carries the meaning, 09-ui 10).
     *
     * @return list<array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return match ($this->tab) {
            'insured' => [
                ['value' => 'active', 'label' => 'Active'],
                ['value' => 'lapsed', 'label' => 'Lapsed'],
                ['value' => 'cancelled', 'label' => 'Cancelled'],
            ],
            'claims' => [
                ['value' => 'draft', 'label' => 'Draft'],
                ['value' => 'submitted', 'label' => 'Submitted'],
                ['value' => 'settled', 'label' => 'Settled'],
                ['value' => 'rejected', 'label' => 'Rejected'],
            ],
            'uninsured' => [],
            default => [
                ['value' => 'active', 'label' => 'Active'],
                ['value' => 'expired', 'label' => 'Expired'],
                ['value' => 'cancelled', 'label' => 'Cancelled'],
            ],
        };
    }

    public function render(): mixed
    {
        $kpis = $this->kpis();

        $tabCounts = [
            'policies' => (int) DB::table('insurance_policies')->count(),
            'insured' => $kpis['insured'],
            'claims' => (int) DB::table('insurance_claims')->count(),
            'uninsured' => $kpis['uninsured'],
        ];

        return view('livewire.welfare.insurance.index', [
            'rows' => $this->rows(),
            'kpis' => $kpis,
            'tabCounts' => $tabCounts,
            'policyOptions' => $this->policyOptions(),
            'academicYearOptions' => $this->academicYearOptions(),
            'statusOptions' => $this->statusOptions(),
            'claimsSummary' => $this->claimsSummary(),
            'expiringPolicies' => $this->expiringPolicies(),
        ]);
    }
}
