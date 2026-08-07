<?php

declare(strict_types=1);

namespace App\Modules\Students\Models;

use App\Modules\Students\Domain\MedicalConditionType;
use App\Modules\Students\Domain\MedicalSeverity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/specs/07-students.md 8.2. Keyed on student_id per 3.4: chronic
 * conditions persist across years.
 *
 * @property int $id
 * @property int $student_id
 * @property MedicalConditionType $condition_type
 * @property string $summary
 * @property string|null $detail
 * @property bool $is_emergency_relevant
 * @property MedicalSeverity $severity
 * @property int|null $recorded_by
 * @property Carbon $recorded_at
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class StudentMedicalRecord extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'student_id',
        'condition_type',
        'summary',
        'detail',
        'is_emergency_relevant',
        'severity',
        'recorded_by',
        'recorded_at',
        'reviewed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'student_id' => 'integer',
            'condition_type' => MedicalConditionType::class,
            // 00-core 9.5: health data about a minor. v1 left the equivalent
            // in a plain column.
            'detail' => 'encrypted',
            'is_emergency_relevant' => 'boolean',
            'severity' => MedicalSeverity::class,
            'recorded_by' => 'integer',
            'recorded_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * The class-teacher view (8.2): emergency-relevant records only, and the
     * caller selects `summary` alone - never `detail`. The full record is
     * Nurse plus Administrator. Narrowing v1's "surfaced to class teachers",
     * which put every child's full medical picture in front of twelve staff.
     *
     * @param  Builder<StudentMedicalRecord>  $query
     * @return Builder<StudentMedicalRecord>
     */
    public function scopeEmergencyRelevant(Builder $query): Builder
    {
        return $query->where('is_emergency_relevant', '=', true);
    }
}
