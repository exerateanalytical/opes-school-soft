<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Welfare\Domain\AllocationStatus;
use App\Modules\Welfare\Domain\TransportDirection;
use App\Modules\Welfare\Domain\TransportPermission;
use App\Modules\Welfare\Models\TransportAllocation;
use App\Modules\Welfare\Models\TransportRoute;
use App\Modules\Welfare\Models\TransportStop;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/plans/phase-10.md §4 (W1). Puts a student (an ENROLLMENT - the
 * year-scoped identity, 07-students line 39) on a route at a stop.
 *
 *  - The enrollment must exist and be ACTIVE - read via DB::table, never
 *    through the Students Models (ModuleBoundaryTest).
 *  - The stop must belong to the route, and the route must be active.
 *  - Any prior active allocation is ENDED atomically in the same
 *    transaction (a student holds at most one seat); the schema's
 *    NULL-unique active_key backs the same invariant against every other
 *    code path, so a race loses with a duplicate-key error instead of a
 *    silent double seat.
 *
 * No billing here: transport fees flow through Phase 6 audience criteria
 * (`transport_status`), and a mid-year change is the operator-driven
 * supplementary-invoice flow (04-fees timing rule H).
 */
final class AllocateTransport
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(
        int $enrollmentId,
        int $routeId,
        int $stopId,
        TransportDirection $direction,
        Carbon $startsOn,
        Actor $actor,
    ): TransportAllocation {
        Gate::authorize(TransportPermission::MANAGE);

        return DB::transaction(function () use (
            $enrollmentId, $routeId, $stopId, $direction, $startsOn, $actor
        ): TransportAllocation {
            /** @var object{id: int|string, status: string}|null $enrollment */
            $enrollment = DB::table('enrollments')
                ->where('id', $enrollmentId)
                ->lockForUpdate()
                ->first(['id', 'status']);

            if ($enrollment === null) {
                throw new DomainException('The enrollment does not exist.');
            }

            if ($enrollment->status !== 'active') {
                throw new DomainException(
                    'Only an ACTIVE enrollment can be allocated transport; '
                    ."this one is '{$enrollment->status}'."
                );
            }

            /** @var TransportRoute $route */
            $route = TransportRoute::query()->findOrFail($routeId);

            if (! $route->is_active) {
                throw new DomainException("Route {$route->code} is inactive and accepts no new riders.");
            }

            /** @var TransportStop $stop */
            $stop = TransportStop::query()->findOrFail($stopId);

            if ($stop->route_id !== (int) $route->getKey()) {
                throw new DomainException(
                    "Stop '{$stop->name}' does not belong to route {$route->code}."
                );
            }

            $prior = TransportAllocation::query()
                ->where('enrollment_id', $enrollmentId)
                ->where('status', AllocationStatus::Active->value)
                ->lockForUpdate()
                ->first();

            if ($prior !== null) {
                // End the day before the new seat starts, clamped so a
                // same-day swap never violates CHECK(ends_on >= starts_on).
                $endsOn = $startsOn->copy()->subDay();

                if ($endsOn->lessThan($prior->starts_on)) {
                    $endsOn = $prior->starts_on->copy();
                }

                $prior->fill([
                    'status' => AllocationStatus::Ended,
                    'ends_on' => $endsOn,
                ])->save();
            }

            $allocation = TransportAllocation::query()->create([
                'enrollment_id' => $enrollmentId,
                'route_id' => (int) $route->getKey(),
                'stop_id' => (int) $stop->getKey(),
                'direction' => $direction,
                'starts_on' => $startsOn,
                'status' => AllocationStatus::Active,
                'allocated_by' => $actor->id,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Welfare',
                auditableType: TransportAllocation::class,
                auditableId: (int) $allocation->getKey(),
                before: $prior === null ? null : [
                    'ended_allocation_id' => (int) $prior->getKey(),
                ],
                after: [
                    'enrollment_id' => $enrollmentId,
                    'route' => $route->code,
                    'stop' => $stop->name,
                    'direction' => $direction->value,
                    'starts_on' => $startsOn->toDateString(),
                ],
                actor: $actor,
            );

            return $allocation->refresh();
        });
    }
}
