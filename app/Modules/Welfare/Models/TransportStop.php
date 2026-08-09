<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An ordered waypoint on a transport route. UNIQUE(route_id, sequence) at
 * the schema keeps the ordering well-defined.
 *
 * @property int $id
 * @property int $route_id
 * @property string $name
 * @property int $sequence
 * @property string|null $pickup_time
 * @property string|null $dropoff_time
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class TransportStop extends Model
{
    /** @var list<string> */
    protected $fillable = ['route_id', 'name', 'sequence', 'pickup_time', 'dropoff_time'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'route_id' => 'integer',
            'sequence' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<TransportRoute, $this>
     */
    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportRoute::class, 'route_id');
    }
}
