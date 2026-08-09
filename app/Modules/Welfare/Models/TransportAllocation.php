<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Models;

use App\Modules\Welfare\Domain\AllocationStatus;
use App\Modules\Welfare\Domain\TransportDirection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A student's seat on a route, keyed on enrollment_id (07-students line
 * 39). At most ONE active row per enrollment - schema-enforced by the
 * NULL-unique `active_key` generated column, so the invariant survives any
 * code path. `active_key` is intentionally NOT fillable: MySQL computes it.
 *
 * @property int $id
 * @property int $enrollment_id
 * @property int $route_id
 * @property int $stop_id
 * @property TransportDirection $direction
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property AllocationStatus $status
 * @property int|null $allocated_by
 * @property int|null $active_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class TransportAllocation extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'enrollment_id', 'route_id', 'stop_id', 'direction',
        'starts_on', 'ends_on', 'status', 'allocated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enrollment_id' => 'integer',
            'route_id' => 'integer',
            'stop_id' => 'integer',
            'direction' => TransportDirection::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'status' => AllocationStatus::class,
            'allocated_by' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<TransportRoute, $this>
     */
    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportRoute::class, 'route_id');
    }

    /**
     * @return BelongsTo<TransportStop, $this>
     */
    public function stop(): BelongsTo
    {
        return $this->belongsTo(TransportStop::class, 'stop_id');
    }

    /**
     * @param  Builder<TransportAllocation>  $query
     * @return Builder<TransportAllocation>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '=', AllocationStatus::Active->value);
    }
}
