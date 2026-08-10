<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A conduct grading scale (01-assessment §12.3). A reference table, not an
 * enum: TB/B/AB/P/M for the Francophone secondary bulletin, A/ECA/NA for a
 * competency-based framework.
 *
 * @property int $id
 * @property string $code
 */
final class ConductScale extends Model
{
    protected $table = 'conduct_scales';

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'name_fr', 'framework_id', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return HasMany<ConductScaleLevel, $this>
     */
    public function levels(): HasMany
    {
        return $this->hasMany(ConductScaleLevel::class, 'conduct_scale_id')->orderBy('sequence');
    }
}
