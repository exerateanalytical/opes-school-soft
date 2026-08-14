<?php

declare(strict_types=1);

namespace App\Modules\Curriculum\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An ordered chapter of a curriculum ("Unit 3 - Electricity").
 *
 * @property int $id
 * @property int $curriculum_id
 * @property string $title
 * @property string|null $description
 * @property int $sequence
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class CurriculumUnit extends Model
{
    /** @var list<string> */
    protected $fillable = ['curriculum_id', 'title', 'description', 'sequence'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'curriculum_id' => 'integer',
            'sequence' => 'integer',
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
     * @return HasMany<CurriculumTopic, $this>
     */
    public function topics(): HasMany
    {
        return $this->hasMany(CurriculumTopic::class, 'curriculum_unit_id')->orderBy('sequence');
    }
}
