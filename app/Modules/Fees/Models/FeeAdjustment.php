<?php

declare(strict_types=1);

namespace App\Modules\Fees\Models;

use App\Modules\Fees\Domain\AdjustmentApplicationMethod;
use App\Modules\Fees\Domain\FeeAdjustmentReasonType;
use App\Modules\Fees\Domain\FeeAdjustmentStatus;
use Database\Factories\FeeAdjustmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/specs/04-fees.md §8 - C2. Line-anchored, SIGNED amount: positive
 * reduces outstanding, negative is a surcharge and increases it.
 *
 * @property int $id
 * @property string $reference_no
 * @property int $invoice_line_id
 * @property int $enrollment_id
 * @property int $student_id
 * @property int $academic_year_id
 * @property int $fiscal_year_id
 * @property int $amount
 * @property FeeAdjustmentReasonType $reason_type
 * @property string $reason_note
 * @property int $adjustment_account_id
 * @property int|null $counterpart_account_id
 * @property int|null $donor_id
 * @property AdjustmentApplicationMethod $application_method
 * @property int|null $target_installment_id
 * @property Carbon $effective_date
 * @property FeeAdjustmentStatus $status
 * @property int $granted_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property int|null $reversed_by_adjustment_id
 * @property string|null $bulk_batch_id
 * @property int|null $journal_entry_id
 */
final class FeeAdjustment extends Model
{
    /** @use HasFactory<FeeAdjustmentFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'reference_no',
        'invoice_line_id',
        'enrollment_id',
        'student_id',
        'academic_year_id',
        'fiscal_year_id',
        'amount',
        'reason_type',
        'reason_note',
        'adjustment_account_id',
        'counterpart_account_id',
        'donor_id',
        'application_method',
        'target_installment_id',
        'effective_date',
        'status',
        'granted_by',
        'approved_by',
        'approved_at',
        'reversed_by_adjustment_id',
        'bulk_batch_id',
        'journal_entry_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'approved_at' => 'datetime',
            'reason_type' => FeeAdjustmentReasonType::class,
            'status' => FeeAdjustmentStatus::class,
            'application_method' => AdjustmentApplicationMethod::class,
        ];
    }

    /** @return BelongsTo<InvoiceLine, $this> */
    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class);
    }
}
