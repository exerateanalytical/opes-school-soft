<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Models;

use App\Modules\Welfare\Domain\VehicleStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docs/plans/phase-10.md §3 row 2. `asset_id` is a bare nullable bigint
 * pointing at the Phase 9 asset register - no FK and no relation here
 * (cross-module Models are off-limits; the link is resolved via DB::table
 * when a screen needs it).
 *
 * @property int $id
 * @property string $registration_no
 * @property string|null $make
 * @property string|null $model
 * @property int $capacity
 * @property int|null $asset_id
 * @property VehicleStatus $status
 * @property Carbon|null $insurance_expires_on
 * @property Carbon|null $inspection_expires_on
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Vehicle extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'registration_no', 'make', 'model', 'capacity', 'asset_id',
        'status', 'insurance_expires_on', 'inspection_expires_on',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'asset_id' => 'integer',
            'status' => VehicleStatus::class,
            'insurance_expires_on' => 'date',
            'inspection_expires_on' => 'date',
        ];
    }

    /**
     * @return HasMany<VehicleDriver, $this>
     */
    public function drivers(): HasMany
    {
        return $this->hasMany(VehicleDriver::class, 'vehicle_id');
    }

    /**
     * @return HasMany<VehicleTripLog, $this>
     */
    public function tripLogs(): HasMany
    {
        return $this->hasMany(VehicleTripLog::class, 'vehicle_id');
    }

    /**
     * @return HasMany<VehicleMaintenanceLog, $this>
     */
    public function maintenanceLogs(): HasMany
    {
        return $this->hasMany(VehicleMaintenanceLog::class, 'vehicle_id');
    }

    /**
     * @return HasMany<VehicleFuelLog, $this>
     */
    public function fuelLogs(): HasMany
    {
        return $this->hasMany(VehicleFuelLog::class, 'vehicle_id');
    }
}
