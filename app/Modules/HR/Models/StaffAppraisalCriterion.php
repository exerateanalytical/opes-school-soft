<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One scored criterion row on a staff appraisal
 * (docs/specs/05-hr-payroll.md 3.7).
 *
 * @property int $id
 * @property int $staff_appraisal_id
 * @property string $criterion
 * @property string|null $score
 * @property string|null $comment
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class StaffAppraisalCriterion extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'staff_appraisal_id',
        'criterion',
        'score',
        'comment',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<StaffAppraisal, $this>
     */
    public function appraisal(): BelongsTo
    {
        return $this->belongsTo(StaffAppraisal::class, 'staff_appraisal_id');
    }
}
