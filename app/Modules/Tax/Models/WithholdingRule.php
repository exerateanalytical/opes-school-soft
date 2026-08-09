<?php

declare(strict_types=1);

namespace App\Modules\Tax\Models;

use App\Modules\Tax\Domain\WithholdingBase;
use App\Modules\Tax\Domain\WithholdingType;
use App\Support\Rate\Rate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * docs/specs/03-tax-procurement.md §6.2 - an effective-dated withholding
 * rule version. Ships EMPTY (no rate seeded, §6.1); append-only once
 * created, same discipline as TaxCode: ConfigureWithholdingRule forbids
 * editing code / rate_bp / withholding_type / effective_from in place.
 *
 * `rate_bp` in App\Support\Rate scale: 5.5% = 5 500.
 * An unconfirmed rule (confirmed_at NULL) is never applied (§6.2), and a
 * rule with an unset `base` cannot be confirmed.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $name_fr
 * @property WithholdingType $withholding_type
 * @property int $rate_bp
 * @property WithholdingBase|null $base
 * @property string $applies_to
 * @property int $minimum_base
 * @property array<string, mixed>|null $supplier_condition
 * @property int $priority
 * @property int|null $liability_account_id
 * @property string|null $declaration_type
 * @property string|null $legal_ref
 * @property \Illuminate\Support\Carbon $effective_from
 * @property \Illuminate\Support\Carbon|null $effective_to
 * @property bool $is_active
 * @property int|null $confirmed_by
 * @property \Illuminate\Support\Carbon|null $confirmed_at
 */
final class WithholdingRule extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'name_fr',
        'withholding_type',
        'rate_bp',
        'base',
        'applies_to',
        'minimum_base',
        'supplier_condition',
        'priority',
        'liability_account_id',
        'declaration_type',
        'legal_ref',
        'effective_from',
        'effective_to',
        'is_active',
        'confirmed_by',
        'confirmed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'withholding_type' => WithholdingType::class,
            'rate_bp' => 'integer',
            'base' => WithholdingBase::class,
            'minimum_base' => 'integer',
            'supplier_condition' => 'array',
            'priority' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'boolean',
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

    /**
     * The version in force on the recognition date (§6.3: invoice date when
     * on_invoice, payment date when on_payment - never now()).
     * `effective_to` is exclusive.
     *
     * @param  Builder<WithholdingRule>  $query
     * @return Builder<WithholdingRule>
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

    /**
     * Does this rule's applies_to cover a line of the given nature?
     * `both` covers services and goods; rent/commission match exactly.
     */
    public function appliesToNature(string $nature): bool
    {
        if ($this->applies_to === $nature) {
            return true;
        }

        return $this->applies_to === 'both'
            && in_array($nature, ['services', 'goods'], true);
    }
}
