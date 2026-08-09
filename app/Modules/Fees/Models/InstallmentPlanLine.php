<?php

declare(strict_types=1);

namespace App\Modules\Fees\Models;

use Database\Factories\InstallmentPlanLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 04-fees.md §2.6 - one tranche. Exactly one of due_date /
 * due_offset_days, and the basis-appropriate amount column, are enforced in
 * SaveInstallmentPlan.
 *
 * @property int $id
 * @property int $installment_plan_id
 * @property int $sequence_no 1-based
 * @property string $label
 * @property string $label_fr
 * @property int|null $percentage_bp
 * @property int|null $fixed_amount
 * @property Carbon|null $due_date
 * @property int|null $due_offset_days
 */
final class InstallmentPlanLine extends Model
{
    /** @use HasFactory<InstallmentPlanLineFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'installment_plan_id', 'sequence_no', 'label', 'label_fr',
        'percentage_bp', 'fixed_amount', 'due_date', 'due_offset_days',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'installment_plan_id' => 'integer',
            'sequence_no' => 'integer',
            'percentage_bp' => 'integer',
            'fixed_amount' => 'integer',
            'due_date' => 'date',
            'due_offset_days' => 'integer',
        ];
    }

    protected static function newFactory(): InstallmentPlanLineFactory
    {
        return InstallmentPlanLineFactory::new();
    }

    /**
     * @return BelongsTo<InstallmentPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(InstallmentPlan::class, 'installment_plan_id');
    }
}
