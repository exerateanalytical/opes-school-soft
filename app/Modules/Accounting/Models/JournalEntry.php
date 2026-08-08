<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use Database\Factories\JournalEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docs/specs/02-accounting.md §4.1. Persistence and the ONE permitted read
 * scope only - the invariants themselves (L1-L15) are enforced by the
 * migration's triggers/CHECKs and by the posting Actions under lock, never
 * by model events, exactly like AcademicYear (see that model's docblock).
 *
 * @property int $id
 * @property int $journal_id
 * @property string|null $piece_no
 * @property Carbon $date
 * @property Carbon $value_date
 * @property int $accounting_period_id
 * @property int $fiscal_year_id
 * @property int $academic_year_id
 * @property string $label
 * @property string|null $reference
 * @property string $status draft|posted|reversed
 * @property string|null $source_type
 * @property int|null $source_id
 * @property int|null $posting_rule_id
 * @property int|null $posting_rule_version
 * @property int|null $reverses_entry_id
 * @property int|null $reversed_by_entry_id
 * @property bool $is_reversal
 * @property string|null $reversal_reason
 * @property bool $is_migration
 * @property bool $is_forward_posted
 * @property int $total_debit
 * @property int $total_credit
 * @property int|null $created_by
 * @property int|null $posted_by
 * @property int|null $approved_by
 * @property Carbon|null $posted_at
 * @property Carbon|null $approved_at
 * @property string|null $idempotency_key
 * @property int $attachment_count
 */
final class JournalEntry extends Model
{
    /** @use HasFactory<JournalEntryFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const STATUS_REVERSED = 'reversed';

    /** @var list<string> */
    protected $fillable = [
        'journal_id',
        'piece_no',
        'date',
        'value_date',
        'accounting_period_id',
        'fiscal_year_id',
        'academic_year_id',
        'label',
        'reference',
        'status',
        'source_type',
        'source_id',
        'posting_rule_id',
        'posting_rule_version',
        'reverses_entry_id',
        'reversed_by_entry_id',
        'reversal_reason',
        'is_migration',
        'is_forward_posted',
        'total_debit',
        'total_credit',
        'created_by',
        'posted_by',
        'approved_by',
        'posted_at',
        'approved_at',
        'idempotency_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'value_date' => 'date',
            'is_reversal' => 'boolean',
            'is_migration' => 'boolean',
            'is_forward_posted' => 'boolean',
            'total_debit' => 'integer',
            'total_credit' => 'integer',
            'posted_at' => 'datetime',
            'approved_at' => 'datetime',
            'attachment_count' => 'integer',
        ];
    }

    protected static function newFactory(): JournalEntryFactory
    {
        return JournalEntryFactory::new();
    }

    /**
     * @return HasMany<JournalEntryLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class)->orderBy('sequence');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_entry_id');
    }

    /**
     * docs/specs/02-accounting.md §4.3 L13 / §9.3 - THE single query scope
     * every statement, book and report must use.
     *
     * It is deliberately NOT `where('status', 'posted')`. A `posted` entry
     * and its `reversed` counterpart are two halves of one correction: the
     * reversal exists precisely to cancel the original by netting to zero
     * in every statement, never by making the original disappear. Excluding
     * `reversed` removes the original half of that pair while leaving the
     * reversal itself untouched, which SILENTLY FLIPS THE SIGN of the whole
     * transaction - a 350 000 debit reads as a 350 000 credit - and the
     * trial balance still balances, so nothing else catches it. `draft` is
     * the only status with no accounting reality yet, so it is the only one
     * excluded.
     *
     * Pest architecture test: no file under Modules/Accounting/{Reports,
     * Books,Statements} may contain a literal 'posted'/'reversed' string in
     * a `where` on `status` - every such query must go through this scope.
     *
     * @param  Builder<JournalEntry>  $query
     * @return Builder<JournalEntry>
     */
    public function scopePostedLedger(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_POSTED, self::STATUS_REVERSED]);
    }
}
