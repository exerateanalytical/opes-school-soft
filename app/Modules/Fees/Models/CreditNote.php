<?php

declare(strict_types=1);

namespace App\Modules\Fees\Models;

use App\Modules\Fees\Domain\CreditNoteReasonType;
use App\Modules\Fees\Domain\CreditNoteSettlementMode;
use App\Modules\Fees\Domain\CreditNoteStatus;
use Database\Factories\CreditNoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docs/specs/04-fees.md §9 - C7. A facture d'avoir: its own legal identity,
 * its own AV sequence, always referencing the invoice it corrects.
 *
 * @property int $id
 * @property string|null $credit_note_no
 * @property int $invoice_id
 * @property int $enrollment_id
 * @property int $student_id
 * @property int $academic_year_id
 * @property int $fiscal_year_id
 * @property Carbon $issue_date
 * @property CreditNoteReasonType $reason_type
 * @property string $reason_note
 * @property CreditNoteStatus $status
 * @property CreditNoteSettlementMode $settlement_mode
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property int|null $journal_entry_id
 * @property string|null $printed_pdf_hash
 * @property string|null $idempotency_key
 * @property int|null $created_by
 */
final class CreditNote extends Model
{
    /** @use HasFactory<CreditNoteFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'credit_note_no',
        'invoice_id',
        'enrollment_id',
        'student_id',
        'academic_year_id',
        'fiscal_year_id',
        'issue_date',
        'reason_type',
        'reason_note',
        'status',
        'settlement_mode',
        'approved_by',
        'approved_at',
        'journal_entry_id',
        'printed_pdf_hash',
        'idempotency_key',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'approved_at' => 'datetime',
            'reason_type' => CreditNoteReasonType::class,
            'status' => CreditNoteStatus::class,
            'settlement_mode' => CreditNoteSettlementMode::class,
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return HasMany<CreditNoteLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(CreditNoteLine::class);
    }

    /** Derived, never stored (A1). */
    public function total(): int
    {
        return (int) $this->lines()
            ->selectRaw('COALESCE(SUM(amount + tax_amount), 0) AS total')
            ->value('total');
    }
}
