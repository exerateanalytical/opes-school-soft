<?php

declare(strict_types=1);

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 06-assets-stores.md §6.6 - one asset inside a revaluation campaign.
 * `ecart` (écart de réévaluation) is GENERATED, SIGNED, and never posted
 * until the V8 account (106) is verified.
 *
 * @property int $id
 * @property int $campaign_id
 * @property int $asset_id
 * @property int $carrying_before
 * @property int $revalued_amount
 * @property int $ecart
 * @property int|null $journal_entry_id
 */
final class AssetRevaluation extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'campaign_id', 'asset_id', 'carrying_before', 'revalued_amount',
        'journal_entry_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'campaign_id' => 'integer',
            'asset_id' => 'integer',
            'carrying_before' => 'integer',
            'revalued_amount' => 'integer',
            'ecart' => 'integer',
            'journal_entry_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<RevaluationCampaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(RevaluationCampaign::class, 'campaign_id');
    }
}
