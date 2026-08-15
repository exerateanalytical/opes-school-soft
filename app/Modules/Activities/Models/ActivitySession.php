<?php

declare(strict_types=1);

namespace App\Modules\Activities\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One scheduled occurrence of an activity - a training, a rehearsal, a
 * meeting, an excursion day - with a venue and an optional staff
 * supervisor (FK to staff_members at the schema layer; the row is read via
 * DB::table in ScheduleSession, never through the HR module's Models).
 *
 * @property int $id
 * @property int $activity_id
 * @property Carbon $scheduled_on
 * @property string|null $starts_at
 * @property string|null $ends_at
 * @property string|null $venue
 * @property int|null $supervisor_id
 * @property string|null $notes
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ActivitySession extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'activity_id', 'scheduled_on', 'starts_at', 'ends_at',
        'venue', 'supervisor_id', 'notes', 'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Activity, $this>
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'activity_id');
    }

    /**
     * @return HasMany<ActivityAttendance, $this>
     */
    public function attendance(): HasMany
    {
        return $this->hasMany(ActivityAttendance::class, 'session_id');
    }
}
