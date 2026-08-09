<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Models;

use App\Modules\Welfare\Domain\VehicleMaintenanceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Maintenance history for a vehicle. `cost_amount` is informational (XAF
 * integer): the actual payable posts through the Phase 5 supplier invoice.
 * `supplier_id` is a plain FK column; the Supplier model belongs to
 * Procurement and is never imported here (ModuleBoundaryTest).
 *
 * @property int $id
 * @property int $vehicle_id
 * @property Carbon $date
 * @property VehicleMaintenanceType $type
 * @property string $description
 * @property int|null $cost_amount
 * @property int|null $supplier_id
 * @property int|null $recorded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class VehicleMaintenanceLog extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'vehicle_id', 'date', 'type', 'description',
        'cost_amount', 'supplier_id', 'recorded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vehicle_id' => 'integer',
            'date' => 'date',
            'type' => VehicleMaintenanceType::class,
            'cost_amount' => 'integer',
            'supplier_id' => 'integer',
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
