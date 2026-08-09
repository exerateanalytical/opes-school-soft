<?php

declare(strict_types=1);

namespace App\Modules\Tax\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * docs/specs/03-tax-procurement.md §5.4.3/.4 - a prorata regularisation
 * working-paper row. SCHEMA ONLY in Phase 5 F1: the annual regularisation
 * Action is F5's scope, and the multi-year capital-goods rule is NEEDS
 * VERIFICATION (asset_id stays a plain column until Assets, Phase 9).
 *
 * @property int $id
 * @property int $vat_prorata_id
 * @property int|null $asset_id
 * @property string $regularisation_type
 * @property int $amount
 * @property int|null $journal_entry_id
 */
final class VatProrataRegularisation extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'vat_prorata_id',
        'asset_id',
        'regularisation_type',
        'amount',
        'journal_entry_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }
}
