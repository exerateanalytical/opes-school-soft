<?php

declare(strict_types=1);

namespace App\Modules\Assets\Models;

use App\Modules\Assets\Domain\AcquisitionType;
use App\Modules\Assets\Domain\AssetStatus;
use App\Modules\Assets\Domain\CostBasis;
use App\Modules\Assets\Domain\DepreciationMethod;
use App\Modules\Assets\Domain\ProrataConvention;
use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Support\Retention\Immutable10Year;

/**
 * 06-assets-stores.md §2.2 - the fixed-asset register row. Depreciation
 * policy columns are SNAPSHOTS taken at capitalisation (§5.3), never live
 * category lookups. A13: no hard delete, ever.
 *
 * @property int $id
 * @property string $tag_number
 * @property string|null $serial_number
 * @property int $asset_category_id
 * @property int|null $parent_asset_id
 * @property string $name
 * @property string|null $description
 * @property AssetStatus $status
 * @property string $acquisition_date
 * @property int $acquisition_cost
 * @property CostBasis $cost_basis
 * @property int $non_recoverable_vat_amount
 * @property int $residual_value
 * @property string|null $in_service_date
 * @property string|null $depreciation_start_date
 * @property int|null $useful_life_months
 * @property DepreciationMethod|null $depreciation_method
 * @property ProrataConvention|null $prorata_convention
 * @property AcquisitionType $acquisition_type
 * @property int|null $fair_value_at_donation
 * @property int|null $donor_id
 * @property int|null $investment_subsidy_id
 * @property int|null $supplier_id
 * @property int|null $supplier_invoice_id
 * @property int|null $location_id
 * @property int|null $custodian_staff_id
 * @property int|null $school_section_id
 * @property int $fiscal_year_id
 * @property int $academic_year_id
 * @property string|null $insurance_policy_ref
 * @property string|null $warranty_expires_on
 * @property int|null $disposal_id
 * @property int|null $journal_entry_id
 * @property string|null $notes
 * @property string|null $idempotency_key
 */
final class Asset extends Model
{
    use Immutable10Year;
    /** @use HasFactory<AssetFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'tag_number', 'serial_number', 'asset_category_id', 'parent_asset_id',
        'name', 'description', 'status', 'acquisition_date', 'acquisition_cost',
        'cost_basis', 'non_recoverable_vat_amount', 'residual_value',
        'in_service_date', 'depreciation_start_date', 'useful_life_months',
        'depreciation_method', 'prorata_convention', 'acquisition_type',
        'fair_value_at_donation', 'donor_id', 'investment_subsidy_id',
        'supplier_id', 'supplier_invoice_id', 'location_id',
        'custodian_staff_id', 'school_section_id', 'fiscal_year_id',
        'academic_year_id', 'insurance_policy_ref', 'warranty_expires_on',
        'notes', 'idempotency_key',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'asset_category_id' => 'integer',
            'parent_asset_id' => 'integer',
            'status' => AssetStatus::class,
            'acquisition_cost' => 'integer',
            'cost_basis' => CostBasis::class,
            'non_recoverable_vat_amount' => 'integer',
            'residual_value' => 'integer',
            'useful_life_months' => 'integer',
            'depreciation_method' => DepreciationMethod::class,
            'prorata_convention' => ProrataConvention::class,
            'acquisition_type' => AcquisitionType::class,
            'fair_value_at_donation' => 'integer',
            'donor_id' => 'integer',
            'investment_subsidy_id' => 'integer',
            'supplier_id' => 'integer',
            'supplier_invoice_id' => 'integer',
            'location_id' => 'integer',
            'custodian_staff_id' => 'integer',
            'school_section_id' => 'integer',
            'fiscal_year_id' => 'integer',
            'academic_year_id' => 'integer',
            'disposal_id' => 'integer',
            'journal_entry_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AssetCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function parentAsset(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_asset_id');
    }

    /**
     * @return HasMany<Asset, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(self::class, 'parent_asset_id');
    }

    /**
     * @return HasMany<AssetCustodyMovement, $this>
     */
    public function custodyMovements(): HasMany
    {
        return $this->hasMany(AssetCustodyMovement::class);
    }

    /**
     * @return HasMany<AssetConstructionCost, $this>
     */
    public function constructionCosts(): HasMany
    {
        return $this->hasMany(AssetConstructionCost::class);
    }

    protected static function newFactory(): AssetFactory
    {
        return AssetFactory::new();
    }
}
