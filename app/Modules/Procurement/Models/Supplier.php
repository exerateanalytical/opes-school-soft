<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use App\Modules\Procurement\Domain\NiuStatus;
use App\Modules\Procurement\Domain\RegimeFiscal;
use App\Modules\Procurement\Domain\SupplierType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * docs/specs/03-tax-procurement.md §3.1 - the supplier master record.
 *
 * Deletion is RESTRICT (§9): the observer throws unconditionally, because a
 * supplier that never traded is still an audit-relevant record of who was
 * onboarded and by whom - archive (`is_archived`) is the only exit. The
 * 10-year AUDCIF retention (02-accounting C5) covers this table.
 *
 * Bank and mobile-money identifiers are application-encrypted (00-core
 * §9.5): non-deterministic ciphertext, therefore unsearchable, which is why
 * the deterministic `_bidx` companions exist - the §3.2 duplicate hard
 * block runs on the blind index, never the plaintext.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $legal_form
 * @property SupplierType $supplier_type
 * @property string|null $niu
 * @property NiuStatus $niu_status
 * @property bool $is_niu_verified
 * @property Carbon|null $niu_verified_at
 * @property int|null $niu_verified_by
 * @property string|null $niu_verification_evidence
 * @property RegimeFiscal|null $regime_fiscal
 * @property string|null $tax_centre_name
 * @property string|null $rccm_number
 * @property bool $has_contributor_card
 * @property int|null $withholding_profile_id
 * @property bool $is_withholding_exempt
 * @property string|null $withholding_exemption_ref
 * @property string|null $withholding_exemption_expires_on
 * @property int|null $default_tax_code_id
 * @property int|null $default_expense_account_id
 * @property int $payable_account_id
 * @property int $payment_terms_days
 * @property string $currency
 * @property string|null $contact_name
 * @property string|null $phone
 * @property string|null $phone_alt
 * @property string|null $email
 * @property string|null $website
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $city
 * @property string|null $region
 * @property string $country
 * @property string|null $bank_name
 * @property string|null $bank_branch
 * @property string|null $bank_account_rib
 * @property string|null $bank_account_rib_bidx
 * @property string|null $mobile_money_operator
 * @property string|null $mobile_money_number
 * @property string|null $mobile_money_number_bidx
 * @property int|null $category_id
 * @property bool $is_active
 * @property bool $is_archived
 * @property string|null $blocked_reason
 * @property string|null $notes
 * @property int $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Supplier extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'legal_form',
        'supplier_type',
        'niu',
        'niu_status',
        'is_niu_verified',
        'niu_verified_at',
        'niu_verified_by',
        'niu_verification_evidence',
        'regime_fiscal',
        'tax_centre_name',
        'rccm_number',
        'has_contributor_card',
        'withholding_profile_id',
        'is_withholding_exempt',
        'withholding_exemption_ref',
        'withholding_exemption_expires_on',
        'default_tax_code_id',
        'default_expense_account_id',
        'payable_account_id',
        'payment_terms_days',
        'currency',
        'contact_name',
        'phone',
        'phone_alt',
        'email',
        'website',
        'address_line1',
        'address_line2',
        'city',
        'region',
        'country',
        'bank_name',
        'bank_branch',
        'bank_account_rib',
        'bank_account_rib_bidx',
        'mobile_money_operator',
        'mobile_money_number',
        'mobile_money_number_bidx',
        'category_id',
        'is_active',
        'is_archived',
        'blocked_reason',
        'notes',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'supplier_type' => SupplierType::class,
            'niu_status' => NiuStatus::class,
            'regime_fiscal' => RegimeFiscal::class,
            'is_niu_verified' => 'boolean',
            'niu_verified_at' => 'datetime',
            'has_contributor_card' => 'boolean',
            'is_withholding_exempt' => 'boolean',
            'is_active' => 'boolean',
            'is_archived' => 'boolean',
            // 00-core 9.5 - encrypted at rest, blind-indexed beside.
            'bank_name' => 'encrypted',
            'bank_branch' => 'encrypted',
            'bank_account_rib' => 'encrypted',
            'mobile_money_number' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (Supplier $supplier): void {
            throw new RuntimeException(sprintf(
                'Supplier %s is never deleted (03-tax-procurement 9, RESTRICT); archive it instead.',
                $supplier->code,
            ));
        });
    }

    /**
     * The §3.2 tier-1 duplicate key for bank/momo identifiers, following
     * Guardian::blindIndexFor: keyed on the app key so the value survives a
     * restore, canonicalised to bare alphanumerics so `10023-00123 456` and
     * `1002300123456` - one RIB transcribed by two clerks - collide.
     */
    public static function blindIndexFor(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $canonical = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', $value));

        if ($canonical === '') {
            return null;
        }

        return hash_hmac('sha256', $canonical, (string) Config::string('app.key'));
    }

    /**
     * @return BelongsTo<SupplierCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(SupplierCategory::class, 'category_id');
    }

    /**
     * @return HasMany<PurchaseOrder, $this>
     */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /**
     * @return HasMany<GoodsReceipt, $this>
     */
    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    /**
     * §6.4: an exemption certificate is only as good as its expiry date -
     * an expired one means the supplier IS withheld from (test obligation 7).
     */
    public function hasLiveWithholdingExemption(string $onDate): bool
    {
        if (! $this->is_withholding_exempt) {
            return false;
        }

        if ($this->withholding_exemption_expires_on === null) {
            return true;
        }

        return Carbon::parse($this->withholding_exemption_expires_on)->gte(Carbon::parse($onDate));
    }
}
