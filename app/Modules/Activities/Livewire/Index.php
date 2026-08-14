<?php

declare(strict_types=1);

namespace App\Modules\Activities\Livewire;

use App\Modules\Activities\Actions\CreateActivity;
use App\Modules\Activities\Domain\ActivityPermission;
use App\Modules\Activities\Domain\ActivityType;
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
 * Extra-Curricular Activities at /activities, gated `activity.view`,
 * composing x-list-screen exactly as Welfare\Transport\Index does: KPI
 * strip (active activities / total members / sessions this week / pending
 * consents), filter bar, one paginated table whose row NAME links to the
 * detail page at /activities/{id}, and the create form in the rail.
 *
 * Cross-module reads go through DB::table joins only - never another
 * module's Models (ModuleBoundaryTest). One paginated query per render
 * plus the KPI aggregates; no unbounded collection reaches the view
 * (00-core 6.2 rule 8, enforced by x-list-screen).
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    #[Url]
    public string $type = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    // ── Create Activity form (rendered in the rail) ─────────────────────
    public bool $showCreateForm = false;

    public string $createFormName = '';

    public string $createFormType = 'club';

    public string $createFormVenue = '';

    public string $createFormCapacity = '';

    public string $createFormDescription = '';

    public string $createFormDestination = '';

    public string $createFormDepartureAt = '';

    public string $createFormReturnAt = '';

    public function mount(): void
    {
        Gate::authorize(ActivityPermission::VIEW);
    }

    public function resetFilters(): void
    {
        $this->reset(['type', 'status', 'search']);
        $this->resetPage();
    }

    public function updatedType(): void
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

    public function toggleCreateForm(): void
    {
        Gate::authorize(ActivityPermission::MANAGE);

        $this->showCreateForm = ! $this->showCreateForm;
    }

    public function saveActivity(CreateActivity $createActivity): void
    {
        Gate::authorize(ActivityPermission::MANAGE);

        $this->validate([
            'createFormName' => ['required', 'string', 'max:150'],
            'createFormType' => ['required', 'string', 'in:club,sport,event,excursion'],
            'createFormVenue' => ['nullable', 'string', 'max:150'],
            'createFormCapacity' => ['nullable', 'integer', 'min:1'],
            'createFormDescription' => ['nullable', 'string', 'max:500'],
            'createFormDestination' => ['nullable', 'string', 'max:200'],
            'createFormDepartureAt' => ['nullable', 'date'],
            'createFormReturnAt' => ['nullable', 'date'],
        ], [], [
            'createFormName' => 'name',
            'createFormType' => 'type',
            'createFormVenue' => 'venue',
            'createFormCapacity' => 'capacity',
            'createFormDescription' => 'description',
            'createFormDestination' => 'destination',
            'createFormDepartureAt' => 'departure',
            'createFormReturnAt' => 'return',
        ]);

        try {
            $createActivity->handle([
                'name' => $this->createFormName,
                'type' => $this->createFormType,
                'venue' => $this->createFormVenue,
                'capacity' => $this->createFormCapacity !== '' ? (int) $this->createFormCapacity : null,
                'description' => $this->createFormDescription,
                'destination' => $this->createFormDestination,
                'departure_at' => $this->createFormDepartureAt,
                'return_at' => $this->createFormReturnAt,
            ], $this->actor());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError('createForm'.str_replace(' ', '', ucwords(str_replace('_', ' ', $field))), (string) ($messages[0] ?? 'Invalid value.'));
            }

            return;
        } catch (DomainException $e) {
            $this->addError('createFormName', $e->getMessage());

            return;
        }

        $this->reset([
            'showCreateForm', 'createFormName', 'createFormType', 'createFormVenue',
            'createFormCapacity', 'createFormDescription', 'createFormDestination',
            'createFormDepartureAt', 'createFormReturnAt',
        ]);
        $this->createFormType = 'club';
        $this->resetPage();
        session()->flash('status', 'Activity created.');
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
     * Activities with live member count, session count and next session -
     * the columns an activities coordinator scans first.
     *
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function rows(): LengthAwarePaginator
    {
        $today = Carbon::today()->toDateString();

        return DB::table('activities as a')
            ->when($this->type !== '', fn ($q) => $q->where('a.type', $this->type))
            ->when($this->status !== '', fn ($q) => $q->where('a.status', $this->status))
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('a.name', 'like', '%'.$this->search.'%')
                        ->orWhere('a.venue', 'like', '%'.$this->search.'%')
                        ->orWhere('a.destination', 'like', '%'.$this->search.'%');
                });
            })
            ->orderBy('a.name')
            ->select(['a.id', 'a.name', 'a.type', 'a.venue', 'a.capacity', 'a.status', 'a.destination', 'a.departure_at'])
            ->selectSub(
                DB::table('activity_memberships')
                    ->whereColumn('activity_id', 'a.id')
                    ->where('status', 'active')
                    ->selectRaw('COUNT(*)'),
                'members_count'
            )
            ->selectSub(
                DB::table('activity_sessions')->whereColumn('activity_id', 'a.id')->selectRaw('COUNT(*)'),
                'sessions_count'
            )
            ->selectSub(
                DB::table('activity_sessions')
                    ->whereColumn('activity_id', 'a.id')
                    ->where('scheduled_on', '>=', $today)
                    ->selectRaw('MIN(scheduled_on)'),
                'next_session_on'
            )
            ->selectSub(
                DB::table('activity_memberships')
                    ->whereColumn('activity_id', 'a.id')
                    ->where('status', 'active')
                    ->where('consent_status', 'pending')
                    ->selectRaw('COUNT(*)'),
                'pending_consents'
            )
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * The KPI strip: dataset-wide numbers, never filter-dependent
     * inventions.
     *
     * @return array{active_activities: int, total_members: int, sessions_this_week: int, pending_consents: int}
     */
    private function kpis(): array
    {
        $weekStart = Carbon::today()->startOfWeek()->toDateString();
        $weekEnd = Carbon::today()->endOfWeek()->toDateString();

        return [
            'active_activities' => (int) DB::table('activities')->where('status', 'active')->count(),
            'total_members' => (int) DB::table('activity_memberships')->where('status', 'active')->count(),
            'sessions_this_week' => (int) DB::table('activity_sessions')
                ->whereBetween('scheduled_on', [$weekStart, $weekEnd])
                ->count(),
            'pending_consents' => (int) DB::table('activity_memberships')
                ->where('status', 'active')
                ->where('consent_status', 'pending')
                ->count(),
        ];
    }

    /**
     * The rail's type breakdown - how the programme splits across the
     * four families.
     *
     * @return list<array{type: string, count: int}>
     */
    private function typeBreakdown(): array
    {
        $counts = [];

        $rows = DB::table('activities')
            ->where('status', 'active')
            ->groupBy('type')
            ->get([DB::raw('type'), DB::raw('COUNT(*) as n')]);

        foreach ($rows as $row) {
            /** @var object{type: string, n: int|string} $row */
            $counts[$row->type] = (int) $row->n;
        }

        $breakdown = [];

        foreach (ActivityType::cases() as $case) {
            $breakdown[] = ['type' => $case->value, 'count' => $counts[$case->value] ?? 0];
        }

        return $breakdown;
    }

    public function render(): mixed
    {
        return view('livewire.activities.index', [
            'rows' => $this->rows(),
            'kpis' => $this->kpis(),
            'typeBreakdown' => $this->typeBreakdown(),
            'canManage' => Gate::allows(ActivityPermission::MANAGE),
        ]);
    }
}
