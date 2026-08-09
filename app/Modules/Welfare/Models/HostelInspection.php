<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Models;

use App\Modules\Welfare\Domain\InspectionRating;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A dated welfare walk-through of a hostel or one of its rooms.
 * `resolved_at` NULL = the findings are still open on the warden's list.
 *
 * @property int $id
 * @property int $hostel_id
 * @property int|null $room_id
 * @property Carbon $inspected_on
 * @property int|null $inspected_by
 * @property InspectionRating $rating
 * @property string|null $findings
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class HostelInspection extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'hostel_id', 'room_id', 'inspected_on', 'inspected_by',
        'rating', 'findings', 'resolved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hostel_id' => 'integer',
            'room_id' => 'integer',
            'inspected_on' => 'date',
            'inspected_by' => 'integer',
            'rating' => InspectionRating::class,
            'resolved_at' => 'datetime',
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
     * @return BelongsTo<HostelRoom, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(HostelRoom::class, 'room_id');
    }
}
