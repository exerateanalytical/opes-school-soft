<?php

declare(strict_types=1);

namespace App\Modules\Tax\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/specs/03-tax-procurement.md §6.2 - one ordered rule inside a
 * WithholdingProfile. UNIQUE(profile_id, sequence) at the database.
 *
 * @property int $id
 * @property int $withholding_profile_id
 * @property int $withholding_rule_id
 * @property int $sequence
 */
final class WithholdingProfileRule extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'withholding_profile_id',
        'withholding_rule_id',
        'sequence',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<WithholdingProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(WithholdingProfile::class, 'withholding_profile_id');
    }

    /**
     * @return BelongsTo<WithholdingRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(WithholdingRule::class, 'withholding_rule_id');
    }
}
