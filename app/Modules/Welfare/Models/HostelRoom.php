<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A door inside a hostel. `capacity` is the physical ceiling SaveBeds
 * enforces on the bed count; occupancy is derived from active allocations
 * on the beds, never stored.
 *
 * @property int $id
 * @property int $hostel_id
 * @property string $name
 * @property int $capacity
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class HostelRoom extends Model
{
    /** @var list<string> */
    protected $fillable = ['hostel_id', 'name', 'capacity'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hostel_id' => 'integer',
            'capacity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Hostel, $this>
     */
    public function hostel(): BelongsTo
    {
        return $this->belongsTo(Hostel::class, 'hostel_id');
    }

    /**
     * @return HasMany<HostelBed, $this>
     */
    public function beds(): HasMany
    {
        return $this->hasMany(HostelBed::class, 'room_id')->orderBy('label');
    }
}
