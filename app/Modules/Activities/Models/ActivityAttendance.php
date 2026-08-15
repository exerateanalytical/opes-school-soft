<?php

declare(strict_types=1);

namespace App\Modules\Activities\Models;

use App\Modules\Activities\Domain\SessionAttendanceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One member's mark for one session. UNIQUE(session_id, membership_id)
 * makes re-recording an update, never a duplicate row - the register for a
 * session is at most one mark per member, by schema.
 *
 * @property int $id
 * @property int $session_id
 * @property int $membership_id
 * @property SessionAttendanceStatus $status
 * @property int|null $recorded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ActivityAttendance extends Model
{
    protected $table = 'activity_attendance';

    /** @var list<string> */
    protected $fillable = ['session_id', 'membership_id', 'status', 'recorded_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SessionAttendanceStatus::class,
        ];
    }

    /**
     * @return BelongsTo<ActivitySession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ActivitySession::class, 'session_id');
    }

    /**
     * @return BelongsTo<ActivityMembership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(ActivityMembership::class, 'membership_id');
    }
}
