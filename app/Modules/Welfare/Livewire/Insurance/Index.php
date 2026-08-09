<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Livewire\Insurance;

use App\Modules\Welfare\Domain\InsurancePermission;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
            'statusOptions' => $this->statusOptions(),
            'claimsSummary' => $this->claimsSummary(),
            'expiringPolicies' => $this->expiringPolicies(),
        ]);
    }
}
