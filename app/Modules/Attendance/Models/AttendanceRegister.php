<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Models;

use App\Modules\Attendance\Domain\AttendanceMode;
use App\Modules\Attendance\Domain\RegisterSession;
use App\Modules\Attendance\Domain\RegisterStatus;
use Database\Factories\AttendanceRegisterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The first-class header (07-students §9.3) whose existence IS the
 * attendance denominator — the C5 fix. `expected_count` is frozen at open;
 * the exception rows and the counts are written in one transaction at
 * submit; the §9.6 rate is derived from headers + records, never from row
 * absence.
 *
 * No relations reach outside this module: class group, year, subject, slot
 * and taker are other modules' rows, read via DB::table where needed
 * (tests/Architecture/ModuleBoundaryTest.php — "No exceptions").
 *
 * @property int $id
 * @property int $class_group_id
 * @property int $academic_year_id
 * @property Carbon $date
 * @property RegisterSession $session
 * @property int $timetable_slot_id 0 = SLOT_NONE sentinel (daily mode)
 * @property int|null $subject_id
 * @property AttendanceMode $mode
 * @property int $expected_count
 * @property int $present_count
 * @property int $absent_count
 * @property int $late_count
 * @property int $excused_count
 * @property RegisterStatus $status
 * @property int $taken_by
 * @property Carbon $taken_at
 * @property Carbon|null $submitted_at
 * @property int|null $amended_by
 * @property Carbon|null $amended_at
 * @property string|null $amendment_reason
 * @property int|null $lesson_duration_minutes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AttendanceRecord> $records
 */
final class AttendanceRegister extends Model
{
    /** @use HasFactory<AttendanceRegisterFactory> */
    use HasFactory;

    /**
     * The timetable_slot_id sentinel meaning "daily mode, no slot".
     * Deliberately 0 and NOT NULL so the daily and per-lesson cases share
     * uq_register_daily without MySQL's NULL-duplicate semantics defeating
     * it (§9.3).
     */
    public const SLOT_NONE = 0;

    /** @var list<string> */
    protected $fillable = [
        'class_group_id',
        'academic_year_id',
        'date',
        'session',
        'timetable_slot_id',
        'subject_id',
        'mode',
        'expected_count',
        'present_count',
        'absent_count',
        'late_count',
        'excused_count',
        'status',
        'taken_by',
        'taken_at',
        'submitted_at',
        'amended_by',
        'amended_at',
        'amendment_reason',
        'lesson_duration_minutes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'class_group_id' => 'integer',
            'academic_year_id' => 'integer',
            'date' => 'date',
            'session' => RegisterSession::class,
            'timetable_slot_id' => 'integer',
            'subject_id' => 'integer',
            'mode' => AttendanceMode::class,
            'expected_count' => 'integer',
            'present_count' => 'integer',
            'absent_count' => 'integer',
            'late_count' => 'integer',
            'excused_count' => 'integer',
            'status' => RegisterStatus::class,
            'taken_by' => 'integer',
            'taken_at' => 'datetime',
            'submitted_at' => 'datetime',
            'amended_by' => 'integer',
            'amended_at' => 'datetime',
            'lesson_duration_minutes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // §9.4: "Registers themselves are never deleted once `submitted`; a
        // model observer enforces it." Deleting one would also cascade its
        // records away and silently shrink every denominator it fed.
        static::deleting(function (AttendanceRegister $register): void {
            $original = $register->getOriginal('status');
            $status = $original instanceof RegisterStatus
                ? $original
                : RegisterStatus::from((string) $original);

            if ($status !== RegisterStatus::Open) {
                throw new RuntimeException(sprintf(
                    'Register %d for %s is %s and cannot be deleted: a taken register is '
                    .'part of the attendance denominator (07-students §9.4). '
                    .'Corrections go through AmendAttendanceRegister.',
                    (int) $register->getKey(),
                    $register->date->toDateString(),
                    $status->value,
                ));
            }
        });
    }

    /**
     * @return HasMany<AttendanceRecord, $this>
     */
    public function records(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function isPerLesson(): bool
    {
        return $this->mode === AttendanceMode::PerLesson;
    }

    protected static function newFactory(): AttendanceRegisterFactory
    {
        return AttendanceRegisterFactory::new();
    }
}
