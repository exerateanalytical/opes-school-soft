<?php

declare(strict_types=1);

namespace App\Modules\Assets\Models;

use App\Modules\Assets\Domain\SubsidyStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 06-assets-stores.md §6.3 - a class-14 subvention d'investissement.
 * release_income_account_id stays NULL until 845 is verified (V5); while
 * NULL the release step is skipped-with-exception, never guessed.
 *
 * @property int $id
 * @property string $reference
 * @property int $donor_partner_id
 * @property int $subsidy_account_id
 * @property int|null $release_income_account_id
 * @property int $granted_amount
 * @property string $granted_on
 * @property string|null $agreement_ref
 * @property string|null $conditions
 * @property int $fiscal_year_id
 * @property int $academic_year_id
 * @property SubsidyStatus $status
 * @property string|null $idempotency_key
 */
final class InvestmentSubsidy extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'reference', 'donor_partner_id', 'subsidy_account_id',
        'release_income_account_id', 'granted_amount', 'granted_on',
        'agreement_ref', 'conditions', 'fiscal_year_id', 'academic_year_id',
        'status', 'idempotency_key',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'donor_partner_id' => 'integer',
            'subsidy_account_id' => 'integer',
            'release_income_account_id' => 'integer',
            'granted_amount' => 'integer',
            'fiscal_year_id' => 'integer',
            'academic_year_id' => 'integer',
            'status' => SubsidyStatus::class,
        ];
    }

    /**
     * @return HasMany<InvestmentSubsidyRelease, $this>
     */
    public function releases(): HasMany
    {
        return $this->hasMany(InvestmentSubsidyRelease::class);
    }
}
