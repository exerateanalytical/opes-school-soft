<?php

declare(strict_types=1);

namespace App\Modules\Curriculum\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * An ordered lesson within a unit, carrying the intended learning outcome
 * the teacher plans against.
 *
 * @property int $id
 * @property int $curriculum_unit_id
 * @property string $title
 * @property string|null $learning_outcome
 * @property int $sequence
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class CurriculumTopic extends Model
{
    /** @var list<string> */
    protected $fillable = ['curriculum_unit_id', 'title', 'learning_outcome', 'sequence'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'curriculum_unit_id' => 'integer',
            'sequence' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CurriculumUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(CurriculumUnit::class, 'curriculum_unit_id');
    }

    /**
     * @return BelongsToMany<Competency, $this>
     */
    public function competencies(): BelongsToMany
    {
        return $this->belongsToMany(
            Competency::class,
            'competency_curriculum_topic',
            'curriculum_topic_id',
            'competency_id',
        )->withTimestamps();
    }
}
