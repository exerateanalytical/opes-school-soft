<?php

declare(strict_types=1);

namespace App\Modules\Academics\Models;

use Database\Factories\ClassLevelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rung of a section's academic ladder - Form 1, 6e, CM2
 * (docs/specs/00-core.md 8). `code` is UNIQUE within its section only; the
 * same code may recur in a different section.
 *
 * @property int $id
 * @property int $school_section_id
 * @property string $code
 * @property string $name
 * @property string $name_fr
 * @property int $order_index
 * @property bool $is_exam_class
 */
final class ClassLevel extends Model
{
    /** @use HasFactory<ClassLevelFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'school_section_id', 'code', 'name', 'name_fr', 'order_index', 'is_exam_class',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_index' => 'integer',
            'is_exam_class' => 'boolean',
        ];
    }

    /**
     * The model lives in a module, not App\Models, so Laravel's factory-name
     * guesser cannot find it. Point at the factory explicitly.
     */
    protected static function newFactory(): ClassLevelFactory
    {
        return ClassLevelFactory::new();
    }

    /**
     * @return BelongsTo<SchoolSection, $this>
     */
    public function schoolSection(): BelongsTo
    {
        return $this->belongsTo(SchoolSection::class);
    }
}
