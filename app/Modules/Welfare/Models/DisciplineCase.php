<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Models;

use App\Modules\Welfare\Domain\DisciplineCaseStatus;
use App\Modules\Welfare\Domain\DisciplineVisibility;
use Database\Factories\DisciplineCaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One discipline case (docs/specs/07-students.md §3.4 C3 — keyed on BOTH
 * student_id NOT NULL and enrollment_id NULL; migration 2026_08_09_260009).
 *
 * `student_id`/`enrollment_id`/`reported_by` are plain integer columns here,
 * not belongsTo relations: Student, Enrollment and User are other modules'
 * Models and ModuleBoundaryTest forbids importing them. Names cross the
 * boundary via DB::table joins inside the Livewire screens and Actions.
 *
 * @property int $id
 * @property int $student_id
 * @property int|null $enrollment_id
 * @property int $discipline_category_id
 * @property Carbon $occurred_on
 * @property int $reported_by
 * @property string $description
 * @property DisciplineCaseStatus $status
 * @property DisciplineVisibility $visibility
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by
 * @property string|null $resolution_note
 * @property bool $is_positive
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read DisciplineCategory $category
 */
final class DisciplineCase extends Model
{
    /** @use HasFactory<DisciplineCaseFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'student_id', 'enrollment_id', 'discipline_category_id', 'occurred_on',
        'reported_by', 'description', 'status', 'visibility', 'resolved_at',
        'resolved_by', 'resolution_note', 'is_positive',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
            'status' => DisciplineCaseStatus::class,
            'visibility' => DisciplineVisibility::class,
            'resolved_at' => 'datetime',
            'is_positive' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<DisciplineCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(DisciplineCategory::class, 'discipline_category_id');
    }

    /**
     * @return HasMany<DisciplineSanction, $this>
     */
    public function sanctions(): HasMany
    {
        return $this->hasMany(DisciplineSanction::class, 'discipline_case_id');
    }

    protected static function newFactory(): DisciplineCaseFactory
    {
        return DisciplineCaseFactory::new();
    }
}
