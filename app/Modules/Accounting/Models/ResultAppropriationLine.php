<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/specs/02-accounting.md §18.3 - one statutory allocation of the
 * result: legal reserve, other reserves, 11 report à nouveau, distribution.
 *
 * @property int $id
 * @property int $result_appropriation_id
 * @property int $account_id
 * @property int $amount signed
 * @property string $label
 * @property int $sequence
 */
final class ResultAppropriationLine extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'result_appropriation_id', 'account_id', 'amount', 'label', 'sequence',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'sequence' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ResultAppropriation, $this>
     */
    public function appropriation(): BelongsTo
    {
        return $this->belongsTo(ResultAppropriation::class, 'result_appropriation_id');
    }

    /**
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }
}
