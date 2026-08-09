<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Actions;

use App\Modules\Welfare\Domain\TransportPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/plans/phase-10.md §4 (W1). The per-route rider list the escort
 * carries: every ACTIVE allocation with its stop (in sequence order), the
 * student's name/matricule and their current class group.
 *
 * Pure cross-module READ door - one DB::table join, no Students/Academics
 * Models (ModuleBoundaryTest), no pagination because a bus roster is
 * physically bounded by vehicle capacity.
 */
final class TransportRosterReport
{
    /**
     * @return list<array{
     *     allocation_id: int,
     *     route_id: int,
     *     route_code: string,
     *     route_name: string,
     *     stop_name: string,
     *     stop_sequence: int,
     *     pickup_time: string|null,
     *     direction: string,
     *     enrollment_id: int,
     *     student_name: string,
     *     matricule: string,
     *     class_group: string|null,
     * }>
     */
    public function handle(?int $routeId = null): array
    {
        Gate::authorize(TransportPermission::VIEW);

        $rows = DB::table('transport_allocations as ta')
            ->join('transport_routes as tr', 'tr.id', '=', 'ta.route_id')
            ->join('transport_stops as ts', 'ts.id', '=', 'ta.stop_id')
            ->join('enrollments as e', 'e.id', '=', 'ta.enrollment_id')
            ->join('students as s', 's.id', '=', 'e.student_id')
            // The OPEN segment (ends_on IS NULL) names the class the student
            // is in TODAY - the enrollment_segments contract (07-students 5).
            ->leftJoin('enrollment_segments as es', function ($join): void {
                $join->on('es.enrollment_id', '=', 'e.id')->whereNull('es.ends_on');
            })
            ->leftJoin('class_groups as cg', 'cg.id', '=', 'es.class_group_id')
            ->where('ta.status', 'active')
            ->when($routeId !== null, fn ($q) => $q->where('ta.route_id', $routeId))
            ->orderBy('tr.code')
            ->orderBy('ts.sequence')
            ->orderBy('s.last_name')
            ->orderBy('s.first_name')
            ->get([
                'ta.id as allocation_id',
                'ta.route_id',
                'tr.code as route_code',
                'tr.name as route_name',
                'ts.name as stop_name',
                'ts.sequence as stop_sequence',
                'ts.pickup_time',
                'ta.direction',
                'ta.enrollment_id',
                's.first_name',
                's.last_name',
                's.matricule',
                'cg.name as class_group',
            ]);

        $roster = [];

        foreach ($rows as $row) {
            /** @var object{allocation_id: int|string, route_id: int|string, route_code: string, route_name: string, stop_name: string, stop_sequence: int|string, pickup_time: string|null, direction: string, enrollment_id: int|string, first_name: string, last_name: string, matricule: string, class_group: string|null} $row */
            $roster[] = [
                'allocation_id' => (int) $row->allocation_id,
                'route_id' => (int) $row->route_id,
                'route_code' => $row->route_code,
                'route_name' => $row->route_name,
                'stop_name' => $row->stop_name,
                'stop_sequence' => (int) $row->stop_sequence,
                'pickup_time' => $row->pickup_time,
                'direction' => $row->direction,
                'enrollment_id' => (int) $row->enrollment_id,
                'student_name' => trim($row->first_name.' '.$row->last_name),
                'matricule' => $row->matricule,
                'class_group' => $row->class_group,
            ];
        }

        return $roster;
    }
}
