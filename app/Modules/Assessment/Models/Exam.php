<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Models;

use Database\Factories\ExamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A scheduled sitting — docs/specs/01-assessment.md 16.1.
 *
 * Read the migration's header for why this is not an `AssessmentPeriod`.
 *
 * ── No Domain enum for `status` and `role` ────────────────────────────────
 *
 * Everywhere else in this codebase a closed set is a backed enum in the
 * module's `Domain` namespace. `Assessment\Domain` is owned by the grading
 * pipeline workstream and is off-limits to this file, so the closed sets live
 * here as class constants with the same effect: a typo is a constant that does
 * not exist, which fails at analysis time rather than matching nothing at run
 * time. If the pipeline workstream later publishes `Domain\ExamStatus`, these
 * constants are the exact value list to seed it from.
 *
 * @property int $id
 * @property int $exam_type_id
 * @property int $assessment_period_id
 * @property int $subject_allocation_id
 * @property int $class_group_id
 * @property Carbon $scheduled_on
 * @property string $starts_at
 * @property int $duration_minutes
 * @property int|null $room_id
 * @property int|null $mark_scheme_id
 * @property string $max_score
 * @property string $status
 * @property int $created_by
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Exam extends Model
{
    /** @use HasFactory<ExamFactory> */
    use HasFactory;

    public const string STATUS_PLANNED = 'planned';

    public const string STATUS_SCHEDULED = 'scheduled';

    public const string STATUS_IN_PROGRESS = 'in_progress';

    public const string STATUS_MARKED = 'marked';

    public const string STATUS_CANCELLED = 'cancelled';

    /**
     * A cancelled exam still exists (its paper was printed, its candidates
     * were told), but it occupies neither an invigilator's morning nor a
     * chair. Both invariant checks exclude these, and both name this constant
     * rather than repeating the literal.
     *
     * @var list<string>
     */
    public const array LIVE_STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_SCHEDULED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_MARKED,
    ];

    /** @var list<string> */
    protected $fillable = [
        'exam_type_id',
        'assessment_period_id',
        'subject_allocation_id',
        'class_group_id',
        'scheduled_on',
        'starts_at',
        'duration_minutes',
        'room_id',
        'mark_scheme_id',
        'max_score',
        'status',
        'created_by',
        'version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_on' => 'date',
            'duration_minutes' => 'integer',
            'version' => 'integer',
            // `starts_at` is deliberately NOT cast to a datetime. It is a
            // TIME column; casting it would graft today's date onto it and
            // make "08:00" compare unequal to itself tomorrow.
        ];
    }

    /**
     * @return HasMany<ExamInvigilator, $this>
     */
    public function invigilators(): HasMany
    {
        return $this->hasMany(ExamInvigilator::class);
    }

    /**
     * @return HasMany<ExamSeating, $this>
     */
    public function seatings(): HasMany
    {
        return $this->hasMany(ExamSeating::class);
    }

    /**
     * The exclusive end of the half-open sitting interval, as `HH:MM:SS`.
     *
     * Computed in PHP for display only. The overlap invariant computes the
     * same value IN SQL (`ADDTIME`) so the comparison happens inside the same
     * locked read that decides it — a PHP-side comparison would be a
     * read-then-write race.
     */
    public function endsAt(): string
    {
        // Plain clock arithmetic rather than a date library, because a TIME is
        // not a moment: grafting today's date onto "08:00" so that Carbon can
        // add two hours to it introduces a timezone and a DST rule that a
        // column with no date has no business acquiring.
        //
        // Hours may legitimately exceed 24 (a 23:30 paper of 90 minutes ends
        // at 25:00). MySQL's TIME type and ADDTIME - which computes this same
        // value inside the invariant queries - both behave that way, so the
        // PHP side matches rather than wrapping to 01:00 and reporting an
        // overlap that runs backwards.
        $parts = array_pad(array_map('intval', explode(':', $this->starts_at)), 3, 0);

        $seconds = $parts[0] * 3600 + $parts[1] * 60 + $parts[2]
            + $this->duration_minutes * 60;

        return sprintf(
            '%02d:%02d:%02d',
            intdiv($seconds, 3600),
            intdiv($seconds % 3600, 60),
            $seconds % 60,
        );
    }

    protected static function newFactory(): ExamFactory
    {
        return ExamFactory::new();
    }
}
