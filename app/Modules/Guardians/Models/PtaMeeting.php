<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A general meeting of the Parent-Teacher Association - the body, not an
 * individual guardian's meeting with the school (see GuardianMeeting).
 *
 * @property int $id
 * @property string $title
 * @property \Illuminate\Support\Carbon $meeting_date
 * @property string $status
 */
final class PtaMeeting extends Model
{
    protected $table = 'pta_meetings';

    /** @var list<string> */
    protected $fillable = [
        'title', 'meeting_date', 'location', 'agenda', 'minutes',
        'attendee_count', 'status', 'chaired_by_officer_id', 'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['meeting_date' => 'date', 'attendee_count' => 'integer'];
    }
}
