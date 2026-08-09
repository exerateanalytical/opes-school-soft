<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Models;

use Database\Factories\AttendanceSummaryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The persisted per-period rollup (07-students §9.8), rebuilt by
 * RebuildAttendanceSummary and frozen into the report-card snapshot at
 * publication. Never archived.
 *
 * The §9.6 rate lives HERE as a method so it is stated once: NULL when the
 * effective denominator is zero — rendered "—", never 0% (C5).
 *
 * @property int $id
 * @property int $enrollment_id
 * @property int $assessment_period_id
 * @property int $sessions_expected
 * @property int $sessions_present
 * @property int $sessions_absent
 * @property int $sessions_excused
 * @property int $sessions_late
 * @property int $sessions_suspended
 * @property string $hours_absent_justified
 * @property string $hours_absent_unjustified
 * @property int $retards
 * @property Carbon $computed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class AttendanceSummary extends Model
{
    /** @use HasFactory<AttendanceSummaryFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'enrollment_id',
        'assessment_period_id',
        'sessions_expected',
        'sessions_present',
        'sessions_absent',
        'sessions_excused',
        'sessions_late',
        'sessions_suspended',
        'hours_absent_justified',
        'hours_absent_unjustified',
        'retards',
        'computed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enrollment_id' => 'integer',
            'assessment_period_id' => 'integer',
            'sessions_expected' => 'integer',
            'sessions_present' => 'integer',
            'sessions_absent' => 'integer',
            'sessions_excused' => 'integer',
            'sessions_late' => 'integer',
            'sessions_suspended' => 'integer',
            'retards' => 'integer',
            'computed_at' => 'datetime',
        ];
    }

    /**
     * §9.6, one formula, stated once. `sessions_present` is stored per that
     * section's definition — expected − absent − excused − suspended — so it
     * already contains the late-but-present sessions (`late` is PRESENT and
     * is never subtracted). The denominator excludes suspended sessions; a
     * zero denominator yields NULL — "not recorded", never 0% (C5).
     */
    public function attendanceRate(): ?float
    {
        $denominator = $this->sessions_expected - $this->sessions_suspended;

        if ($denominator <= 0) {
            return null;
        }

        return round($this->sessions_present / $denominator, 4);
    }

    protected static function newFactory(): AttendanceSummaryFactory
    {
        return AttendanceSummaryFactory::new();
    }
}
