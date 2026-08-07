<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Models;

use App\Modules\Guardians\Domain\FollowUpStatus;
use App\Modules\Guardians\Domain\MeetingRequestedBy;
use App\Modules\Guardians\Domain\MeetingStatus;
use App\Modules\Guardians\Domain\MeetingType;
use Database\Factories\GuardianMeetingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/specs/07-students.md 7.8. Schema and model only in Phase 2 - no UI.
 *
 * As on StudentGuardian, there is no relation to Student: the module boundary
 * test forbids importing another module's Models. `student_id` is a plain
 * nullable FK column.
 *
 * @property int $id
 * @property int $guardian_id
 * @property int|null $student_id
 * @property Carbon $scheduled_at
 * @property Carbon|null $held_at
 * @property string|null $location
 * @property MeetingType $meeting_type
 * @property MeetingRequestedBy $requested_by
 * @property string|null $agenda
 * @property array<int|string, mixed>|null $attendees
 * @property string|null $minutes
 * @property string|null $decisions
 * @property string|null $follow_up_action
 * @property Carbon|null $follow_up_due_on
 * @property FollowUpStatus $follow_up_status
 * @property MeetingStatus $status
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Guardian|null $guardian
 */
final class GuardianMeeting extends Model
{
    /** @use HasFactory<GuardianMeetingFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'guardian_id',
        'student_id',
        'scheduled_at',
        'held_at',
        'location',
        'meeting_type',
        'requested_by',
        'agenda',
        'attendees',
        'minutes',
        'decisions',
        'follow_up_action',
        'follow_up_due_on',
        'follow_up_status',
        'status',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'guardian_id' => 'integer',
            'student_id' => 'integer',
            'scheduled_at' => 'datetime',
            'held_at' => 'datetime',
            'meeting_type' => MeetingType::class,
            'requested_by' => MeetingRequestedBy::class,
            'attendees' => 'array',
            'follow_up_due_on' => 'date',
            'follow_up_status' => FollowUpStatus::class,
            'status' => MeetingStatus::class,
            'created_by' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Guardian, $this>
     */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }

    protected static function newFactory(): GuardianMeetingFactory
    {
        return GuardianMeetingFactory::new();
    }
}
