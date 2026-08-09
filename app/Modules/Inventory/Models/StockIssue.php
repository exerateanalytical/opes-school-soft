<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docs/specs/06-assets-stores.md §7.8 - ONE JournalEntry per issue header.
 *
 * @property int $id
 * @property string $issue_no
 * @property int $store_location_id
 * @property int|null $issued_to_staff_id
 * @property int|null $store_requisition_id
 * @property Carbon $issued_on
 * @property int|null $journal_entry_id
 * @property int $fiscal_year_id
 * @property int $academic_year_id
 * @property int $created_by
 * @property string|null $idempotency_key
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class StockIssue extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'issue_no', 'store_location_id', 'issued_to_staff_id',
        'store_requisition_id', 'issued_on', 'journal_entry_id',
        'fiscal_year_id', 'academic_year_id', 'created_by',
        'idempotency_key', 'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
        ];
    }

    /**
     * @return HasMany<StockIssueLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockIssueLine::class);
    }
}
