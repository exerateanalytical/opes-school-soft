<?php

declare(strict_types=1);

namespace App\Modules\Tax\Models;

use App\Modules\Tax\Domain\ProrataBasis;
use App\Support\Rate\Rate;
use Illuminate\Database\Eloquent\Model;

/**
 * docs/specs/03-tax-procurement.md §5.4 - the prorata de déduction for one
 * fiscal year and basis. `rate_bp` in App\Support\Rate scale (100 000 =
 * 100%). Unusable until confirmed: ComputeLineTax refuses to split input
 * VAT against an unconfirmed prorata.
 *
 * No Eloquent relation to FiscalYear: that model belongs to Accounting and
 * cross-module reads go through DB::table (00-core §6.2).
 *
 * @property int $id
 * @property int $fiscal_year_id
 * @property ProrataBasis $basis
 * @property int $rate_bp
 * @property int $numerator_amount
 * @property int $denominator_amount
 * @property \Illuminate\Support\Carbon|null $computed_at
 * @property int|null $computed_by
 * @property string $source
 * @property string|null $manual_reason
 * @property int|null $confirmed_by
 * @property \Illuminate\Support\Carbon|null $confirmed_at
 * @property int|null $regularisation_entry_id
 */
final class VatProrata extends Model
{
    protected $table = 'vat_proratas';

    /** @var list<string> */
    protected $fillable = [
        'fiscal_year_id',
        'basis',
        'rate_bp',
        'numerator_amount',
        'denominator_amount',
        'computed_at',
        'computed_by',
        'source',
        'manual_reason',
        'confirmed_by',
        'confirmed_at',
        'regularisation_entry_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'basis' => ProrataBasis::class,
            'rate_bp' => 'integer',
            'numerator_amount' => 'integer',
            'denominator_amount' => 'integer',
            'computed_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function rate(): Rate
    {
        return Rate::ofBasisPoints($this->rate_bp);
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }
}
