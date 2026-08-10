<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Modules\Accounting\Domain\ExpensePayeeType;
use App\Modules\Accounting\Domain\ExpenseStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docs/specs/02-accounting.md §21.3. Persistence and read scopes ONLY - the
 * invariants live in the migration's CHECKs/triggers and in the Actions
 * under transaction, never in model events (the JournalEntry rule).
 *
 * `payee_id` is a plain integer, NOT a relation: it points at suppliers OR
 * users depending on `payee_type`, and Accounting never reaches into another
 * module's Models (ModuleBoundaryTest). The payee label is resolved via
 * DB::table inside the screens - and `payee_name` is stored anyway, so a
 * printed voucher survives a rename.
 *
 * @property int $id
 * @property string $expense_no
 * @property Carbon $expense_date
 * @property ExpensePayeeType $payee_type
 * @property int|null $payee_id
 * @property string $payee_name
 * @property string $description
 * @property int $treasury_account_id
 * @property int $total_amount
 * @property string $currency
 * @property ExpenseStatus $status
 * @property string|null $attachment_ref
 * @property int $created_by
 * @property int|null $submitted_by
 * @property Carbon|null $submitted_at
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property int|null $posted_by
 * @property Carbon|null $posted_at
 * @property bool $requires_approval
 * @property int $approval_threshold_applied
 * @property int|null $journal_entry_id
 * @property string|null $rejection_reason
 * @property string|null $notes
 * @property string|null $idempotency_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Expense extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'expense_no', 'expense_date',
        'payee_type', 'payee_id', 'payee_name',
        'description', 'treasury_account_id', 'total_amount', 'currency',
        'status', 'attachment_ref',
        'created_by', 'submitted_by', 'submitted_at',
        'approved_by', 'approved_at', 'posted_by', 'posted_at',
        'requires_approval', 'approval_threshold_applied',
        'journal_entry_id', 'rejection_reason', 'notes', 'idempotency_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'payee_type' => ExpensePayeeType::class,
            'payee_id' => 'integer',
            'treasury_account_id' => 'integer',
            'total_amount' => 'integer',
            'status' => ExpenseStatus::class,
            'created_by' => 'integer',
            'submitted_by' => 'integer',
            'submitted_at' => 'datetime',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'posted_by' => 'integer',
            'posted_at' => 'datetime',
            'requires_approval' => 'boolean',
            'approval_threshold_applied' => 'integer',
            'journal_entry_id' => 'integer',
        ];
    }

    /**
     * @return HasMany<ExpenseLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ExpenseLine::class)->orderBy('line_no');
    }

    /**
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function treasuryAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'treasury_account_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /**
     * Everything still waiting on a human: the approver's queue.
     *
     * @param  Builder<Expense>  $query
     * @return Builder<Expense>
     */
    public function scopeAwaitingApproval(Builder $query): Builder
    {
        return $query->where('status', ExpenseStatus::Submitted->value);
    }

    /**
     * Approved but not yet in the ledger - the poster's queue.
     *
     * @param  Builder<Expense>  $query
     * @return Builder<Expense>
     */
    public function scopeAwaitingPosting(Builder $query): Builder
    {
        return $query->where('status', ExpenseStatus::Approved->value);
    }
}
