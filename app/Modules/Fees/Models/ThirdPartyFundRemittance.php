<?php

declare(strict_types=1);

namespace App\Modules\Fees\Models;

use Database\Factories\ThirdPartyFundRemittanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/specs/04-fees.md §2.3 - one hand-over of agent-collected money to
 * its beneficiary. UNIQUE(fund, period) where status <> cancelled, via the
 * generated `active_fund_key` column.
 *
 * @property int $id
 * @property int $third_party_fund_id
 * @property \Illuminate\Support\Carbon $period_start
 * @property \Illuminate\Support\Carbon $period_end
 * @property int $amount_collected
 * @property int $amount_remitted
 * @property \Illuminate\Support\Carbon|null $remitted_on
 * @property string|null $method
 * @property string|null $reference
 * @property string $status
 * @property int|null $journal_entry_id
 * @property int|null $approved_by
 * @property \Illuminate\Support\Carbon|null $approved_at
 */
final class ThirdPartyFundRemittance extends Model
{
    /** @use HasFactory<ThirdPartyFundRemittanceFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'third_party_fund_id',
        'period_start',
        'period_end',
        'amount_collected',
        'amount_remitted',
        'remitted_on',
        'method',
        'reference',
        'status',
        'journal_entry_id',
        'approved_by',
        'approved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'amount_collected' => 'integer',
            'amount_remitted' => 'integer',
            'remitted_on' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    protected static function newFactory(): ThirdPartyFundRemittanceFactory
    {
        return ThirdPartyFundRemittanceFactory::new();
    }

    /**
     * @return BelongsTo<ThirdPartyFund, $this>
     */
    public function fund(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyFund::class, 'third_party_fund_id');
    }
}
