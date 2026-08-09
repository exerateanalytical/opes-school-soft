<?php

declare(strict_types=1);

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 06-assets-stores.md §2.3 - append-only custody trail. The schema's
 * triggers reject every UPDATE except the single acknowledgement
 * transition, and every DELETE; the model deliberately exposes no
 * convenience mutators.
 *
 * @property int $id
 * @property int $asset_id
 * @property int|null $from_staff_id
 * @property int|null $to_staff_id
 * @property int|null $from_location_id
 * @property int|null $to_location_id
 * @property string $moved_on
 * @property string $reason
 * @property string|null $document_ref
 * @property \Illuminate\Support\Carbon|null $acknowledged_at
 * @property int|null $acknowledged_by
 * @property int $recorded_by
 * @property string|null $idempotency_key
 */
final class AssetCustodyMovement extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'asset_id', 'from_staff_id', 'to_staff_id', 'from_location_id',
        'to_location_id', 'moved_on', 'reason', 'document_ref',
        'recorded_by', 'idempotency_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'asset_id' => 'integer',
            'from_staff_id' => 'integer',
            'to_staff_id' => 'integer',
            'from_location_id' => 'integer',
            'to_location_id' => 'integer',
            'acknowledged_at' => 'datetime',
            'acknowledged_by' => 'integer',
            'recorded_by' => 'integer',
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
