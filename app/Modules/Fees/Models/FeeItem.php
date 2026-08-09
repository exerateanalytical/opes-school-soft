<?php

declare(strict_types=1);

namespace App\Modules\Fees\Models;

use App\Modules\Fees\Domain\CollectionBasis;
use App\Modules\Fees\Domain\FeeRecurrence;
use App\Modules\Fees\Domain\RecognitionMethod;
use Database\Factories\FeeItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 04-fees.md §2.2 - the billable thing. The collection_basis / account
 * pairing is guarded by chk_fee_items_basis in the schema and by
 * CreateFeeItem with friendlier errors.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $name_fr
 * @property int $fee_category_id
 * @property CollectionBasis $collection_basis
 * @property int|null $third_party_fund_id
 * @property int|null $revenue_account_id
 * @property RecognitionMethod $recognition_method
 * @property int|null $tax_code_id
 * @property bool $is_refundable
 * @property bool $is_mandatory
 * @property FeeRecurrence $default_recurrence
 * @property string|null $asset_or_service_note
 * @property bool $is_archived
 */
final class FeeItem extends Model
{
    /** @use HasFactory<FeeItemFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'code', 'name', 'name_fr', 'fee_category_id', 'collection_basis',
        'third_party_fund_id', 'revenue_account_id', 'recognition_method',
        'tax_code_id', 'is_refundable', 'is_mandatory', 'default_recurrence',
        'asset_or_service_note', 'is_archived',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fee_category_id' => 'integer',
            'collection_basis' => CollectionBasis::class,
            'third_party_fund_id' => 'integer',
            'revenue_account_id' => 'integer',
            'recognition_method' => RecognitionMethod::class,
            'tax_code_id' => 'integer',
            'is_refundable' => 'boolean',
            'is_mandatory' => 'boolean',
            'default_recurrence' => FeeRecurrence::class,
            'is_archived' => 'boolean',
        ];
    }

    protected static function newFactory(): FeeItemFactory
    {
        return FeeItemFactory::new();
    }

    /**
     * @return BelongsTo<FeeCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(FeeCategory::class, 'fee_category_id');
    }

    /**
     * @return BelongsTo<ThirdPartyFund, $this>
     */
    public function thirdPartyFund(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyFund::class);
    }

    /**
     * Conjunctive - ALL rows must match for the item to apply (§2.2.1).
     *
     * @return HasMany<FeeItemAudienceCriterion, $this>
     */
    public function audienceCriteria(): HasMany
    {
        return $this->hasMany(FeeItemAudienceCriterion::class);
    }

    /**
     * Disjunctive - ANY matching row excludes the student (§2.2.1).
     *
     * @return HasMany<FeeItemExclusionCriterion, $this>
     */
    public function exclusionCriteria(): HasMany
    {
        return $this->hasMany(FeeItemExclusionCriterion::class);
    }
}
