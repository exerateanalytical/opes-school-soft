<?php

declare(strict_types=1);

namespace App\Modules\Activities\Actions;

use App\Modules\Activities\Domain\ActivityPermission;
use App\Modules\Activities\Domain\ActivityStatus;
use App\Modules\Activities\Domain\ActivityType;
use App\Modules\Activities\Models\Activity;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Creates a club, sports team, event or excursion (gap-analysis #1). The
 * only writer of `activities` rows.
 *
 * An excursion MUST carry its destination and departure/return window
 * (return not before departure), and no other type may carry any of them -
 * the same invariant chk_activities_excursion_only enforces at the schema
 * layer, validated here first so the operator gets a field error instead
 * of a QueryException.
 */
final class CreateActivity
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, Actor $actor): Activity
    {
        Gate::authorize(ActivityPermission::MANAGE);

        if (trim((string) ($data['name'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'name' => 'An activity requires a name.',
            ]);
        }

        $type = ActivityType::tryFrom((string) ($data['type'] ?? ''));

        if ($type === null) {
            throw ValidationException::withMessages([
                'type' => 'The activity type must be club, sport, event or excursion.',
            ]);
        }

        $capacity = $data['capacity'] ?? null;

        if ($capacity !== null && (int) $capacity < 1) {
            throw ValidationException::withMessages([
                'capacity' => 'Capacity, when set, must be at least 1.',
            ]);
        }

        [$destination, $departureAt, $returnAt] = $this->excursionFields($type, $data);

        $activity = DB::transaction(function () use ($data, $type, $capacity, $destination, $departureAt, $returnAt, $actor): Activity {
            $activity = Activity::query()->create([
                'name' => trim((string) $data['name']),
                'type' => $type,
                'description' => $this->trimmedOrNull($data['description'] ?? null),
                'venue' => $this->trimmedOrNull($data['venue'] ?? null),
                'capacity' => $capacity === null ? null : (int) $capacity,
                'status' => ActivityStatus::Active,
                'destination' => $destination,
                'departure_at' => $departureAt,
                'return_at' => $returnAt,
                'created_by' => $actor->id,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Activities',
                auditableType: Activity::class,
                auditableId: (int) $activity->getKey(),
                after: [
                    'name' => $activity->name,
                    'type' => $type->value,
                    'capacity' => $activity->capacity,
                    'destination' => $destination,
                    'departure_at' => $departureAt?->toDateTimeString(),
                    'return_at' => $returnAt?->toDateTimeString(),
                ],
                actor: $actor,
            );

            return $activity;
        });

        return $activity->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{string|null, Carbon|null, Carbon|null}
     */
    private function excursionFields(ActivityType $type, array $data): array
    {
        $destination = $this->trimmedOrNull($data['destination'] ?? null);
        $departureRaw = $this->trimmedOrNull($data['departure_at'] ?? null);
        $returnRaw = $this->trimmedOrNull($data['return_at'] ?? null);

        if (! $type->isExcursion()) {
            if ($destination !== null || $departureRaw !== null || $returnRaw !== null) {
                throw ValidationException::withMessages([
                    'destination' => 'Destination and departure/return apply to excursions only.',
                ]);
            }

            return [null, null, null];
        }

        if ($destination === null) {
            throw ValidationException::withMessages([
                'destination' => 'An excursion requires a destination.',
            ]);
        }

        if ($departureRaw === null || $returnRaw === null) {
            throw ValidationException::withMessages([
                'departure_at' => 'An excursion requires a departure and a return date/time.',
            ]);
        }

        $departureAt = Carbon::parse($departureRaw);
        $returnAt = Carbon::parse($returnRaw);

        if ($returnAt->lessThan($departureAt)) {
            throw ValidationException::withMessages([
                'return_at' => 'The return cannot be before the departure.',
            ]);
        }

        return [$destination, $departureAt, $returnAt];
    }

    private function trimmedOrNull(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }
}
