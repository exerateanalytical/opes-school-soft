<?php

declare(strict_types=1);

namespace App\Modules\Admissions\Livewire;

use App\Modules\Admissions\Actions\ConvertApplication;
use App\Modules\Admissions\Actions\RejectApplication;
use App\Modules\Admissions\Domain\ApplicationStatus;
use App\Modules\Admissions\Models\AdmissionApplication;
use App\Modules\Identity\Domain\Permission;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The admissions QUEUE, docs/specs/07-students.md 6.2-6.3.
 *
 * The wizard was built; the list was not, so /admissions dropped a registrar
 * straight into a blank step 1 with no way to see the drafts they had already
 * started or the applications waiting on a decision. This screen is that
 * missing triage view and nothing more:
 *
 *  - Every row action delegates to an EXISTING Action (ConvertApplication,
 *    RejectApplication) or hands off to the Wizard. No admission logic is
 *    duplicated here - in particular the conversion idempotency guard, the
 *    row lock and the retention clock all stay where they were written.
 *  - Reads go through DB::table with joins to the academics reference tables
 *    (ModuleBoundaryTest: never another module's Models). Only the two write
 *    paths load AdmissionApplication, because that is the type the Actions
 *    accept.
 *  - The listed columns are deliberately the non-encrypted ones. Religion,
 *    blood group, genotype and special information are encrypted at rest
 *    (6.1) and have no business on a triage list.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    /** Status tab: one of ApplicationStatus, or 'all'. */
    #[Url]
    public string $tab = 'submitted';

    #[Url]
    public string $search = '';

    #[Url]
    public string $academicYear = '';

    #[Url]
    public string $classLevel = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    public string $statusMessage = '';

    // --- Reject dialog -----------------------------------------------------

    public ?int $rejectingId = null;

    public string $rejectionReason = '';

    // --- Convert dialog ----------------------------------------------------

    public ?int $convertingId = null;

    public string $classGroupId = '';

    public function mount(): void
    {
        Gate::authorize(Permission::AdmissionsManage->value);
    }

    public function selectTab(string $tab): void
    {
        $this->tab = $this->isKnownTab($tab) ? $tab : 'all';
        $this->page = 1;
        $this->closeDialogs();
    }

    private function isKnownTab(string $tab): bool
    {
        return $tab === 'all' || ApplicationStatus::tryFrom($tab) !== null;
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'academicYear', 'classLevel']);
        $this->page = 1;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'academicYear', 'classLevel', 'perPage'], true)) {
            $this->page = 1;
        }
    }

    public function closeDialogs(): void
    {
        $this->rejectingId = null;
        $this->convertingId = null;
        $this->rejectionReason = '';
        $this->classGroupId = '';
    }

    /**
     * Continue (or review) an application in the existing 5-step Wizard.
     *
     * Route::has() rather than a bare route(): today `/admissions` IS the
     * wizard, and the list route has not been wired yet. Once the wiring lands
     * this picks up the dedicated name without a code change, and it never
     * throws in the meantime.
     */
    public function wizardUrl(int $id): string
    {
        $parameters = ['application' => $id];

        if (Route::has('admissions.wizard')) {
            return route('admissions.wizard', $parameters);
        }

        return route('admissions.index', $parameters);
    }

    // --- Reject ------------------------------------------------------------

    public function startReject(int $id): void
    {
        Gate::authorize(Permission::AdmissionsManage->value);

        $this->convertingId = null;
        $this->rejectingId = $id;
        $this->rejectionReason = '';
    }

    public function reject(RejectApplication $rejectApplication): void
    {
        Gate::authorize(Permission::AdmissionsManage->value);

        $application = $this->application($this->rejectingId);

        if ($application === null) {
            return;
        }

        // The blank-reason guard lives in the Action (a rejection nobody has
        // to explain is a rejection nobody can appeal); its ValidationException
        // surfaces into this component's error bag unchanged.
        $rejected = $rejectApplication->handle($application, $this->rejectionReason);

        $this->statusMessage = 'Application '.($rejected->application_no ?? '#'.$rejected->id)
            .' rejected. Record is purged on '.($rejected->purge_due_on?->toDateString() ?? 'the retention date').'.';

        $this->closeDialogs();
    }

    // --- Convert -----------------------------------------------------------

    public function startConvert(int $id): void
    {
        Gate::authorize(Permission::AdmissionsManage->value);

        $this->rejectingId = null;
        $this->convertingId = $id;
        $this->classGroupId = '';
    }

    public function convert(ConvertApplication $convertApplication): void
    {
        Gate::authorize(Permission::AdmissionsManage->value);

        $application = $this->application($this->convertingId);

        if ($application === null) {
            return;
        }

        if ($this->classGroupId === '') {
            // 6.3 step 4: the class GROUP is chosen at conversion, when
            // capacity is known - the application only ever named a LEVEL.
            throw ValidationException::withMessages([
                'classGroupId' => 'Choose the class group this applicant is enrolled into.',
            ]);
        }

        try {
            $convertApplication->handle($application, (int) $this->classGroupId);
        } catch (DomainException) {
            // The already-converted guard. Pressing Confirm twice is an
            // ordinary human action, not a 500.
            throw ValidationException::withMessages([
                'classGroupId' => 'This application has already been converted into a student.',
            ]);
        }

        $this->statusMessage = 'Application '.($application->application_no ?? '#'.$application->id)
            .' converted into a student record.';

        $this->closeDialogs();
    }

    private function application(?int $id): ?AdmissionApplication
    {
        if ($id === null) {
            return null;
        }

        /** @var AdmissionApplication|null $application */
        $application = AdmissionApplication::query()->find($id);

        return $application;
    }

    // --- Reads -------------------------------------------------------------

    /**
     * @return Builder
     */
    private function query()
    {
        return DB::table('admission_applications as aa')
            ->leftJoin('academic_years as ay', 'ay.id', '=', 'aa.academic_year_id')
            ->leftJoin('class_levels as cl', 'cl.id', '=', 'aa.class_level_id')
            ->leftJoin('school_sections as ss', 'ss.id', '=', 'aa.school_section_id')
            ->when($this->tab !== 'all', fn ($q) => $q->where('aa.status', $this->tab))
            ->when($this->academicYear !== '', fn ($q) => $q->where('aa.academic_year_id', (int) $this->academicYear))
            ->when($this->classLevel !== '', fn ($q) => $q->where('aa.class_level_id', (int) $this->classLevel))
            ->when($this->search !== '', function ($q): void {
                $term = '%'.$this->search.'%';
                $q->where(function ($inner) use ($term): void {
                    $inner->where('aa.application_no', 'like', $term)
                        ->orWhere('aa.first_name', 'like', $term)
                        ->orWhere('aa.middle_name', 'like', $term)
                        ->orWhere('aa.last_name', 'like', $term);
                });
            })
            ->orderByDesc('aa.updated_at')
            ->orderByDesc('aa.id')
            ->select([
                'aa.id', 'aa.application_no', 'aa.first_name', 'aa.middle_name', 'aa.last_name',
                'aa.gender', 'aa.status', 'aa.current_step', 'aa.completed_step',
                'aa.submitted_at', 'aa.decided_at', 'aa.decision_reason',
                'aa.converted_student_id', 'aa.academic_year_id', 'aa.class_level_id',
                'aa.updated_at',
                'ay.code as academic_year_code',
                'cl.name as class_level_name',
                'ss.name as section_name',
            ]);
    }

    /**
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function rows(): LengthAwarePaginator
    {
        return $this->query()->paginate($this->perPage, page: $this->page);
    }

    /**
     * @return array<string, int>
     */
    private function tabCounts(): array
    {
        $counts = ['all' => 0];

        foreach (ApplicationStatus::cases() as $case) {
            $counts[$case->value] = 0;
        }

        foreach (DB::table('admission_applications')->select('status', DB::raw('count(*) as aggregate'))->groupBy('status')->get() as $row) {
            /** @var object{status: string, aggregate: int|string} $row */
            $counts[$row->status] = (int) $row->aggregate;
            $counts['all'] += (int) $row->aggregate;
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $counts
     * @return array{total: int, drafts: int, awaiting_decision: int, accepted: int, enrolled: int}
     */
    private function kpis(array $counts): array
    {
        return [
            'total' => $counts['all'],
            'drafts' => $counts[ApplicationStatus::Draft->value] ?? 0,
            'awaiting_decision' => ($counts[ApplicationStatus::Submitted->value] ?? 0)
                + ($counts[ApplicationStatus::UnderReview->value] ?? 0),
            'accepted' => $counts[ApplicationStatus::Accepted->value] ?? 0,
            'enrolled' => $counts[ApplicationStatus::Enrolled->value] ?? 0,
        ];
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    private function options(string $table, string $labelColumn): array
    {
        $options = [];

        foreach (DB::table($table)->orderBy($labelColumn)->get(['id', $labelColumn]) as $row) {
            // Array, not property access: the label column is chosen by the
            // caller, so there is no fixed object shape to declare.
            /** @var array<string, mixed> $attributes */
            $attributes = (array) $row;

            $options[] = [
                'id' => (int) ($attributes['id'] ?? 0),
                'label' => (string) ($attributes[$labelColumn] ?? ''),
            ];
        }

        return $options;
    }

    /**
     * Class groups offered in the convert dialog, narrowed to the year and
     * level the application actually named. An operator should not be able to
     * drop a Form 1 applicant into a Form 4 group from a triage list.
     *
     * @return list<array{id: int, label: string}>
     */
    private function classGroupOptions(): array
    {
        if ($this->convertingId === null) {
            return [];
        }

        $application = DB::table('admission_applications')
            ->where('id', $this->convertingId)
            ->first(['academic_year_id', 'class_level_id']);

        if ($application === null || $application->academic_year_id === null) {
            return [];
        }

        $query = DB::table('class_groups')
            ->where('academic_year_id', (int) $application->academic_year_id)
            ->orderBy('name');

        if ($application->class_level_id !== null) {
            $query->where('class_level_id', (int) $application->class_level_id);
        }

        $options = [];

        foreach ($query->get(['id', 'name']) as $row) {
            /** @var object{id: int|string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'label' => (string) $row->name];
        }

        return $options;
    }

    public function render(): mixed
    {
        $counts = $this->tabCounts();

        return view('livewire.admissions.index', [
            'rows' => $this->rows(),
            'tabCounts' => $counts,
            'kpis' => $this->kpis($counts),
            'academicYearOptions' => $this->options('academic_years', 'code'),
            'classLevelOptions' => $this->options('class_levels', 'name'),
            'classGroupOptions' => $this->classGroupOptions(),
            'newDraftUrl' => Route::has('admissions.wizard')
                ? route('admissions.wizard')
                : route('admissions.index'),
        ]);
    }
}
