<?php

declare(strict_types=1);

namespace App\Modules\Fees\Models;

use App\Modules\Fees\Domain\InvoiceStatus;
use App\Modules\Fees\Domain\InvoiceType;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Support\Retention\Immutable10Year;

/**
 * docs/specs/04-fees.md §3. Persistence only - status transitions, locking
 * and the §5.3 invariant live in the Actions, never in model events.
 *
 * A1: balance is COMPUTED, never stored - there is no `balance` column, and
 * `outstandingAsOf()` derives §5's formula from the tables this work package
 * owns (adjustments, credit notes; the payment/write-off terms subtract in
 * their own work packages' extension of the same formula).
 *
 * @property int $id
 * @property string|null $invoice_no
 * @property int $enrollment_id
 * @property int $student_id
 * @property int $academic_year_id
 * @property int $fiscal_year_id
 * @property int|null $fee_structure_id
 * @property int|null $fee_structure_version
 * @property int|null $term_id
 * @property int|null $installment_plan_id
 * @property InvoiceType $type
 * @property Carbon $issue_date
 * @property Carbon $due_date
 * @property string $currency
 * @property InvoiceStatus $status
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by
 * @property string|null $cancellation_reason
 * @property bool $is_migration
 * @property int|null $journal_entry_id
 * @property string|null $notes
 * @property int $version
 * @property string|null $idempotency_key
 * @property int|null $created_by
 */
final class Invoice extends Model
{
    use Immutable10Year;
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'invoice_no',
        'enrollment_id',
        'student_id',
        'academic_year_id',
        'fiscal_year_id',
        'fee_structure_id',
        'fee_structure_version',
        'term_id',
        'installment_plan_id',
        'type',
        'issue_date',
        'due_date',
        'currency',
        'status',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'is_migration',
        'journal_entry_id',
        'notes',
        'version',
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
            'due_date' => 'date',
            'cancelled_at' => 'datetime',
            'is_migration' => 'boolean',
            'type' => InvoiceType::class,
            'status' => InvoiceStatus::class,
        ];
    }

    protected static function newFactory(): InvoiceFactory
    {
        return InvoiceFactory::new();
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('line_no');
    }

    /** @return HasMany<InvoiceInstallment, $this> */
    public function installments(): HasMany
    {
        return $this->hasMany(InvoiceInstallment::class)->orderBy('sequence_no');
    }

    /** @return HasMany<CreditNote, $this> */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    /**
     * Gross total = Σ line (amount + tax_amount). Derived, never stored (A1).
     */
    public function grossTotal(): int
    {
        return (int) $this->lines()
            ->selectRaw('COALESCE(SUM(amount + tax_amount), 0) AS total')
            ->value('total');
    }

    /**
     * §5 balance formula over the terms this work package owns:
     * invoiced − adjustments − credit_notes, as at date $d. Draft and
     * cancelled invoices contribute 0. Adjustment amounts are SIGNED -
     * a surcharge is negative and increases outstanding.
     */
    public function outstandingAsOf(Carbon $asOf): int
    {
        if ($this->status !== InvoiceStatus::Issued) {
            return 0;
        }

        $invoiced = $this->grossTotal();

        $adjusted = (int) DB::table('fee_adjustments')
            ->join('invoice_lines', 'invoice_lines.id', '=', 'fee_adjustments.invoice_line_id')
            ->where('invoice_lines.invoice_id', $this->id)
            ->where('fee_adjustments.status', 'approved')
            ->where('fee_adjustments.effective_date', '<=', $asOf->toDateString())
            ->sum('fee_adjustments.amount');

        $credited = (int) DB::table('credit_note_lines')
            ->join('credit_notes', 'credit_notes.id', '=', 'credit_note_lines.credit_note_id')
            ->join('invoice_lines', 'invoice_lines.id', '=', 'credit_note_lines.invoice_line_id')
            ->where('invoice_lines.invoice_id', $this->id)
            ->where('credit_notes.status', 'issued')
            ->where('credit_notes.issue_date', '<=', $asOf->toDateString())
            ->sum('credit_note_lines.amount');

        return $invoiced - $adjusted - $credited;
    }
}
