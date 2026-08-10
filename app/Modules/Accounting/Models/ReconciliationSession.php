<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Modules\Accounting\Domain\ReconciliationSessionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docs/specs/02-accounting.md §13.1/§13.3. The five money columns are the
 * état de rapprochement itself, persisted; see 390003 for the layout and why
 * they are stored rather than recomputed on read.
 *
 * @property int $id
 * @property string $session_no
 * @property int $treasury_account_id
 * @property int $accounting_period_id
 * @property int|null $bank_statement_id
 * @property ReconciliationSessionStatus $status
 * @property int $book_balance
 * @property int $statement_balance
 * @property int $deposits_in_transit
 * @property int $unpresented_payments
 * @property int $unrecorded_statement_items
 * @property int $computed_difference
 * @property int $opened_by
 * @property Carbon $opened_at
 * @property int|null $completed_by
 * @property Carbon|null $completed_at
 * @property string|null $notes
 */
final class ReconciliationSession extends Model
{
    protected $table = 'reconciliation_sessions';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'book_balance' => 'integer',
            'statement_balance' => 'integer',
            'deposits_in_transit' => 'integer',
            'unpresented_payments' => 'integer',
            'unrecorded_statement_items' => 'integer',
            'computed_difference' => 'integer',
            'opened_at' => 'datetime',
            'completed_at' => 'datetime',
            'status' => ReconciliationSessionStatus::class,
        ];
    }

    /**
     * @return HasMany<ReconciliationMatch, $this>
     */
    public function matches(): HasMany
    {
        return $this->hasMany(ReconciliationMatch::class, 'reconciliation_session_id');
    }

    /**
     * @return BelongsTo<BankStatement, $this>
     */
    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    /**
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function treasuryAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'treasury_account_id');
    }

    /**
     * @return BelongsTo<AccountingPeriod, $this>
     */
    public function accountingPeriod(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    public function isDraft(): bool
    {
        return $this->status === ReconciliationSessionStatus::Draft;
    }

    /** BR-3, as a question the UI can ask before offering the button. */
    public function ties(): bool
    {
        return $this->computed_difference === 0 && $this->unrecorded_statement_items === 0;
    }
}
