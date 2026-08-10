<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Livewire\Hostel;

use App\Modules\Reporting\Support\PdfExport;
use App\Modules\Welfare\Domain\HostelPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * One room's occupancy file at /hostel/rooms/{room}, gated `hostel.view`
 * (Hostel\Index's own gate) - the room's hostel, its beds, and the
 * current + historical allocations across every bed in the room, plus a
 * printable "Room Card" (Assets\Livewire\Show's asset-card pattern).
 *
 * Cross-module reads (student names on the allocation history) go through
 * DB::table joins only, never an Eloquent model reach across module
 * boundaries (ModuleBoundaryTest); the history list is capped, no
 * unbounded collection reaches the view (00-core 6.2 rule 8).
 */
#[Layout('layouts.app')]
final class RoomShow extends Component
{
    /** Cap on the allocation-history list. */
    private const int HISTORY_LIMIT = 100;

    public int $roomId;

    public function mount(int $room): void
    {
        Gate::authorize(HostelPermission::VIEW);

        $this->roomId = $room;

        // 404 early rather than rendering an empty card.
        DB::table('hostel_rooms')->where('id', $room)->firstOrFail();
    }

    private function roomRow(): stdClass
    {
        /** @var stdClass $row */
        $row = DB::table('hostel_rooms as r')
            ->join('hostels as h', 'h.id', '=', 'r.hostel_id')
            ->where('r.id', $this->roomId)
            ->select(['r.id', 'r.name', 'r.capacity', 'h.id as hostel_id', 'h.code as hostel_code', 'h.name as hostel_name', 'h.gender'])
            ->first();

        return $row;
    }

    /**
     * @return \Illuminate\Support\Collection<int, stdClass>
     */
    private function beds(): \Illuminate\Support\Collection
    {
        return DB::table('hostel_beds as b')
            ->where('b.room_id', $this->roomId)
            ->orderBy('b.label')
            ->select(['b.id', 'b.label', 'b.is_active'])
            ->selectSub(
                DB::table('hostel_allocations')
                    ->whereColumn('bed_id', 'b.id')
                    ->where('status', 'active')
                    ->selectRaw('COUNT(*)'),
                'occupied'
            )
            ->get();
    }

    /**
     * Current + historical allocations across every bed in this room,
     * newest first.
     *
     * @return \Illuminate\Support\Collection<int, stdClass>
     */
    private function allocationHistory(): \Illuminate\Support\Collection
    {
        return DB::table('hostel_allocations as a')
            ->join('hostel_beds as b', 'b.id', '=', 'a.bed_id')
            ->join('enrollments as e', 'e.id', '=', 'a.enrollment_id')
            ->join('students as s', 's.id', '=', 'e.student_id')
            ->where('b.room_id', $this->roomId)
            ->orderByDesc('a.starts_on')
            ->orderByDesc('a.id')
            ->limit(self::HISTORY_LIMIT)
            ->select([
                'a.id', 'a.starts_on', 'a.ends_on', 'a.status',
                'b.label as bed_label',
                's.first_name', 's.last_name', 's.matricule',
            ])
            ->get();
    }

    // ── Export ────────────────────────────────────────────────────────────

    public function exportRoomCardPdf(): Response
    {
        Gate::authorize(HostelPermission::VIEW);

        $room = $this->roomRow();

        return PdfExport::download(
            'Room Card — '.$room->hostel_code.' / '.$room->name,
            ['Field', 'Value'],
            $this->roomCardRows(),
            'room-card-'.$room->hostel_code.'-'.$room->name.'.pdf',
        );
    }

    /**
     * @return iterable<int, list<mixed>>
     */
    private function roomCardRows(): iterable
    {
        $room = $this->roomRow();
        $beds = $this->beds();
        $occupied = $beds->sum(fn (stdClass $b): int => (int) $b->occupied);

        yield ['Hostel', $room->hostel_code.' — '.$room->hostel_name];
        yield ['Room', $room->name];
        yield ['Gender', ucfirst($room->gender)];
        yield ['Capacity', (string) $room->capacity];
        yield ['Beds', (string) $beds->count()];
        yield ['Occupied Beds', (string) $occupied];
    }

    public function render(): mixed
    {
        return view('livewire.welfare.hostel.room-show', [
            'room' => $this->roomRow(),
            'beds' => $this->beds(),
            'allocationHistory' => $this->allocationHistory(),
        ]);
    }
}
