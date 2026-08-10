<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Modules\Accounting\Domain\ReconciliationMatchType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * docs/specs/02-accounting.md §13.1. The two sides live in the join tables
 * (390004) rather than in JSON columns, so BR-2 is a UNIQUE index instead of
 * a rule an Action has to remember.
 *
 * @property int $id
 * @property int $reconciliation_session_id
 * @property ReconciliationMatchType $match_type
 * @property int $amount
 * @property bool $is_auto
 * @property int $confidence_bp
 * @property int $matched_by
 * @property Carbon $matched_at
 */
final class ReconciliationMatch extends Model
{
    protected $table = 'reconciliation_matches';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'is_auto' => 'boolean',
            'confidence_bp' => 'integer',
            'matched_at' => 'datetime',
            'match_type' => ReconciliationMatchType::class,
        ];
    }

    /**
     * @return BelongsTo<ReconciliationSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ReconciliationSession::class, 'reconciliation_session_id');
    }

    /**
     * @return BelongsToMany<BankStatementLine, $this>
     */
    public function statementLines(): BelongsToMany
    {
        return $this->belongsToMany(
            BankStatementLine::class,
            'reconciliation_match_statement_lines',
            'reconciliation_match_id',
            'bank_statement_line_id',
        );
    }

    /**
     * @return BelongsToMany<JournalEntryLine, $this>
     */
    public function ledgerLines(): BelongsToMany
    {
        return $this->belongsToMany(
            JournalEntryLine::class,
            'reconciliation_match_ledger_lines',
            'reconciliation_match_id',
            'journal_entry_line_id',
        );
    }
}
