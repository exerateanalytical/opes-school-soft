<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docs/specs/05-hr-payroll.md 3.7. One appraisal per contract per period;
 * criteria live on child rows. Never printed on a certificat de travail -
 * a certificat carrying an appraisal is unlawful (13.2).
 *
 * @property int $id
 * @property int $staff_contract_id
 * @property string $period
 * @property string|null $score
 * @property int $reviewer_staff_id
 * @property string $status
 * @property Carbon|null $acknowledged_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class StaffAppraisal extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'staff_contract_id',
        'period',
        'score',
        'reviewer_staff_id',
        'status',
        'acknowledged_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'reviewer_staff_id' => 'integer',
            'acknowledged_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StaffContract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(StaffContract::class, 'staff_contract_id');
    }

    /**
     * @return HasMany<StaffAppraisalCriterion, $this>
     */
    public function criteria(): HasMany
    {
        return $this->hasMany(StaffAppraisalCriterion::class);
    }

    /**
     * @return BelongsTo<StaffMember, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'reviewer_staff_id');
    }
}
