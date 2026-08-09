<?php

declare(strict_types=1);

namespace App\Modules\Tax\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/specs/03-tax-procurement.md §7.2 step 6 - a negative TVA net
 * carried forward. Consumed (once, whole) by the first later declaration
 * that absorbs it; `consumed_in_declaration_id` NULL = still open.
 *
 * @property int $id
 * @property int $fiscal_year_id
 * @property int $period_year
 * @property int $period_month
 * @property int $amount
 * @property int $source_declaration_id
 * @property int|null $consumed_in_declaration_id
 */
final class TaxCredit extends Model
{
    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'period_month' => 'integer',
            'amount' => 'integer',
        ];
    }

    /**
     * @param  Builder<TaxCredit>  $query
     * @return Builder<TaxCredit>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('consumed_in_declaration_id');
    }

    /**
     * @return BelongsTo<TaxDeclaration, $this>
     */
    public function sourceDeclaration(): BelongsTo
    {
        return $this->belongsTo(TaxDeclaration::class, 'source_declaration_id');
    }
}
