<?php

declare(strict_types=1);

namespace App\Modules\Alumni\Livewire;

use App\Modules\Alumni\Actions\ConvertGraduateToAlumnus;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The alumni register, gated `alumni.view`: KPI strip (total alumni /
 * this year's cohort / engagements this year / reachable), year and
 * occupation filters, and the "Convert graduates" bulk entry point -
 * the list of graduated students not yet converted, converted through
 * the REAL ConvertGraduateToAlumnus door one by one.
 *
 * Cross-module reads (student names) go through DB::table joins only -
 * never another module's Models (ModuleBoundaryTest). One paginated
 * query per render plus the KPI aggregates.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    #[Url]
    public string $year = '';

    #[Url]
    public string $occupation = '';

    #[Url]
    public string $search = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    // ── Convert graduates panel ─────────────────────────────────────────
    public bool $showConvertPanel = false;

    /** @var list<int|string> */
    public array $selectedStudentIds = [];

    public function mount(): void
    {
        Gate::authorize(Permission::AlumniView->value);
    }

    public function resetFilters(): void
    {
        $this->reset(['year', 'occupation', 'search']);
        $this->resetPage();
    }

    public function updatedYear(): void
    {
        $this->resetPage();
    }

    public function updatedOccupation(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function toggleConvertPanel(): void
    {
        Gate::authorize(ConvertGraduateToAlumnus::PERMISSION);

        $this->showConvertPanel = ! $this->showConvertPanel;
        $this->selectedStudentIds = [];
    }

    public function convertSelected(ConvertGraduateToAlumnus $convert): void
    {
        Gate::authorize(ConvertGraduateToAlumnus::PERMISSION);

        $ids = array_values(array_unique(array_map(intval(...), $this->selectedStudentIds)));

        if ($ids === []) {
            $this->addError('selectedStudentIds', __('alumni.convert_none_selected'));

            return;
        }

        $converted = 0;

        foreach ($ids as $studentId) {
            try {
                $convert->handle($studentId, $this->actor());
                $converted++;
            } catch (DomainException $e) {
                // One refusal (raced double-convert, a status that changed
                // under us) must not roll back the students already through.
                $this->addError('selectedStudentIds', $e->getMessage());
            }
        }

        $this->selectedStudentIds = [];

        if ($converted > 0) {
            $this->showConvertPanel = false;
            $this->resetPage();
            session()->flash('status', trans_choice('alumni.converted_count', $converted, ['count' => $converted]));
        }
    }

    private function actor(): Actor
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
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function rows(): LengthAwarePaginator
    {
        return DB::table('alumnus_records as ar')
            ->join('students as s', 's.id', '=', 'ar.student_id')
            ->when($this->year !== '', fn ($q) => $q->where('ar.graduation_year', (int) $this->year))
            ->when($this->occupation !== '', function ($q): void {
                $q->where('ar.current_occupation', 'like', '%'.$this->occupation.'%');
            })
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('s.first_name', 'like', '%'.$this->search.'%')
                        ->orWhere('s.last_name', 'like', '%'.$this->search.'%')
                        ->orWhere('s.matricule', 'like', '%'.$this->search.'%');
                });
            })
            ->orderByDesc('ar.graduation_year')
            ->orderBy('s.last_name')
            ->orderBy('s.first_name')
            ->select([
                'ar.id', 'ar.graduation_year', 'ar.final_class_group_name',
                'ar.current_occupation', 'ar.current_organisation',
                'ar.contact_email', 'ar.contact_phone', 'ar.is_deceased',
                's.first_name', 's.last_name', 's.matricule',
            ])
            ->selectSub(
                DB::table('alumni_engagements')
                    ->whereColumn('alumnus_record_id', 'ar.id')
                    ->selectRaw('COUNT(*)'),
                'engagement_count'
            )
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * Graduated students with no AlumnusRecord yet - the convert panel's
     * worklist. Bounded: this is a worklist, not an archive browser.
     *
     * @return list<object{id: int, first_name: string, last_name: string, matricule: string}>
     */
    private function unconvertedGraduates(): array
    {
        $rows = [];

        $query = DB::table('students as s')
            ->leftJoin('alumnus_records as ar', 'ar.student_id', '=', 's.id')
            ->where('s.status', 'graduated')
            ->whereNull('ar.id')
            ->orderBy('s.last_name')
            ->orderBy('s.first_name')
            ->limit(200)
            ->get(['s.id', 's.first_name', 's.last_name', 's.matricule']);

        foreach ($query as $row) {
            /** @var object{id: int|string, first_name: string, last_name: string, matricule: string} $row */
            $rows[] = (object) [
                'id' => (int) $row->id,
                'first_name' => $row->first_name,
                'last_name' => $row->last_name,
                'matricule' => $row->matricule,
            ];
        }

        return $rows;
    }

    /**
     * Dataset-wide KPI numbers, never filter-dependent.
     *
     * @return array{total: int, cohort: int, engagements_this_year: int, reachable: int}
     */
    private function kpis(): array
    {
        $currentYear = (int) Carbon::now()->format('Y');

        return [
            'total' => (int) DB::table('alumnus_records')->count(),
            'cohort' => (int) DB::table('alumnus_records')
                ->where('graduation_year', $currentYear)
                ->count(),
            'engagements_this_year' => (int) DB::table('alumni_engagements')
                ->whereBetween('engaged_on', [$currentYear.'-01-01', $currentYear.'-12-31'])
                ->count(),
            'reachable' => (int) DB::table('alumnus_records')
                ->where(function ($q): void {
                    $q->whereNotNull('contact_email')->orWhereNotNull('contact_phone');
                })
                ->count(),
        ];
    }

    /**
     * @return list<int>
     */
    private function yearOptions(): array
    {
        $years = [];

        foreach (DB::table('alumnus_records')->distinct()->orderByDesc('graduation_year')->pluck('graduation_year') as $year) {
            $years[] = (int) $year;
        }

        return $years;
    }

    public function render(): mixed
    {
        return view('livewire.alumni.index', [
            'rows' => $this->rows(),
            'kpis' => $this->kpis(),
            'yearOptions' => $this->yearOptions(),
            'unconvertedGraduates' => $this->showConvertPanel ? $this->unconvertedGraduates() : [],
        ]);
    }
}
