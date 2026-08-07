<?php

declare(strict_types=1);

namespace App\Modules\Students\Models;

use App\Modules\Students\Domain\StudentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * docs/specs/07-students.md 3.3 - append-only.
 *
 * @property int $id
 * @property int $student_id
 * @property StudentStatus|null $from_status
 * @property StudentStatus $to_status
 * @property Carbon $effective_on
 * @property string|null $reason_code
 * @property string|null $reason_text
 * @property int|null $actor_id
 * @property string $actor_name_at_time
 * @property Carbon|null $created_at
 */
final class StudentStatusTransition extends Model
{
    /**
     * The table has created_at and no updated_at: an append-only log with a
     * mutation timestamp invites the mutation it forbids.
     */
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'student_id',
        'from_status',
        'to_status',
        'effective_on',
        'reason_code',
        'reason_text',
        'actor_id',
        'actor_name_at_time',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'student_id' => 'integer',
            'from_status' => StudentStatus::class,
            'to_status' => StudentStatus::class,
            'effective_on' => 'date',
            'actor_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Append-only, enforced rather than asserted. 3.3 says history is never
     * deleted; without this, "never" means "until someone writes
     * ->update()".
     */
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException(
                'StudentStatusTransition is append-only (07-students 3.3); correct it with a new row.'
            );
        });

        static::deleting(function (): void {
            throw new RuntimeException(
                'StudentStatusTransition is append-only (07-students 3.3); rows are never deleted.'
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
}
