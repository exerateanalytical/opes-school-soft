<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Models;

use App\Support\Score\Score;
use Database\Factories\GradeBandFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One interval of the grade ladder (docs/specs/01-assessment.md 3.3).
 *
 * Half-open `[min, max)` everywhere except the top band, which is CLOSED so a
 * perfect score bands instead of falling off the end. "Top band" is not a
 * column: it is the band whose `max_score` equals the scale ceiling, which
 * ConfigureGradeBands guarantees exists for every configured tuple.
 *
 * No belongsTo for `class_level_id` - that row is Academics' (00-core 6.2).
 *
 * @property int $id
 * @property int $framework_id
 * @property string $purpose
 * @property string $scale_basis
 * @property int|null $class_level_id
 * @property numeric-string $min_score
 * @property numeric-string $max_score
 * @property string $label
 * @property string $label_fr
 * @property string|null $mention
 * @property numeric-string|null $grade_point
 * @property bool $is_pass
 * @property string|null $colour
 * @property int $order_index
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class GradeBand extends Model
{
    /** @use HasFactory<GradeBandFactory> */
    use HasFactory;

    /** 01-assessment 3.3: GCE letter grades are Board artefacts. */
    public const PURPOSE_INTERNAL = 'internal';

    public const PURPOSE_EXAM_O_LEVEL = 'exam_o_level';

    public const PURPOSE_EXAM_A_LEVEL = 'exam_a_level';

    /** @var list<string> */
    public const PURPOSES = [self::PURPOSE_INTERNAL, self::PURPOSE_EXAM_O_LEVEL, self::PURPOSE_EXAM_A_LEVEL];

    public const BASIS_OUT_OF_MAX = 'out_of_max';

    public const BASIS_PERCENTAGE = 'percentage';

    /** @var list<string> */
    public const BASES = [self::BASIS_OUT_OF_MAX, self::BASIS_PERCENTAGE];

    /** @var list<string> */
    protected $fillable = [
        'framework_id',
        'purpose',
        'scale_basis',
        'class_level_id',
        'min_score',
        'max_score',
        'label',
        'label_fr',
        'mention',
        'grade_point',
        'is_pass',
        'colour',
        'order_index',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'framework_id' => 'int',
            'class_level_id' => 'int',
            'min_score' => 'decimal:3',
            'max_score' => 'decimal:3',
            'grade_point' => 'decimal:2',
            'is_pass' => 'bool',
            'order_index' => 'int',
        ];
    }

    protected static function newFactory(): GradeBandFactory
    {
        return GradeBandFactory::new();
    }

    /**
     * @return BelongsTo<AssessmentFramework, $this>
     */
    public function framework(): BelongsTo
    {
        return $this->belongsTo(AssessmentFramework::class, 'framework_id');
    }

    /**
     * Whether a ROUNDED score falls in this band. The caller rounds first
     * (01-assessment 10.1): banding the unrounded value is how a bulletin ends
     * up printing 12.00 beside the mention for 11.99.
     *
     * `$isTopBand` closes the upper bound. It is passed in rather than derived
     * here because only the whole set knows which band is the top one.
     */
    public function contains(Score $score, bool $isTopBand): bool
    {
        $value = $score->thousandths();
        $min = Score::of($this->min_score)->thousandths();
        $max = Score::of($this->max_score)->thousandths();

        if ($value < $min) {
            return false;
        }

        return $isTopBand ? $value <= $max : $value < $max;
    }
}
