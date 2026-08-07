<?php

declare(strict_types=1);

namespace App\Modules\Students\Models;

use App\Modules\Students\Domain\StudentActivityEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * docs/specs/07-students.md 8.3 - "what happened to this child", as opposed to
 * the global AuditLog's "who changed this row". Readable by staff without
 * audit-log permission, which is why it is a separate table and not a view
 * over audit_logs.
 *
 * @property int $id
 * @property int $student_id
 * @property int|null $enrollment_id
 * @property StudentActivityEvent $event
 * @property string $summary
 * @property string|null $related_type
 * @property int|null $related_id
 * @property int|null $actor_id
 * @property string $actor_name_at_time
 * @property Carbon $occurred_at
 */
final class StudentActivityLog extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'student_id',
        'enrollment_id',
        'event',
        'summary',
        'related_type',
        'related_id',
        'actor_id',
        'actor_name_at_time',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'student_id' => 'integer',
            'enrollment_id' => 'integer',
            'event' => StudentActivityEvent::class,
            'related_id' => 'integer',
            'actor_id' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    /** Append-only (8.3), enforced the same way as the transition log. */
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException(
                'StudentActivityLog is append-only (07-students 8.3); add a correcting entry instead.'
            );
        });

        static::deleting(function (): void {
            throw new RuntimeException(
                'StudentActivityLog is append-only (07-students 8.3); rows are never deleted.'
            );
        });
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * The viewer's only query shape - newest first on the
     * (student_id, occurred_at) index, and always paginated by the caller
     * (00-core 6.2 rule 8: never loaded unbounded).
     *
     * @param  Builder<StudentActivityLog>  $query
     * @return Builder<StudentActivityLog>
     */
    public function scopeForStudent(Builder $query, int $studentId): Builder
    {
        return $query
            ->where('student_id', '=', $studentId)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');
    }
}
