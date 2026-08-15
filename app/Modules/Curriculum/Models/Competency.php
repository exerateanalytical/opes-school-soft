<?php

declare(strict_types=1);

namespace App\Modules\Curriculum\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A flat competency (code + descriptor) belonging to one curriculum
 * version, linked to the topics that develop it via a pivot. Deliberately
 * simple per the gap-#2 scope: no hierarchy, no weights.
 *
 * @property int $id
 * @property int $curriculum_id
 * @property string $code
 * @property string $descriptor
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Competency extends Model
{
    /** @var list<string> */
    protected $fillable = ['curriculum_id', 'code', 'descriptor'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'curriculum_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Curriculum, $this>
     */
    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class, 'curriculum_id');
    }

    /**
     * @return BelongsToMany<CurriculumTopic, $this>
     */
    public function topics(): BelongsToMany
    {
        return $this->belongsToMany(
            CurriculumTopic::class,
            'competency_curriculum_topic',
            'competency_id',
            'curriculum_topic_id',
        )->withTimestamps();
    }
}
