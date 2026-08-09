<?php

declare(strict_types=1);

namespace App\Modules\Assets\Models;

use App\Modules\Assets\Domain\BelowThresholdBehaviour;
use App\Modules\Assets\Domain\DepreciationMethod;
use App\Modules\Assets\Domain\ProrataConvention;
use Database\Factories\AssetCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 06-assets-stores.md §2.1 - the accounting and fiscal depreciation policy
 * an asset copies at capitalisation (§5.3). A5: account FKs are frozen by
 * CreateAssetCategory once a posted asset exists under the category.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $name_fr
 * @property int|null $parent_id
 * @property int $asset_account_id
 * @property int $accumulated_depreciation_account_id
 * @property int|null $depreciation_expense_account_id
 * @property int $disposal_nbv_account_id
 * @property int $disposal_proceeds_account_id
 * @property int|null $impairment_provision_account_id
 * @property int|null $impairment_expense_account_id
 * @property int|null $revaluation_equity_account_id
 * @property int|null $in_progress_account_id
 * @property DepreciationMethod $depreciation_method
 * @property int|null $useful_life_months
 * @property int|null $declining_rate_bp
 * @property int $default_residual_rate_bp
 * @property ProrataConvention|null $prorata_convention
 * @property DepreciationMethod|null $tax_method
 * @property int|null $tax_rate_bp
 * @property int|null $tax_useful_life_months
 * @property int|null $derogatory_depreciation_account_id
 * @property int $capitalisation_threshold
 * @property BelowThresholdBehaviour $below_threshold_behaviour
 * @property int|null $below_threshold_expense_account_id
 * @property bool $requires_serial_number
 * @property bool $is_archived
 */
final class AssetCategory extends Model
{
    /** @use HasFactory<AssetCategoryFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'code', 'name', 'name_fr', 'parent_id',
        'asset_account_id', 'accumulated_depreciation_account_id',
        'depreciation_expense_account_id', 'disposal_nbv_account_id',
        'disposal_proceeds_account_id', 'impairment_provision_account_id',
        'impairment_expense_account_id', 'revaluation_equity_account_id',
        'in_progress_account_id', 'depreciation_method', 'useful_life_months',
        'declining_rate_bp', 'default_residual_rate_bp', 'prorata_convention',
        'tax_method', 'tax_rate_bp', 'tax_useful_life_months',
        'derogatory_depreciation_account_id', 'capitalisation_threshold',
        'below_threshold_behaviour', 'below_threshold_expense_account_id',
        'requires_serial_number', 'is_archived',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'parent_id' => 'integer',
            'asset_account_id' => 'integer',
            'accumulated_depreciation_account_id' => 'integer',
            'depreciation_expense_account_id' => 'integer',
            'disposal_nbv_account_id' => 'integer',
            'disposal_proceeds_account_id' => 'integer',
            'impairment_provision_account_id' => 'integer',
            'impairment_expense_account_id' => 'integer',
            'revaluation_equity_account_id' => 'integer',
            'in_progress_account_id' => 'integer',
            'depreciation_method' => DepreciationMethod::class,
            'useful_life_months' => 'integer',
            'declining_rate_bp' => 'integer',
            'default_residual_rate_bp' => 'integer',
            'prorata_convention' => ProrataConvention::class,
            'tax_method' => DepreciationMethod::class,
            'tax_rate_bp' => 'integer',
            'tax_useful_life_months' => 'integer',
            'derogatory_depreciation_account_id' => 'integer',
            'capitalisation_threshold' => 'integer',
            'below_threshold_behaviour' => BelowThresholdBehaviour::class,
            'below_threshold_expense_account_id' => 'integer',
            'requires_serial_number' => 'boolean',
            'is_archived' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<AssetCategory, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<AssetCategory, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Asset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    protected static function newFactory(): AssetCategoryFactory
    {
        return AssetCategoryFactory::new();
    }
}
