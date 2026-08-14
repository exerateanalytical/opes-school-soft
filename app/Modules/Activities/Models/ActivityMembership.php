<?php

declare(strict_types=1);

namespace App\Modules\Activities\Models;

use App\Modules\Activities\Domain\ConsentStatus;
use App\Modules\Activities\Domain\MembershipRole;
use App\Modules\Activities\Domain\MembershipStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A student's enrolment in one activity, with a validity window and a role.
 * Keyed on student_id (not enrollment_id): a club membership is a fact
 * about the student, and EnrolStudent verifies the student row exists via
 * DB::table - never through the Students module's Models.
 *
 * The consent_* columns are the gap-analysis row-15 tie-in, populated only
 * when the parent activity is an excursion: EnrolStudent stamps `pending`,
 * RecordConsent stores the deciding guardian, the decision, who keyed it
 * and when. On every other activity type they stay NULL.
 *
 * @property int $id
 * @property int $activity_id
 * @property int $student_id
 * @property MembershipRole $role
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property MembershipStatus $status
 * @property ConsentStatus|null $consent_status
 * @property int|null $consent_guardian_id
 * @property int|null $consent_recorded_by
 * @property Carbon|null $consent_recorded_at
 * @property string|null $consent_note
 * @property int|null $enrolled_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ActivityMembership extends Model
{
    protected $table = 'activity_memberships';

    /** @var list<string> */
    protected $fillable = [
        'activity_id', 'student_id', 'role', 'starts_on', 'ends_on', 'status',
        'consent_status', 'consent_guardian_id', 'consent_recorded_by',
        'consent_recorded_at', 'consent_note', 'enrolled_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => MembershipRole::class,
            'status' => MembershipStatus::class,
            'consent_status' => ConsentStatus::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'consent_recorded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Activity, $this>
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'activity_id');
    }
}
