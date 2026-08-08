<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Models;

use App\Modules\Assessment\Domain\ComponentKind;
use App\Support\Score\Score;
use Database\Factories\AssessmentComponentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A column on the marks-entry grid - CA, EXAM, TP, ORAL
 * (docs/specs/01-assessment.md 5.3).
 *
 * `max_score` is the component's OWN maximum and is what pipeline stage 2
 * divides by. That is the whole of the 2.1 correction: a CA marked out of 30
 * and an exam marked out of 100 are normalised against 30 and 100, not against
 * the framework's 20.
 *
 * @property int $id
 * @property int $framework_id
 * @property string $code
 * @property string $name
 * @property string $name_fr
 * @property numeric-string $max_score
 * @property int $order_index
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class AssessmentComponent extends Model
{
    /** @use HasFactory<AssessmentComponentFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'framework_id',
        'code',
        'name',
        'name_fr',
        'max_score',
        'order_index',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'framework_id' => 'int',
            'max_score' => 'decimal:3',
            'order_index' => 'int',
            'is_active' => 'bool',
        ];
    }

    protected static function newFactory(): AssessmentComponentFactory
    {
        return AssessmentComponentFactory::new();
    }

    /**
     * @return BelongsTo<AssessmentFramework, $this>
     */
    public function framework(): BelongsTo
    {
        return $this->belongsTo(AssessmentFramework::class, 'framework_id');
    }

    /**
     * Derived from `code`, never stored - see ComponentKind for why. An
     * unrecognised code answers `Other`, which carries no behaviour.
     */
    public function kind(): ComponentKind
    {
        return ComponentKind::fromCode($this->code);
    }

    /** The component's own maximum, as the pipeline's value object. */
    public function maximum(): Score
    {
        return Score::of($this->max_score);
    }
}
