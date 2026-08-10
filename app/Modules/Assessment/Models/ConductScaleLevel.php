<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One level on a conduct scale.
 *
 * `sequence` orders the levels for display and does NOT carry a numeric
 * value: conduct never enters an average (§12.3), so a level has no mark.
 *
 * @property int $id
 * @property string $code
 * @property int $sequence
 */
final class ConductScaleLevel extends Model
{
    protected $table = 'conduct_scale_levels';

    /** @var list<string> */
    protected $fillable = ['conduct_scale_id', 'code', 'label', 'label_fr', 'sequence'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sequence' => 'integer'];
    }

    /**
     * @return BelongsTo<ConductScale, $this>
     */
    public function scale(): BelongsTo
    {
        return $this->belongsTo(ConductScale::class, 'conduct_scale_id');
    }
}
