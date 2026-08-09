<?php

declare(strict_types=1);

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 06-assets-stores.md §6.5. The feature ships DISABLED (V9): no rows can
 * be created until the class-29 provision and impairment expense accounts
 * are verified and configured - ImpairAsset refuses first. impairment_loss
 * is GENERATED (carrying − recoverable).
 *
 * @property int $id
 * @property int $asset_id
 * @property string $test_date
 * @property int $carrying_amount
 * @property int $recoverable_amount
 * @property int $impairment_loss
 * @property string $basis
 * @property string|null $evidence_ref
 * @property int $approved_by
 * @property string $approved_at
 * @property int|null $journal_entry_id
 * @property int|null $reversed_by_impairment_id
 */
final class AssetImpairment extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'asset_id', 'test_date', 'carrying_amount', 'recoverable_amount',
        'basis', 'evidence_ref', 'approved_by', 'approved_at',
        'journal_entry_id', 'reversed_by_impairment_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'asset_id' => 'integer',
            'carrying_amount' => 'integer',
            'recoverable_amount' => 'integer',
            'impairment_loss' => 'integer',
            'approved_by' => 'integer',
            'journal_entry_id' => 'integer',
            'reversed_by_impairment_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
