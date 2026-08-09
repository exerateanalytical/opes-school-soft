<?php

declare(strict_types=1);

namespace App\Modules\Students\Models;

use Database\Factories\PromotionCriteriaSetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docs/specs/07-students.md §10.4 — the promotion rulebook, versioned and
 * immutable once referenced by an evaluated run (the 00-core versioning
 * pattern). `isReferenced()` is the guard CreateCriteriaSet consults before
 * allowing any mutation.
 *
 * No relation to AcademicYear / SchoolSection / ClassLevel is declared:
 * tests/Architecture/ModuleBoundaryTest.php forbids cross-module Model use,
 * so the foreign keys are plain integers.
 *
 * @property int $id
 * @property int $academic_year_id
 * @property int $school_section_id
 * @property int|null $class_level_id
 * @property string $name
 * @property bool $is_active
 * @property int $version
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PromotionCriteriaSet extends Model
{
    /** @use HasFactory<PromotionCriteriaSetFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'academic_year_id',
        'school_section_id',
        'class_level_id',
        'name',
        'is_active',
        'version',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'academic_year_id' => 'integer',
            'school_section_id' => 'integer',
            'class_level_id' => 'integer',
            'is_active' => 'boolean',
            'version' => 'integer',
            'created_by' => 'integer',
        ];
    }

    /**
     * @return HasMany<PromotionCriterion, $this>
     */
    public function criteria(): HasMany
    {
        return $this->hasMany(PromotionCriterion::class, 'criteria_set_id')->orderBy('sequence');
    }

    /**
     * @return HasMany<PromotionRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(PromotionRun::class, 'criteria_set_id');
    }

    /**
     * Immutability trigger (§10.4): referenced by any run that has reached an
     * evaluation — i.e. any run at all, since a run row exists only once an
     * evaluation started.
     */
    public function isReferenced(): bool
    {
        return $this->runs()->exists();
    }

    protected static function newFactory(): PromotionCriteriaSetFactory
    {
        return PromotionCriteriaSetFactory::new();
    }
}
