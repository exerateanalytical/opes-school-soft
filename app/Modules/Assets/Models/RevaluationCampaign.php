<?php

declare(strict_types=1);

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 06-assets-stores.md §6.6. Revaluation is a regulated CAMPAIGN applied to
 * whole categories, never a free-form edit of one asset row. Ships
 * disabled behind the V8 (106 écart de réévaluation) account gate.
 *
 * @property int $id
 * @property string $reference
 * @property string $legal_basis
 * @property string $campaign_date
 * @property list<int> $asset_category_ids
 * @property int|null $approved_by
 * @property string|null $approved_at
 * @property string $status
 */
final class RevaluationCampaign extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'reference', 'legal_basis', 'campaign_date', 'asset_category_ids',
        'approved_by', 'approved_at', 'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'asset_category_ids' => 'array',
            'approved_by' => 'integer',
        ];
    }

    /**
     * @return HasMany<AssetRevaluation, $this>
     */
    public function revaluations(): HasMany
    {
        return $this->hasMany(AssetRevaluation::class, 'campaign_id');
    }
}
