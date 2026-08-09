<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Welfare\Domain\TransportPermission;
use App\Modules\Welfare\Models\TransportRoute;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/plans/phase-10.md §4 (W1). Creates or updates a transport route,
 * optionally replacing its stop list in the same transaction.
 *
 * Stops are passed as an ordered array and re-sequenced 1..n here, so the
 * UNIQUE(route_id, sequence) key can never be violated by a reorder: the
 * old stop set is deleted and the new one inserted atomically. Deleting is
 * refused while any stop still carries an ACTIVE allocation - history rows
 * (ended allocations) block nothing, but restrictOnDelete means a stop
 * referenced by ANY allocation cannot vanish, so edits to a route with
 * riders keep existing stops and only retime/rename them.
 */
final class CreateRoute
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  array<string, mixed>  $data  route fields; optional 'stops' =>
     *                                      list<array{name: string, pickup_time?: string|null, dropoff_time?: string|null}>
     */
    public function handle(?int $routeId, array $data, Actor $actor): TransportRoute
    {
        Gate::authorize(TransportPermission::MANAGE);

        /** @var list<array<string, mixed>>|null $stops */
        $stops = $data['stops'] ?? null;
        unset($data['stops']);

        if ($stops !== null) {
            $this->validateStops($stops);
        }

        return DB::transaction(function () use ($routeId, $data, $stops, $actor): TransportRoute {
            $existing = null;

            if ($routeId !== null) {
                /** @var TransportRoute $existing */
                $existing = TransportRoute::query()->lockForUpdate()->findOrFail($routeId);
            }

            $this->validateRoute($data, $existing);

            if ($existing !== null) {
                $existing->fill($data)->save();
                $route = $existing;
                $auditAction = AuditAction::Updated;
            } else {
                $route = TransportRoute::query()->create($data);
                $auditAction = AuditAction::Created;
            }

            if ($stops !== null) {
                $this->replaceStops($route, $stops);
            }

            $this->audit->handle(
                action: $auditAction,
                module: 'Welfare',
                auditableType: TransportRoute::class,
                auditableId: (int) $route->getKey(),
                after: [
                    'code' => $route->code,
                    'name' => $route->name,
                    'is_active' => $route->is_active,
                    'stops' => $stops === null ? null : count($stops),
                ],
                actor: $actor,
            );

            return $route->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateRoute(array $data, ?TransportRoute $existing): void
    {
        if ($existing === null) {
            foreach (['code', 'name'] as $field) {
                if (trim((string) ($data[$field] ?? '')) === '') {
                    throw ValidationException::withMessages([
                        $field => 'A transport route requires a code and a name.',
                    ]);
                }
            }
        }

        $code = $data['code'] ?? null;

        if ($code !== null) {
            $clash = TransportRoute::query()
                ->where('code', (string) $code)
                ->when($existing !== null, fn ($q) => $q->whereKeyNot($existing?->getKey()))
                ->exists();

            if ($clash) {
                throw ValidationException::withMessages([
                    'code' => 'A route with this code already exists.',
                ]);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $stops
     */
    private function validateStops(array $stops): void
    {
        if ($stops === []) {
            throw ValidationException::withMessages([
                'stops' => 'A route stop list may not be empty; omit it to leave stops unchanged.',
            ]);
        }

        foreach ($stops as $stop) {
            if (trim((string) ($stop['name'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    'stops' => 'Every stop requires a name.',
                ]);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $stops
     */
    private function replaceStops(TransportRoute $route, array $stops): void
    {
        $hasActiveAllocation = DB::table('transport_allocations')
            ->where('route_id', $route->getKey())
            ->where('status', 'active')
            ->exists();

        if ($hasActiveAllocation) {
            throw new DomainException(
                'The stop list cannot be replaced while students hold active '
                .'allocations on this route; end or move them first.'
            );
        }

        $referenced = DB::table('transport_allocations')
            ->whereIn('stop_id', $route->stops()->pluck('id'))
            ->exists();

        if ($referenced) {
            throw new DomainException(
                'Stops on this route are referenced by allocation history and '
                .'cannot be replaced; create a successor route instead.'
            );
        }

        $route->stops()->delete();

        foreach ($stops as $index => $stop) {
            $route->stops()->create([
                'name' => (string) $stop['name'],
                'sequence' => $index + 1,
                'pickup_time' => isset($stop['pickup_time']) ? (string) $stop['pickup_time'] : null,
                'dropoff_time' => isset($stop['dropoff_time']) ? (string) $stop['dropoff_time'] : null,
            ]);
        }
    }
}
