<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Livewire\Visitors;

use App\Modules\Welfare\Actions\CheckInVisitor;
use App\Modules\Welfare\Actions\CheckOutVisitor;
use App\Modules\Welfare\Domain\VisitorHostType;
use App\Modules\Welfare\Domain\VisitorPermission;
use App\Modules\Welfare\Models\VisitorLog;
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
 * The gate desk at /welfare/visitors (route wired by W5), gated
 * `visitor.manage` (phase-10 plan §5: not a sidebar item). No dedicated
 * mockup exists for this screen, so it mirrors the Phase 10
 * Transport/Hostel/Medical chrome exactly (x-list-screen + x-kpi-card +
 * x-status-pill): KPI strip, check-in form, tabbed register, right rail.
 *
 * The ID document reference is encrypted at rest, so register reads go
 * through Welfare's OWN model - decrypting a page at a time - while host
 * identity (a staff user or a student) comes from DB::table lookups keyed
 * on the page's host ids (never other modules' Models: ModuleBoundaryTest).
 * All writes go through the W4 Actions, which re-check `visitor.manage`
 * (rule 17: enforced in Actions, not menus).
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    /** Which table is showing: onsite | register. */
    #[Url]
    public string $tab = 'onsite';

    #[Url]
    public string $hostType = '';

    #[Url]
    public string $search = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    // ── Check-in form ───────────────────────────────────────────────────
    public bool $showForm = false;

    public string $formName = '';

    public string $formPhone = '';

    public string $formIdRef = '';

    public string $formPurpose = '';

    public string $formHostType = 'office';

    /** Student matricule or staff email, resolved to host_id on save. */
    public string $formHostRef = '';

    public string $formBadge = '';

    public string $formCheckedInAt = '';

    /**
     * Gate pass number issued with the badge at the desk. CheckInVisitor has
     * always accepted it; the form simply never asked for it.
     */
    public string $formGatePass = '';

    /**
     * Gate pass numbers captured on the way OUT, keyed by visitor log id.
     * CheckOutVisitor writes one when supplied and keeps the check-in value
     * otherwise, so a pass issued at exit is recordable too.
     *
     * @var array<int|string, string>
     */
    public array $checkoutGatePass = [];

    public function mount(): void
    {
        Gate::authorize(VisitorPermission::MANAGE);
    }

    public function selectTab(string $tab): void
    {
        $this->tab = in_array($tab, ['onsite', 'register'], true) ? $tab : 'onsite';
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['hostType', 'search']);
        $this->resetPage();
    }

    public function updatedHostType(): void
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
        Gate::authorize(VisitorPermission::MANAGE);

        $this->showForm = ! $this->showForm;

        if ($this->showForm && $this->formCheckedInAt === '') {
            $this->formCheckedInAt = Carbon::now()->format('Y-m-d\TH:i');
        }
    }

    public function saveCheckIn(CheckInVisitor $checkIn): void
    {
        Gate::authorize(VisitorPermission::MANAGE);

        $hostType = VisitorHostType::tryFrom($this->formHostType) ?? VisitorHostType::Office;

        $hostId = null;

        if ($hostType !== VisitorHostType::Office) {
            $hostRef = trim($this->formHostRef);

            if ($hostRef === '') {
                $this->addError('formHostRef', $hostType === VisitorHostType::Student
                    ? 'Enter the student\'s matricule.'
                    : 'Enter the staff member\'s email.');

                return;
            }

            // Cross-module identity lookups: DB::table only.
            $hostId = $hostType === VisitorHostType::Student
                ? DB::table('students')->where('matricule', $hostRef)->value('id')
                : DB::table('users')->where('email', $hostRef)->value('id');

            if ($hostId === null) {
                $this->addError('formHostRef', $hostType === VisitorHostType::Student
                    ? 'No student carries this matricule.'
                    : 'No staff account carries this email.');

                return;
            }
        }

        try {
            $checkIn->handle(
                $this->formName,
                $this->formPhone,
                $this->formIdRef === '' ? null : $this->formIdRef,
                $this->formPurpose,
                $hostType,
                $hostId === null ? null : (int) $hostId,
                $this->formBadge,
                $this->formCheckedInAt === '' ? Carbon::now() : Carbon::parse($this->formCheckedInAt),
                $this->actor(),
                $this->formGatePass === '' ? null : $this->formGatePass,
            );
        } catch (ValidationException $e) {
            $this->addError('formName', $e->getMessage());

            return;
        } catch (DomainException $e) {
            $this->addError('formBadge', $e->getMessage());

            return;
        }

        $this->reset([
            'showForm', 'formName', 'formPhone', 'formIdRef', 'formPurpose',
            'formHostType', 'formHostRef', 'formBadge', 'formCheckedInAt',
            'formGatePass',
        ]);
        $this->tab = 'onsite';
        $this->resetPage();
        session()->flash('status', 'Visitor checked in.');
    }

    public function checkOut(int $visitorLogId, CheckOutVisitor $checkOut): void
    {
        Gate::authorize(VisitorPermission::MANAGE);

        // A pass issued at the barrier on the way out overrides the one taken
        // at check-in; CheckOutVisitor keeps the existing value when null.
        $gatePass = trim($this->checkoutGatePass[$visitorLogId] ?? '');

        try {
            $checkOut->handle(
                $visitorLogId,
                Carbon::now(),
                $this->actor(),
                $gatePass === '' ? null : $gatePass,
            );
        } catch (DomainException $e) {
            $this->addError('checkout', $e->getMessage());

            return;
        }

        unset($this->checkoutGatePass[$visitorLogId]);

        session()->flash('status', 'Visitor checked out; the badge is free again.');
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
     * @return LengthAwarePaginator<int, VisitorLog>
     */
    private function rows(): LengthAwarePaginator
    {
        return VisitorLog::query()
            ->when($this->tab === 'onsite', fn ($q) => $q->onSite())
            ->when($this->hostType !== '', fn ($q) => $q->where('host_type', $this->hostType))
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('visitor_name', 'like', '%'.$this->search.'%')
                        ->orWhere('badge_no', 'like', '%'.$this->search.'%')
                        ->orWhere('phone', 'like', '%'.$this->search.'%');
                });
            })
            ->orderByDesc('checked_in_at')
            ->orderByDesc('id')
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * Host labels for the page's rows, keyed by row id. Staff hosts live in
     * users, student hosts in students - DB::table reads only.
     *
     * @param  list<VisitorLog>  $rows
     * @return array<int, string>
     */
    private function hostLabels(array $rows): array
    {
        $userIds = [];
        $studentIds = [];

        foreach ($rows as $row) {
            if ($row->host_id === null) {
                continue;
            }

            if ($row->host_type === VisitorHostType::Staff) {
                $userIds[] = $row->host_id;
            } elseif ($row->host_type === VisitorHostType::Student) {
                $studentIds[] = $row->host_id;
            }
        }

        $userNames = [];

        if ($userIds !== []) {
            $userRows = DB::table('users')
                ->whereIn('id', array_values(array_unique($userIds)))
                ->get(['id', 'name']);

            foreach ($userRows as $userRow) {
                /** @var object{id: int|string, name: string} $userRow */
                $userNames[(int) $userRow->id] = $userRow->name;
            }
        }

        $studentNames = [];

        if ($studentIds !== []) {
            $studentRows = DB::table('students')
                ->whereIn('id', array_values(array_unique($studentIds)))
                ->get(['id', 'first_name', 'last_name', 'matricule']);

            foreach ($studentRows as $studentRow) {
                /** @var object{id: int|string, first_name: string, last_name: string, matricule: string} $studentRow */
                $studentNames[(int) $studentRow->id] =
                    trim($studentRow->first_name.' '.$studentRow->last_name)
                    .' ('.$studentRow->matricule.')';
            }
        }

        $labels = [];

        foreach ($rows as $row) {
            $labels[$row->id] = match ($row->host_type) {
                VisitorHostType::Office => 'Office',
                VisitorHostType::Staff => $row->host_id !== null
                    ? ($userNames[$row->host_id] ?? 'Staff #'.$row->host_id)
                    : 'Staff',
                VisitorHostType::Student => $row->host_id !== null
                    ? ($studentNames[$row->host_id] ?? 'Student #'.$row->host_id)
                    : 'Student',
            };
        }

        return $labels;
    }

    /**
     * The KPI strip: the desk's four questions.
     *
     * @return array{on_site: int, today: int, checked_out_today: int, week: int}
     */
    private function kpis(): array
    {
        $todayStart = Carbon::today()->startOfDay();

        return [
            'on_site' => (int) DB::table('visitor_logs')->whereNull('checked_out_at')->count(),
            'today' => (int) DB::table('visitor_logs')->where('checked_in_at', '>=', $todayStart)->count(),
            'checked_out_today' => (int) DB::table('visitor_logs')->where('checked_out_at', '>=', $todayStart)->count(),
            'week' => (int) DB::table('visitor_logs')
                ->where('checked_in_at', '>=', Carbon::today()->subDays(6)->startOfDay())
                ->count(),
        ];
    }

    /**
     * The rail's host-type breakdown over the last 30 days.
     *
     * @return array<string, int>
     */
    private function hostBreakdown(): array
    {
        $breakdown = ['staff' => 0, 'student' => 0, 'office' => 0];

        $rows = DB::table('visitor_logs')
            ->where('checked_in_at', '>=', Carbon::today()->subDays(30)->startOfDay())
            ->selectRaw('host_type, COUNT(*) AS total')
            ->groupBy('host_type')
            ->get();

        foreach ($rows as $row) {
            /** @var object{host_type: string, total: int|string} $row */
            $breakdown[$row->host_type] = (int) $row->total;
        }

        return $breakdown;
    }

    /**
     * The rail's "Longest on site" list: open visits, oldest first - the
     * badges the desk should chase before closing the gate.
     *
     * @return list<array{visitor: string, badge: string, since: string}>
     */
    private function longestOnSite(): array
    {
        $rows = VisitorLog::query()
            ->onSite()
            ->orderBy('checked_in_at')
            ->limit(5)
            ->get(['visitor_name', 'badge_no', 'checked_in_at']);

        $longest = [];

        foreach ($rows as $row) {
            $longest[] = [
                'visitor' => $row->visitor_name,
                'badge' => $row->badge_no,
                'since' => $row->checked_in_at->format('Y-m-d H:i'),
            ];
        }

        return $longest;
    }

    public function render(): mixed
    {
        $rows = $this->rows();

        /** @var list<VisitorLog> $items */
        $items = $rows->items();

        $tabCounts = [
            'onsite' => (int) DB::table('visitor_logs')->whereNull('checked_out_at')->count(),
            'register' => (int) DB::table('visitor_logs')->count(),
        ];

        return view('livewire.welfare.visitors.index', [
            'rows' => $rows,
            'kpis' => $this->kpis(),
            'tabCounts' => $tabCounts,
            'hosts' => $this->hostLabels($items),
            'hostBreakdown' => $this->hostBreakdown(),
            'longestOnSite' => $this->longestOnSite(),
        ]);
    }
}
