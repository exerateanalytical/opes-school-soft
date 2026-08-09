<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/specs/06-assets-stores.md §7.8. `issue_cost` is the §7.1 derived
 * cost snapshot, authoritative for the line.
 *
 * @property int $id
 * @property int $stock_issue_id
 * @property int $item_id
 * @property string $quantity
 * @property int $issue_cost
 * @property int|null $stock_movement_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class StockIssueLine extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'stock_issue_id', 'item_id', 'quantity', 'issue_cost', 'stock_movement_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'string',
            'issue_cost' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<StockIssue, $this>
     */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(StockIssue::class, 'stock_issue_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
