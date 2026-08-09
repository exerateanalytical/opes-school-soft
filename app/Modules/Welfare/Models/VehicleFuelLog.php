<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A fuel purchase for a vehicle. `cost_amount` (XAF integer) is
 * informational: the money reaches the ledger through the Phase 5 supplier
 * invoice flow, never from this table.
 *
 * @property int $id
 * @property int $vehicle_id
 * @property Carbon $date
 * @property string $litres
 * @property int $cost_amount
 * @property int|null $odometer
 * @property int|null $recorded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class VehicleFuelLog extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'vehicle_id', 'date', 'litres', 'cost_amount', 'odometer', 'recorded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vehicle_id' => 'integer',
            'date' => 'date',
            'litres' => 'decimal:2',
            'cost_amount' => 'integer',
            'odometer' => 'integer',
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
}
