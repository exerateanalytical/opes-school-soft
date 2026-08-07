<?php

declare(strict_types=1);

namespace App\Modules\Academics\Models;

use Database\Factories\StreamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A series/stream within a section (Arts, Science, C, D...).
 *
 * `subject_basket` is load-bearing (docs/specs/00-core.md 8): it defines the
 * ranking cohort. Students are ranked only against classmates sharing the
 * same basket - ranking across different elective sets is arithmetically
 * unfair and a conseil de classe will reject it. See 01-assessment.
 *
 * @property int $id
 * @property int $school_section_id
 * @property string $code
 * @property string $name
 * @property string $name_fr
 * @property list<string> $subject_basket
 * @property bool $is_active
 */
final class Stream extends Model
{
    /** @use HasFactory<StreamFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'school_section_id', 'code', 'name', 'name_fr', 'subject_basket', 'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject_basket' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The model lives in a module, not App\Models, so Laravel's factory-name
     * guesser cannot find it. Point at the factory explicitly.
     */
    protected static function newFactory(): StreamFactory
    {
        return StreamFactory::new();
    }

    /**
     * @return BelongsTo<SchoolSection, $this>
     */
    public function schoolSection(): BelongsTo
    {
        return $this->belongsTo(SchoolSection::class);
    }
}
