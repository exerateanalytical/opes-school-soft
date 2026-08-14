<?php

declare(strict_types=1);

namespace App\Modules\Activities\Models;

use App\Modules\Activities\Domain\ActivityStatus;
use App\Modules\Activities\Domain\ActivityType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A club, sports team, event or excursion (gap-analysis #1). Activities
 * models relate to each other only; student/guardian/staff data crosses
 * the boundary via DB::table inside Actions (ModuleBoundaryTest).
 *
 * The excursion extras (destination, departure_at, return_at) are NULL on
 * every other type - enforced by chk_activities_excursion_only in the
 * migration as well as by CreateActivity, so no code path can decorate a
 * chess club with a departure time.
 *
 * @property int $id
 * @property string $name
 * @property ActivityType $type
 * @property string|null $description
 * @property string|null $venue
 * @property int|null $capacity
 * @property ActivityStatus $status
 * @property string|null $destination
 * @property Carbon|null $departure_at
 * @property Carbon|null $return_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Activity extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'name', 'type', 'description', 'venue', 'capacity', 'status',
        'destination', 'departure_at', 'return_at', 'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ActivityType::class,
            'status' => ActivityStatus::class,
            'capacity' => 'integer',
            'departure_at' => 'datetime',
            'return_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ActivityMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(ActivityMembership::class, 'activity_id');
    }

    /**
     * @return HasMany<ActivitySession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(ActivitySession::class, 'activity_id')->orderBy('scheduled_on');
    }
}
