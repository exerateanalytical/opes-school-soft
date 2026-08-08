<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Modules\Accounting\Domain\PartnerType;
use Database\Factories\JournalEntryLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/specs/02-accounting.md §4.2. Persistence only. L1 (one-sided) and L3
 * (immutable once the parent is posted/reversed) are enforced by the
 * migration's CHECK constraint and BEFORE INSERT/UPDATE/DELETE triggers -
 * proven directly against the database in LedgerInvariantsTest, not assumed
 * from this class.
 *
 * @property int $id
 * @property int $journal_entry_id
 * @property int $sequence
 * @property int $account_id
 * @property string $label
 * @property int $debit
 * @property int $credit
 * @property PartnerType|null $partner_type
 * @property int|null $partner_id
 * @property int|null $lettering_id
 * @property int|null $tax_code_id
 * @property int|null $tax_base_amount
 * @property \Illuminate\Support\Carbon|null $due_date
 * @property int|null $reconciliation_match_id
 * @property string|null $source_line_type
 * @property int|null $source_line_id
 */
final class JournalEntryLine extends Model
{
    /** @use HasFactory<JournalEntryLineFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'journal_entry_id',
        'sequence',
        'account_id',
        'label',
        'debit',
        'credit',
        'partner_type',
        'partner_id',
        'lettering_id',
        'tax_code_id',
        'tax_base_amount',
        'due_date',
        'reconciliation_match_id',
        'source_line_type',
        'source_line_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'debit' => 'integer',
            'credit' => 'integer',
            'tax_base_amount' => 'integer',
            'due_date' => 'date',
            'partner_type' => PartnerType::class,
        ];
    }

    protected static function newFactory(): JournalEntryLineFactory
    {
        return JournalEntryLineFactory::new();
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
