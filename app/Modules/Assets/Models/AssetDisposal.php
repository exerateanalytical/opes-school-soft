<?php

declare(strict_types=1);

namespace App\Modules\Assets\Models;

use App\Modules\Assets\Domain\DisposalSettlement;
use App\Modules\Assets\Domain\DisposalType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 06-assets-stores.md §6.1. One disposal per asset (UNIQUE). gain_or_loss
 * is a GENERATED column - never fillable, never posted; the P&L carries
 * the loss/gain as the gross 812/822 pair.
 *
 * @property int $id
 * @property int $asset_id
 * @property DisposalType $disposal_type
 * @property string $disposal_date
 * @property int $proceeds_amount
 * @property int|null $buyer_partner_id
 * @property DisposalSettlement|null $settlement
 * @property int $nbv_at_disposal
 * @property int $accumulated_at_disposal
 * @property int $gain_or_loss
 * @property int $approved_by
 * @property string $approved_at
 * @property string $reason
 * @property string|null $document_ref
 * @property int $journal_entry_id
 * @property string|null $idempotency_key
 */
final class AssetDisposal extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'asset_id', 'disposal_type', 'disposal_date', 'proceeds_amount',
        'buyer_partner_id', 'settlement', 'nbv_at_disposal',
        'accumulated_at_disposal', 'approved_by', 'approved_at', 'reason',
        'document_ref', 'journal_entry_id', 'idempotency_key',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'asset_id' => 'integer',
            'disposal_type' => DisposalType::class,
            'proceeds_amount' => 'integer',
            'buyer_partner_id' => 'integer',
            'settlement' => DisposalSettlement::class,
            'nbv_at_disposal' => 'integer',
            'accumulated_at_disposal' => 'integer',
            'gain_or_loss' => 'integer',
            'approved_by' => 'integer',
            'journal_entry_id' => 'integer',
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
