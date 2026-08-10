<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Livewire\Hostel;

use App\Modules\Welfare\Actions\AllocateBed;
use App\Modules\Welfare\Actions\EndHostelAllocation;
use App\Modules\Welfare\Actions\RecordInspection;
use App\Modules\Welfare\Actions\SaveBeds;
use App\Modules\Welfare\Actions\SaveHostel;
use App\Modules\Welfare\Actions\SaveRoom;
use App\Modules\Welfare\Domain\HostelGender;
use App\Modules\Welfare\Domain\HostelPermission;
use App\Modules\Welfare\Domain\InspectionRating;
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
 * Hostel Management at /hostel (route wired by W5), gated `hostel.view`,
 * replicating 'frontend images/Hostel Management.png': KPI strip (total
 * rooms / total beds / occupied beds / occupancy rate / open
 * inspections), filter bar, the Hostel Rooms Overview table plus
 * Allocations, Inspections and Occupancy tabs, and the occupancy +
 * hostel-summary + upcoming-inspections rail.
 *
 * Cross-module reads (student names on the Allocations tab) go through
 * DB::table joins only - never another module's Models
 * (ModuleBoundaryTest). One paginated query per render plus the KPI
 * aggregates; no unbounded collection reaches the view (00-core 6.2
 * rule 8, enforced by x-list-screen).
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    /** Which table is showing: rooms | allocations | inspections | occupancy. */
    #[Url]
    public string $tab = 'rooms';

    #[Url]
    public string $hostel = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    // ── Allocate-bed form ────────────────────────────────────────────────
    public bool $showAllocateForm = false;

    public string $allocMatricule = '';

    public string $allocBedId = '';

    public string $allocStartsOn = '';

    // ── Record-inspection form ──────────────────────────────────────────
    public bool $showInspectionForm = false;

    public string $inspHostelId = '';

    public string $inspRoomId = '';

    public string $inspInspectedOn = '';

    public string $inspRating = 'good';

    public string $inspFindings = '';

    // ── Add-hostel form ──────────────────────────────────────────────────
    public bool $showHostelForm = false;

    public string $hostelCode = '';

    public string $hostelName = '';

    public string $hostelGender = 'boys';

    // ── Add-room form ────────────────────────────────────────────────────
    public bool $showRoomForm = false;

    public string $roomHostelId = '';

    public string $roomName = '';

    public string $roomCapacity = '';

    // ── Add-beds form ────────────────────────────────────────────────────
    public bool $showBedsForm = false;

    public string $bedsRoomId = '';

    public string $bedsLabels = '';

    public function mount(): void
    {
        Gate::authorize(HostelPermission::VIEW);
    }

    public function selectTab(string $tab): void
    {
        $this->tab = in_array($tab, ['rooms', 'allocations', 'inspections', 'occupancy'], true)
            ? $tab
            : 'rooms';
        $this->status = '';
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['hostel', 'status', 'search']);
        $this->resetPage();
    }

    public function updatedHostel(): void
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

    public function toggleAllocateForm(): void
    {
        Gate::authorize(HostelPermission::MANAGE);

        $this->showAllocateForm = ! $this->showAllocateForm;

        if ($this->showAllocateForm && $this->allocStartsOn === '') {
            $this->allocStartsOn = Carbon::now()->format('Y-m-d');
        }
    }

    public function saveAllocation(AllocateBed $allocate): void
    {
        Gate::authorize(HostelPermission::MANAGE);

        $matricule = trim($this->allocMatricule);

        if ($matricule === '') {
            $this->addError('allocMatricule', 'Enter the student\'s matricule.');

            return;
        }

        $enrollmentId = DB::table('enrollments as e')
            ->join('students as s', 's.id', '=', 'e.student_id')
            ->where('s.matricule', $matricule)
            ->where('e.status', 'active')
            ->value('e.id');

        if ($enrollmentId === null) {
            $this->addError('allocMatricule', 'No active enrollment carries this matricule.');

            return;
        }

        if ($this->allocBedId === '') {
            $this->addError('allocBedId', 'Choose a bed to allocate.');

            return;
        }

        try {
            $allocate->handle(
                (int) $enrollmentId,
                (int) $this->allocBedId,
                $this->allocStartsOn === '' ? Carbon::today() : Carbon::parse($this->allocStartsOn),
                $this->actor(),
            );
        } catch (DomainException $e) {
            $this->addError('allocBedId', $e->getMessage());

            return;
        }

        $this->reset(['showAllocateForm', 'allocMatricule', 'allocBedId', 'allocStartsOn']);
        $this->tab = 'allocations';
        $this->resetPage();
        session()->flash('status', 'Bed allocated.');
    }

    public function toggleInspectionForm(): void
    {
        Gate::authorize(HostelPermission::MANAGE);

        $this->showInspectionForm = ! $this->showInspectionForm;

        if ($this->showInspectionForm && $this->inspInspectedOn === '') {
            $this->inspInspectedOn = Carbon::now()->format('Y-m-d');
        }
    }

    public function saveInspection(RecordInspection $record): void
    {
        Gate::authorize(HostelPermission::MANAGE);

        if ($this->inspHostelId === '') {
            $this->addError('inspHostelId', 'Choose a hostel.');

            return;
        }

        $rating = InspectionRating::tryFrom($this->inspRating) ?? InspectionRating::Good;

        try {
            $record->handle(
                (int) $this->inspHostelId,
                $this->inspInspectedOn === '' ? Carbon::today() : Carbon::parse($this->inspInspectedOn),
                $rating,
                [
                    'room_id' => $this->inspRoomId === '' ? null : (int) $this->inspRoomId,
                    'findings' => $this->inspFindings === '' ? null : $this->inspFindings,
                ],
                $this->actor(),
            );
        } catch (ValidationException $e) {
            $this->addError('inspHostelId', $e->getMessage());

            return;
        } catch (DomainException $e) {
            $this->addError('inspRoomId', $e->getMessage());

            return;
        }

        $this->reset([
            'showInspectionForm', 'inspHostelId', 'inspRoomId', 'inspInspectedOn', 'inspRating', 'inspFindings',
        ]);
        $this->tab = 'inspections';
        $this->resetPage();
        session()->flash('status', 'Inspection recorded.');
    }

    public function toggleHostelForm(): void
    {
        Gate::authorize(HostelPermission::MANAGE);

        $this->showHostelForm = ! $this->showHostelForm;
    }

    public function saveHostel(SaveHostel $save): void
    {
        Gate::authorize(HostelPermission::MANAGE);

        try {
            $save->handle(null, [
                'code' => trim($this->hostelCode),
                'name' => trim($this->hostelName),
                'gender' => $this->hostelGender,
            ], $this->actor());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError('hostel'.ucfirst($field), (string) ($messages[0] ?? $e->getMessage()));
            }

            return;
        } catch (DomainException $e) {
            $this->addError('hostelCode', $e->getMessage());

            return;
        }

        $this->reset(['showHostelForm', 'hostelCode', 'hostelName', 'hostelGender']);
        $this->hostelGender = 'boys';
        $this->tab = 'occupancy';
        $this->resetPage();
        session()->flash('status', 'Hostel added.');
    }

    public function toggleRoomForm(): void
    {
        Gate::authorize(HostelPermission::MANAGE);

        $this->showRoomForm = ! $this->showRoomForm;
    }

    public function saveRoom(SaveRoom $save): void
    {
        Gate::authorize(HostelPermission::MANAGE);

        try {
            $save->handle(null, [
                'hostel_id' => $this->roomHostelId === '' ? null : (int) $this->roomHostelId,
                'name' => trim($this->roomName),
                'capacity' => $this->roomCapacity === '' ? null : (int) $this->roomCapacity,
            ], $this->actor());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError('room'.ucfirst($field === 'hostel_id' ? 'HostelId' : $field), (string) ($messages[0] ?? $e->getMessage()));
            }

            return;
        } catch (DomainException $e) {
            $this->addError('roomName', $e->getMessage());

            return;
        }

        $this->reset(['showRoomForm', 'roomHostelId', 'roomName', 'roomCapacity']);
        $this->tab = 'rooms';
        $this->resetPage();
        session()->flash('status', 'Room added.');
    }

    public function toggleBedsForm(): void
    {
        Gate::authorize(HostelPermission::MANAGE);

        $this->showBedsForm = ! $this->showBedsForm;
    }

    public function saveBeds(SaveBeds $save): void
    {
        Gate::authorize(HostelPermission::MANAGE);

        if ($this->bedsRoomId === '') {
            $this->addError('bedsRoomId', 'Choose a room.');

            return;
        }

        $labels = array_values(array_filter(array_map(
            static fn (string $l): string => trim($l),
            explode(',', $this->bedsLabels),
        ), static fn (string $l): bool => $l !== ''));

        try {
            $save->handle((int) $this->bedsRoomId, $labels, $this->actor());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError('bedsLabels', (string) ($messages[0] ?? $e->getMessage()));
            }

            return;
        } catch (DomainException $e) {
            $this->addError('bedsLabels', $e->getMessage());

            return;
        }

        $this->reset(['showBedsForm', 'bedsRoomId', 'bedsLabels']);
        $this->tab = 'rooms';
        $this->resetPage();
        session()->flash('status', 'Beds updated.');
    }

    public function endAllocation(int $allocationId, EndHostelAllocation $end): void
    {
        Gate::authorize(HostelPermission::MANAGE);

        try {
            $end->handle($allocationId, Carbon::today(), $this->actor());
        } catch (DomainException $e) {
            session()->flash('status', $e->getMessage());

            return;
        }

        $this->resetPage();
        session()->flash('status', 'Allocation ended.');
    }

    private function actor(): Actor
    {
        /** @var \App\Modules\Identity\Models\User $user */
        $user = auth()->user();

        return $user->toAuditActor();
    }

    /**
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function rows(): LengthAwarePaginator
    {
        return match ($this->tab) {
            'allocations' => $this->allocationRows(),
            'inspections' => $this->inspectionRows(),
            'occupancy' => $this->occupancyRows(),
            default => $this->roomRows(),
        };
    }

    /**
     * The mockup's "Hostel Rooms Overview": each room with its bed stock,
     * occupancy and a derived status word (Full / Occupied / Available).
     *
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function roomRows(): LengthAwarePaginator
    {
        $query = DB::table('hostel_rooms as r')
            ->join('hostels as h', 'h.id', '=', 'r.hostel_id')
            ->when($this->hostel !== '', fn ($q) => $q->where('r.hostel_id', (int) $this->hostel))
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('r.name', 'like', '%'.$this->search.'%')
                        ->orWhere('h.name', 'like', '%'.$this->search.'%')
                        ->orWhere('h.code', 'like', '%'.$this->search.'%');
                });
            })
            ->orderBy('h.code')
            ->orderBy('r.name')
            ->select(['r.id', 'r.name', 'r.capacity', 'h.name as hostel_name', 'h.code as hostel_code'])
            ->selectSub(
                DB::table('hostel_beds')
                    ->whereColumn('room_id', 'r.id')
                    ->where('is_active', true)
                    ->selectRaw('COUNT(*)'),
                'beds_count'
            )
            ->selectSub(
                DB::table('hostel_allocations as a')
                    ->join('hostel_beds as b', 'b.id', '=', 'a.bed_id')
                    ->whereColumn('b.room_id', 'r.id')
                    ->where('a.status', 'active')
                    ->selectRaw('COUNT(*)'),
                'occupied_count'
            );

        if ($this->status !== '') {
            // Derived-word filter: full = every active bed taken,
            // available = at least one free bed, occupied = somewhere in
            // between zero and full.
            $bedsSql = '(SELECT COUNT(*) FROM hostel_beds WHERE room_id = r.id AND is_active = 1)';
            $occSql = '(SELECT COUNT(*) FROM hostel_allocations a JOIN hostel_beds b ON b.id = a.bed_id '
                ."WHERE b.room_id = r.id AND a.status = 'active')";

            match ($this->status) {
                'full' => $query->whereRaw("{$bedsSql} > 0 AND {$occSql} >= {$bedsSql}"),
                'available' => $query->whereRaw("{$bedsSql} > {$occSql}"),
                default => $query->whereRaw("{$occSql} > 0 AND {$occSql} < {$bedsSql}"),
            };
        }

        return $query->paginate($this->perPage, page: $this->page);
    }

    /**
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function allocationRows(): LengthAwarePaginator
    {
        return DB::table('hostel_allocations as a')
            ->join('hostel_beds as b', 'b.id', '=', 'a.bed_id')
            ->join('hostel_rooms as r', 'r.id', '=', 'b.room_id')
            ->join('hostels as h', 'h.id', '=', 'r.hostel_id')
            ->join('enrollments as e', 'e.id', '=', 'a.enrollment_id')
            ->join('students as s', 's.id', '=', 'e.student_id')
            ->when($this->hostel !== '', fn ($q) => $q->where('r.hostel_id', (int) $this->hostel))
            ->when($this->status !== '', fn ($q) => $q->where('a.status', $this->status))
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('s.first_name', 'like', '%'.$this->search.'%')
                        ->orWhere('s.last_name', 'like', '%'.$this->search.'%')
                        ->orWhere('s.matricule', 'like', '%'.$this->search.'%')
                        ->orWhere('r.name', 'like', '%'.$this->search.'%');
                });
            })
            ->orderBy('h.code')
            ->orderBy('r.name')
            ->orderBy('b.label')
            ->select([
                'a.id', 'a.starts_on', 'a.ends_on', 'a.status',
                'h.name as hostel_name', 'h.code as hostel_code',
                'r.name as room_name', 'b.label as bed_label',
                's.first_name', 's.last_name', 's.matricule',
            ])
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function inspectionRows(): LengthAwarePaginator
    {
        return DB::table('hostel_inspections as i')
            ->join('hostels as h', 'h.id', '=', 'i.hostel_id')
            ->leftJoin('hostel_rooms as r', 'r.id', '=', 'i.room_id')
            ->leftJoin('users as u', 'u.id', '=', 'i.inspected_by')
            ->when($this->hostel !== '', fn ($q) => $q->where('i.hostel_id', (int) $this->hostel))
            ->when($this->status !== '', function ($q): void {
                $this->status === 'resolved'
                    ? $q->whereNotNull('i.resolved_at')
                    : $q->where('i.rating', $this->status);
            })
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('h.name', 'like', '%'.$this->search.'%')
                        ->orWhere('i.findings', 'like', '%'.$this->search.'%');
                });
            })
            ->orderByDesc('i.inspected_on')->orderByDesc('i.id')
            ->select([
                'i.id', 'i.inspected_on', 'i.rating', 'i.findings', 'i.resolved_at',
                'h.name as hostel_name', 'h.code as hostel_code',
                'r.name as room_name', 'u.name as inspector_name',
            ])
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function occupancyRows(): LengthAwarePaginator
    {
        return DB::table('hostels as h')
            ->when($this->hostel !== '', fn ($q) => $q->where('h.id', (int) $this->hostel))
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('h.name', 'like', '%'.$this->search.'%')
                        ->orWhere('h.code', 'like', '%'.$this->search.'%');
                });
            })
            ->orderBy('h.code')
            ->select(['h.id', 'h.code', 'h.name', 'h.gender', 'h.is_active'])
            ->selectSub(
                DB::table('hostel_rooms')->whereColumn('hostel_id', 'h.id')->selectRaw('COUNT(*)'),
                'rooms_count'
            )
            ->selectSub(
                DB::table('hostel_beds as b')
                    ->join('hostel_rooms as r', 'r.id', '=', 'b.room_id')
                    ->whereColumn('r.hostel_id', 'h.id')
                    ->where('b.is_active', true)
                    ->selectRaw('COUNT(*)'),
                'beds_count'
            )
            ->selectSub(
                DB::table('hostel_allocations as a')
                    ->join('hostel_beds as b', 'b.id', '=', 'a.bed_id')
                    ->join('hostel_rooms as r', 'r.id', '=', 'b.room_id')
                    ->whereColumn('r.hostel_id', 'h.id')
                    ->where('a.status', 'active')
                    ->selectRaw('COUNT(*)'),
                'occupied_count'
            )
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * The mockup's five KPI cards, dataset-wide (never filter-dependent
     * inventions).
     *
     * @return array{rooms: int, beds: int, occupied: int, occupancy_pct: float, open_inspections: int}
     */
    private function kpis(): array
    {
        $rooms = (int) DB::table('hostel_rooms')->count();
        $beds = (int) DB::table('hostel_beds')->where('is_active', true)->count();
        $occupied = (int) DB::table('hostel_allocations')->where('status', 'active')->count();

        return [
            'rooms' => $rooms,
            'beds' => $beds,
            'occupied' => $occupied,
            'occupancy_pct' => $beds > 0 ? round($occupied * 100 / $beds, 2) : 0.0,
            'open_inspections' => (int) DB::table('hostel_inspections')
                ->whereNull('resolved_at')
                ->whereIn('rating', ['poor', 'critical'])
                ->count(),
        ];
    }

    /**
     * The rail's "Hostel Summary": per-gender room counts and occupancy
     * share, as in the mockup's Boys/Girls/Annex bars.
     *
     * @return list<array{gender: string, hostels: int, rooms: int, beds: int, occupied: int, share: int}>
     */
    private function hostelSummary(): array
    {
        $summary = [];

        foreach (HostelGender::cases() as $case) {
            $row = DB::table('hostels as h')
                ->where('h.gender', $case->value)
                ->selectRaw('COUNT(DISTINCT h.id) as hostels')
                ->selectSub(
                    DB::table('hostel_rooms as r')
                        ->join('hostels as h2', 'h2.id', '=', 'r.hostel_id')
                        ->where('h2.gender', $case->value)
                        ->selectRaw('COUNT(*)'),
                    'rooms'
                )
                ->selectSub(
                    DB::table('hostel_beds as b')
                        ->join('hostel_rooms as r', 'r.id', '=', 'b.room_id')
                        ->join('hostels as h2', 'h2.id', '=', 'r.hostel_id')
                        ->where('h2.gender', $case->value)
                        ->where('b.is_active', true)
                        ->selectRaw('COUNT(*)'),
                    'beds'
                )
                ->selectSub(
                    DB::table('hostel_allocations as a')
                        ->join('hostel_beds as b', 'b.id', '=', 'a.bed_id')
                        ->join('hostel_rooms as r', 'r.id', '=', 'b.room_id')
                        ->join('hostels as h2', 'h2.id', '=', 'r.hostel_id')
                        ->where('h2.gender', $case->value)
                        ->where('a.status', 'active')
                        ->selectRaw('COUNT(*)'),
                    'occupied'
                )
                ->first();

            /** @var object{hostels: int|string, rooms: int|string, beds: int|string, occupied: int|string}|null $row */
            $beds = $row !== null ? (int) $row->beds : 0;
            $occupied = $row !== null ? (int) $row->occupied : 0;

            $summary[] = [
                'gender' => $case->value,
                'hostels' => $row !== null ? (int) $row->hostels : 0,
                'rooms' => $row !== null ? (int) $row->rooms : 0,
                'beds' => $beds,
                'occupied' => $occupied,
                'share' => $beds > 0 ? (int) round($occupied * 100 / $beds) : 0,
            ];
        }

        return $summary;
    }

    /**
     * The rail's "Upcoming Inspections": unresolved poor/critical
     * findings, oldest first - the warden's follow-up list.
     *
     * @return list<array{hostel: string, room: string|null, rating: string, inspected_on: string}>
     */
    private function openInspections(): array
    {
        $rows = DB::table('hostel_inspections as i')
            ->join('hostels as h', 'h.id', '=', 'i.hostel_id')
            ->leftJoin('hostel_rooms as r', 'r.id', '=', 'i.room_id')
            ->whereNull('i.resolved_at')
            ->whereIn('i.rating', ['poor', 'critical'])
            ->orderBy('i.inspected_on')
            ->limit(5)
            ->get(['h.name as hostel', 'r.name as room', 'i.rating', 'i.inspected_on']);

        $list = [];

        foreach ($rows as $row) {
            /** @var object{hostel: string, room: string|null, rating: string, inspected_on: string} $row */
            $list[] = [
                'hostel' => $row->hostel,
                'room' => $row->room,
                'rating' => $row->rating,
                'inspected_on' => $row->inspected_on,
            ];
        }

        return $list;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function hostelOptions(): array
    {
        $options = [];

        foreach (DB::table('hostels')->orderBy('code')->get(['id', 'code', 'name']) as $row) {
            /** @var object{id: int|string, code: string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => $row->code.' - '.$row->name];
        }

        return $options;
    }

    /**
     * Free (unoccupied, active) beds in active hostels - the Allocate Bed
     * form's dropdown, labeled so the operator can see hostel/room/bed at a
     * glance without a cascading select.
     *
     * @return list<array{id: int, name: string}>
     */
    private function availableBedOptions(): array
    {
        $rows = DB::table('hostel_beds as b')
            ->join('hostel_rooms as r', 'r.id', '=', 'b.room_id')
            ->join('hostels as h', 'h.id', '=', 'r.hostel_id')
            ->where('b.is_active', true)
            ->where('h.is_active', true)
            ->whereNotExists(function ($q): void {
                $q->select(DB::raw(1))
                    ->from('hostel_allocations as a')
                    ->whereColumn('a.bed_id', 'b.id')
                    ->where('a.status', 'active');
            })
            ->orderBy('h.code')->orderBy('r.name')->orderBy('b.label')
            ->get(['b.id', 'h.code as hostel_code', 'r.name as room_name', 'b.label as bed_label']);

        $options = [];

        foreach ($rows as $row) {
            /** @var object{id: int|string, hostel_code: string, room_name: string, bed_label: string} $row */
            $options[] = [
                'id' => (int) $row->id,
                'name' => $row->hostel_code.' - '.$row->room_name.' - Bed '.$row->bed_label,
            ];
        }

        return $options;
    }

    /**
     * All rooms, labeled with their hostel - the Record Inspection form's
     * optional room dropdown.
     *
     * @return list<array{id: int, name: string}>
     */
    private function roomOptions(): array
    {
        $rows = DB::table('hostel_rooms as r')
            ->join('hostels as h', 'h.id', '=', 'r.hostel_id')
            ->orderBy('h.code')->orderBy('r.name')
            ->get(['r.id', 'h.code as hostel_code', 'r.name as room_name']);

        $options = [];

        foreach ($rows as $row) {
            /** @var object{id: int|string, hostel_code: string, room_name: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => $row->hostel_code.' - '.$row->room_name];
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
            'allocations' => [
                ['value' => 'active', 'label' => 'Active'],
                ['value' => 'ended', 'label' => 'Ended'],
            ],
            'inspections' => [
                ['value' => 'good', 'label' => 'Good'],
                ['value' => 'fair', 'label' => 'Fair'],
                ['value' => 'poor', 'label' => 'Poor'],
                ['value' => 'critical', 'label' => 'Critical'],
                ['value' => 'resolved', 'label' => 'Resolved'],
            ],
            'occupancy' => [],
            default => [
                ['value' => 'full', 'label' => 'Full'],
                ['value' => 'occupied', 'label' => 'Occupied'],
                ['value' => 'available', 'label' => 'Available'],
            ],
        };
    }

    public function render(): mixed
    {
        $tabCounts = [
            'rooms' => (int) DB::table('hostel_rooms')->count(),
            'allocations' => (int) DB::table('hostel_allocations')->where('status', 'active')->count(),
            'inspections' => (int) DB::table('hostel_inspections')->count(),
            'occupancy' => (int) DB::table('hostels')->count(),
        ];

        return view('livewire.welfare.hostel.index', [
            'rows' => $this->rows(),
            'kpis' => $this->kpis(),
            'tabCounts' => $tabCounts,
            'hostelOptions' => $this->hostelOptions(),
            'statusOptions' => $this->statusOptions(),
            'hostelSummary' => $this->hostelSummary(),
            'openInspections' => $this->openInspections(),
            'availableBedOptions' => $this->availableBedOptions(),
            'roomOptions' => $this->roomOptions(),
            'canManage' => Gate::allows(HostelPermission::MANAGE),
        ]);
    }
}
