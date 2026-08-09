<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Actions;

use App\Modules\Welfare\Domain\HostelPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/plans/phase-10.md §4 (W2). Per-hostel (optionally per-room)
 * occupancy: rooms, active beds, occupied beds, availability and the
 * occupancy percentage the mockup's KPI cards and rail show.
 *
 * Pure READ door - occupancy is DERIVED from active allocation rows,
 * never stored, so this can never drift from the allocation truth. Only
 * ACTIVE beds count as stock: a deactivated (broken) bed neither offers
 * space nor - by AllocateBed's guard - holds anyone.
 */
final class OccupancyReport
{
    /**
     * @return list<array{
     *     hostel_id: int,
     *     code: string,
     *     name: string,
     *     gender: string,
     *     is_active: bool,
     *     rooms: int,
     *     beds: int,
     *     occupied: int,
     *     available: int,
     *     occupancy_pct: float,
     *     rooms_detail: list<array{room_id: int, room: string, capacity: int, beds: int, occupied: int, available: int}>|null,
     * }>
     */
    public function handle(?int $hostelId = null, bool $withRooms = false): array
    {
        Gate::authorize(HostelPermission::VIEW);

        $rows = DB::table('hostels as h')
            ->when($hostelId !== null, fn ($q) => $q->where('h.id', $hostelId))
            ->orderBy('h.code')
            ->select(['h.id', 'h.code', 'h.name', 'h.gender', 'h.is_active'])
            ->selectSub(
                DB::table('hostel_rooms')->whereColumn('hostel_id', 'h.id')->selectRaw('COUNT(*)'),
                'rooms'
            )
            ->selectSub(
                DB::table('hostel_beds as b')
                    ->join('hostel_rooms as r', 'r.id', '=', 'b.room_id')
                    ->whereColumn('r.hostel_id', 'h.id')
                    ->where('b.is_active', true)
                    ->selectRaw('COUNT(*)'),
                'beds'
            )
            ->selectSub(
                DB::table('hostel_allocations as a')
                    ->join('hostel_beds as b', 'b.id', '=', 'a.bed_id')
                    ->join('hostel_rooms as r', 'r.id', '=', 'b.room_id')
                    ->whereColumn('r.hostel_id', 'h.id')
                    ->where('a.status', 'active')
                    ->selectRaw('COUNT(*)'),
                'occupied'
            )
            ->get();

        $report = [];

        foreach ($rows as $row) {
            /** @var object{id: int|string, code: string, name: string, gender: string, is_active: int|bool, rooms: int|string, beds: int|string, occupied: int|string} $row */
            $beds = (int) $row->beds;
            $occupied = (int) $row->occupied;

            $report[] = [
                'hostel_id' => (int) $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'gender' => $row->gender,
                'is_active' => (bool) $row->is_active,
                'rooms' => (int) $row->rooms,
                'beds' => $beds,
                'occupied' => $occupied,
                'available' => max(0, $beds - $occupied),
                'occupancy_pct' => $beds > 0 ? round($occupied * 100 / $beds, 2) : 0.0,
                'rooms_detail' => $withRooms ? $this->roomsDetail((int) $row->id) : null,
            ];
        }

        return $report;
    }

    /**
     * @return list<array{room_id: int, room: string, capacity: int, beds: int, occupied: int, available: int}>
     */
    private function roomsDetail(int $hostelId): array
    {
        $rows = DB::table('hostel_rooms as r')
            ->where('r.hostel_id', $hostelId)
            ->orderBy('r.name')
            ->select(['r.id', 'r.name', 'r.capacity'])
            ->selectSub(
                DB::table('hostel_beds')
                    ->whereColumn('room_id', 'r.id')
                    ->where('is_active', true)
                    ->selectRaw('COUNT(*)'),
                'beds'
            )
            ->selectSub(
                DB::table('hostel_allocations as a')
                    ->join('hostel_beds as b', 'b.id', '=', 'a.bed_id')
                    ->whereColumn('b.room_id', 'r.id')
                    ->where('a.status', 'active')
                    ->selectRaw('COUNT(*)'),
                'occupied'
            )
            ->get();

        $detail = [];

        foreach ($rows as $row) {
            /** @var object{id: int|string, name: string, capacity: int|string, beds: int|string, occupied: int|string} $row */
            $beds = (int) $row->beds;
            $occupied = (int) $row->occupied;

            $detail[] = [
                'room_id' => (int) $row->id,
                'room' => $row->name,
                'capacity' => (int) $row->capacity,
                'beds' => $beds,
                'occupied' => $occupied,
                'available' => max(0, $beds - $occupied),
            ];
        }

        return $detail;
    }
}
