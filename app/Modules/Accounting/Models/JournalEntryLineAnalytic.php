<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use Database\Factories\JournalEntryLineAnalyticFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/specs/02-accounting.md §12.2 - one member's share of one line, on
 * one axis. `amount` is SIGNED (carries the line's debit-minus-credit
 * sign); `share_bp` is basis points where 1_000_000 = 100% (00-core §7.2).
 *
 * AN-1 (Σ amount conserves the line) holds BY CONSTRUCTION: the only
 * writer, AllocateLineAnalytics, derives amounts through
 * App\Support\Money\Allocator's largest-remainder split. This model never
 * computes an amount.
 *
 * @property int $id
 * @property int $journal_entry_line_id
 * @property int $analytic_axis_id
 * @property int $analytic_value_id
 * @property int $amount
 * @property int $share_bp
 */
final class JournalEntryLineAnalytic extends Model
{
    /** @use HasFactory<JournalEntryLineAnalyticFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'journal_entry_line_id',
        'analytic_axis_id',
        'analytic_value_id',
        'amount',
        'share_bp',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'share_bp' => 'integer',
        ];
    }

    protected static function newFactory(): JournalEntryLineAnalyticFactory
    {
        return JournalEntryLineAnalyticFactory::new();
    }

    /**
     * @return BelongsTo<JournalEntryLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(JournalEntryLine::class, 'journal_entry_line_id');
    }

    /**
     * @return BelongsTo<AnalyticAxis, $this>
     */
    public function axis(): BelongsTo
    {
        return $this->belongsTo(AnalyticAxis::class, 'analytic_axis_id');
    }

    /**
     * @return BelongsTo<AnalyticValue, $this>
     */
    public function value(): BelongsTo
    {
        return $this->belongsTo(AnalyticValue::class, 'analytic_value_id');
    }
}
