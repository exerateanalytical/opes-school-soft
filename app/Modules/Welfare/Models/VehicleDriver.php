<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A driver assigned to a vehicle for a period. `licence_no` is encrypted
 * at rest ('encrypted' cast, the StudentMedicalRecord pattern) - a driving
 * licence number is personal identity data (00-core 9.5).
 *
 * @property int $id
 * @property int $vehicle_id
 * @property string $name
 * @property string|null $licence_no
 * @property string|null $phone
 * @property int|null $user_id
 * @property Carbon $active_from
 * @property Carbon|null $active_to
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class VehicleDriver extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'vehicle_id', 'name', 'licence_no', 'phone', 'user_id',
        'active_from', 'active_to',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vehicle_id' => 'integer',
            'licence_no' => 'encrypted',
            'user_id' => 'integer',
            'active_from' => 'date',
            'active_to' => 'date',
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
