<?php

declare(strict_types=1);

namespace App\Modules\Fees\Models;

use App\Modules\Fees\Domain\BeneficiaryType;
use App\Modules\Fees\Domain\RemittanceFrequency;
use Database\Factories\ThirdPartyFundFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * 04-fees.md §2.3, C5 - money the school collects AS AN AGENT (APEE, exam
 * boards). The liability account must be class 47; that shape is enforced by
 * the Actions (a CHECK cannot subquery), and the seed ships EMPTY per §23.
 *
 * No cross-module relation to ChartOfAccount: ModuleBoundaryTest forbids
 * importing Accounting models. Read account data via query builder.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $name_fr
 * @property BeneficiaryType $beneficiary_type
 * @property string $beneficiary_name
 * @property string|null $beneficiary_niu
 * @property int $liability_account_id
 * @property RemittanceFrequency $remittance_frequency
 * @property int|null $remittance_due_day
 * @property bool $is_active
 */
final class ThirdPartyFund extends Model
{
    /** @use HasFactory<ThirdPartyFundFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'code', 'name', 'name_fr', 'beneficiary_type', 'beneficiary_name',
        'beneficiary_niu', 'liability_account_id', 'remittance_frequency',
        'remittance_due_day', 'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'beneficiary_type' => BeneficiaryType::class,
            'remittance_frequency' => RemittanceFrequency::class,
            'remittance_due_day' => 'integer',
            'liability_account_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): ThirdPartyFundFactory
    {
        return ThirdPartyFundFactory::new();
    }
}
