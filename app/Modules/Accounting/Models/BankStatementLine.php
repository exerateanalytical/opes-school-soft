<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Modules\Accounting\Domain\StatementLineStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use App\Support\Retention\Immutable10Year;

/**
 * docs/specs/02-accounting.md §13.1.
 *
 * `credit` is money IN, `debit` is money OUT - the counterparty's wording,
 * which is the mirror of the ledger's on the same class-5 account. See
 * 390002 for the full note; `signedAmount()` below is the only place any
 * caller should need that fact.
 *
 * @property int $id
 * @property int $bank_statement_id
 * @property int $line_no
 * @property Carbon $operation_date
 * @property Carbon|null $value_date
 * @property string $label
 * @property string|null $reference
 * @property int $debit
 * @property int $credit
 * @property StatementLineStatus $status
 * @property string|null $ignore_reason
 * @property int|null $reconciliation_match_id
 * @property int|null $journal_entry_id
 */
final class BankStatementLine extends Model
{
    use Immutable10Year;
    protected $table = 'bank_statement_lines';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'operation_date' => 'date',
            'value_date' => 'date',
            'debit' => 'integer',
            'credit' => 'integer',
            'status' => StatementLineStatus::class,
        ];
    }

    /**
     * @return BelongsTo<BankStatement, $this>
     */
    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    /** Money-in positive: +92 500 for a receipt, −5 250 for a commission. */
    public function signedAmount(): int
    {
        return $this->credit - $this->debit;
    }

    public function isAvailable(): bool
    {
        return $this->status === StatementLineStatus::Unmatched;
    }
}
