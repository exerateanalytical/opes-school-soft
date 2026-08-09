<?php

declare(strict_types=1);

namespace App\Modules\Academics\Models;

use App\Modules\Academics\Domain\AttendanceMode;
use Database\Factories\ClassGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $class_level_id
 * @property int|null $stream_id
 * @property int $academic_year_id
 * @property string $name
 * @property int|null $class_teacher_staff_id
 * @property int|null $room_id
 * @property int $capacity
 * @property string $status
 * @property AttendanceMode $attendance_mode
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ClassGroup extends Model
{
    /** @use HasFactory<ClassGroupFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'class_level_id',
        'stream_id',
        'academic_year_id',
        'name',
        'class_teacher_staff_id',
        'room_id',
        'capacity',
        'status',
        'attendance_mode',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'class_level_id' => 'integer',
            'stream_id' => 'integer',
            'academic_year_id' => 'integer',
            'class_teacher_staff_id' => 'integer',
            'room_id' => 'integer',
            'capacity' => 'integer',
            'attendance_mode' => AttendanceMode::class,
        ];
    }

    /**
     * @return BelongsTo<ClassLevel, $this>
     */
    public function classLevel(): BelongsTo
    {
        return $this->belongsTo(ClassLevel::class);
    }

    /**
     * @return BelongsTo<Stream, $this>
     */
    public function stream(): BelongsTo
    {
        return $this->belongsTo(Stream::class);
    }

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * The model lives in a module, not App\Models, so Laravel's factory-name
     * guesser cannot find it. Point at the factory explicitly.
     */
    protected static function newFactory(): ClassGroupFactory
    {
        return ClassGroupFactory::new();
    }
}
