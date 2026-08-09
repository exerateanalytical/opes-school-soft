<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One completed run: which vehicle, which route, who drove, and the
 * odometer envelope. Operational record only - never posts.
 *
 * @property int $id
 * @property int $vehicle_id
 * @property int|null $route_id
 * @property int|null $driver_id
 * @property Carbon $date
 * @property int $odometer_start
 * @property int $odometer_end
 * @property string|null $notes
 * @property int|null $recorded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class VehicleTripLog extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'vehicle_id', 'route_id', 'driver_id', 'date',
        'odometer_start', 'odometer_end', 'notes', 'recorded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vehicle_id' => 'integer',
            'route_id' => 'integer',
            'driver_id' => 'integer',
            'date' => 'date',
            'odometer_start' => 'integer',
            'odometer_end' => 'integer',
            'recorded_by' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    /**
     * @return BelongsTo<TransportRoute, $this>
     */
    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportRoute::class, 'route_id');
    }

    /**
     * @return BelongsTo<VehicleDriver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(VehicleDriver::class, 'driver_id');
    }
}
