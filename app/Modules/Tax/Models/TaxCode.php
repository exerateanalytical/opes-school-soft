<?php

declare(strict_types=1);

namespace App\Modules\Tax\Models;

use App\Modules\Tax\Domain\TaxType;
use App\Support\Rate\Rate;
use Database\Factories\TaxCodeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * docs/specs/03-tax-procurement.md §5.3 - an effective-dated tax code
 * version. Append-only once referenced: a rate change closes the current
 * row (`effective_to`) and inserts a successor; ConfigureTaxCode forbids
 * editing `code`, `rate_bp` or `effective_from` in place, because doing so
 * silently rewrites the tax of every historical invoice.
 *
 * `rate_bp` is in App\Support\Rate scale: 100 000 bp = 100%.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $name_fr
 * @property TaxType $tax_type
 * @property int $rate_bp
 * @property string $direction
 * @property \Illuminate\Support\Carbon $effective_from
 * @property \Illuminate\Support\Carbon|null $effective_to
 * @property bool $is_exempt
 * @property bool $is_zero_rated
 * @property string|null $exemption_legal_ref
 * @property string|null $exemption_condition
 * @property int|null $collected_account_id
 * @property int|null $deductible_account_id
 * @property int|null $non_deductible_expense_account_id
 * @property bool $affects_prorata_numerator
 * @property bool $affects_prorata_denominator
 * @property bool $is_active
 */
final class TaxCode extends Model
{
    /** @use HasFactory<TaxCodeFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'name_fr',
        'tax_type',
        'rate_bp',
        'direction',
        'effective_from',
        'effective_to',
        'is_exempt',
        'is_zero_rated',
        'exemption_legal_ref',
        'exemption_condition',
        'collected_account_id',
        'deductible_account_id',
        'non_deductible_expense_account_id',
        'affects_prorata_numerator',
        'affects_prorata_denominator',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tax_type' => TaxType::class,
            'rate_bp' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_exempt' => 'boolean',
            'is_zero_rated' => 'boolean',
            'affects_prorata_numerator' => 'boolean',
            'affects_prorata_denominator' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): TaxCodeFactory
    {
        return TaxCodeFactory::new();
    }

    public function rate(): Rate
    {
        return Rate::ofBasisPoints($this->rate_bp);
    }

    /**
     * The version in force on the DOCUMENT date (§5.3: never now()).
     * `effective_to` is exclusive.
     *
     * @param  Builder<TaxCode>  $query
     * @return Builder<TaxCode>
     */
    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query
            ->whereDate('effective_from', '<=', $date)
            ->where(function (Builder $inner) use ($date): void {
                $inner->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>', $date);
            });
    }
}
