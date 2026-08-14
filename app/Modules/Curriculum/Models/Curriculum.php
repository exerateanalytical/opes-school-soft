<?php

declare(strict_types=1);

namespace App\Modules\Curriculum\Models;

use App\Modules\Academics\Domain\SubSystem;
use App\Modules\Curriculum\Domain\CurriculumStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One VERSION of the programme of study for a (subject, class level,
 * sub-system) triple, valid for an academic year (module-gap-analysis
 * gap #2).
 *
 * Curriculum models relate to each other only; subject/class-level/year
 * data crosses the boundary via DB::table inside Actions and screens
 * (ModuleBoundaryTest). SubSystem is Academics' DOMAIN enum, not a Model,
 * so the import is permitted.
 *
 * @property int $id
 * @property int $subject_id
 * @property int $class_level_id
 * @property int $academic_year_id
 * @property SubSystem $sub_system
 * @property string $title
 * @property string|null $description
 * @property int $version
 * @property CurriculumStatus $status
 * @property Carbon|null $published_at
 * @property int|null $published_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Curriculum extends Model
{
    /** @var string Laravel would guess "curricula" correctly, but being explicit costs nothing. */
    protected $table = 'curricula';

    /** @var list<string> */
    protected $fillable = [
        'subject_id', 'class_level_id', 'academic_year_id', 'sub_system',
        'title', 'description', 'version', 'status', 'published_at', 'published_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'class_level_id' => 'integer',
            'academic_year_id' => 'integer',
            'sub_system' => SubSystem::class,
            'version' => 'integer',
            'status' => CurriculumStatus::class,
            'published_at' => 'datetime',
            'published_by' => 'integer',
        ];
    }

    public function isPublished(): bool
    {
        return $this->status === CurriculumStatus::Published;
    }

    /**
     * @return HasMany<CurriculumUnit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(CurriculumUnit::class, 'curriculum_id')->orderBy('sequence');
    }

    /**
     * @return HasMany<Competency, $this>
     */
    public function competencies(): HasMany
    {
        return $this->hasMany(Competency::class, 'curriculum_id')->orderBy('code');
    }
}
