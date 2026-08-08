<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One candidate in one chair — docs/specs/01-assessment.md 16.1's `ExamSeat`.
 *
 * As with the invigilator row, the capacity invariant is not enforced here:
 * counting occupied chairs is a query, and a model event holds no lock. See
 * `Assessment\Actions\GenerateSeating`.
 *
 * No `enrollment()` relation: `Enrollment` is a Students model and the module
 * boundary test forbids naming it here. Candidate names reach the seating
 * plan through the query builder.
 *
 * @property int $id
 * @property int $exam_id
 * @property int $enrollment_id
 * @property int $room_id
 * @property string $seat_label
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ExamSeating extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'exam_id',
        'enrollment_id',
        'room_id',
        'seat_label',
    ];

    /**
     * @return BelongsTo<Exam, $this>
     */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }
}
