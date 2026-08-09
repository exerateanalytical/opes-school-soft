<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Models;

use App\Modules\Attendance\Domain\AttendanceStatus;
use App\Modules\Attendance\Domain\JustificationType;
use Database\Factories\AttendanceRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One EXCEPTION row (07-students §9.4): `present` is never stored — the
 * header's counts are the authority. Keys on `enrollment_id`, never
 * `student_id` (C3). The `is_justified` flag is orthogonal to the status
 * (§9.7): an `absent` that a guardian later justifies STAYS `absent`.
 *
 * @property int $id
 * @property int $attendance_register_id
 * @property int $enrollment_id
 * @property AttendanceStatus $status
 * @property bool $is_justified
 * @property JustificationType|null $justification_type
 * @property int|null $justification_document_id
 * @property int|null $justified_by
 * @property Carbon|null $justified_at
 * @property int|null $minutes_late
 * @property string|null $remark
 * @property int $recorded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AttendanceRegister $register
 */
final class AttendanceRecord extends Model
{
    /** @use HasFactory<AttendanceRecordFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'attendance_register_id',
        'enrollment_id',
        'status',
        'is_justified',
        'justification_type',
        'justification_document_id',
        'justified_by',
        'justified_at',
        'minutes_late',
        'remark',
        'recorded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attendance_register_id' => 'integer',
            'enrollment_id' => 'integer',
            'status' => AttendanceStatus::class,
            'is_justified' => 'boolean',
            'justification_type' => JustificationType::class,
            'justification_document_id' => 'integer',
            'justified_by' => 'integer',
            'justified_at' => 'datetime',
            'minutes_late' => 'integer',
            'recorded_by' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AttendanceRegister, $this>
     */
    public function register(): BelongsTo
    {
        return $this->belongsTo(AttendanceRegister::class, 'attendance_register_id');
    }

    protected static function newFactory(): AttendanceRecordFactory
    {
        return AttendanceRecordFactory::new();
    }
}
