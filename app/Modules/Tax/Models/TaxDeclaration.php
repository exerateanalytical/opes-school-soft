<?php

declare(strict_types=1);

namespace App\Modules\Tax\Models;

use App\Modules\Tax\Domain\DeclarationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * docs/specs/03-tax-procurement.md §7.1 - one declaration per type per
 * period (DB unique backstop over (type, year, month, period_slot);
 * originals share slot 0, an amendment occupies the slot of the row it
 * amends).
 *
 * A declaration past `draft` is never deleted: it is cancelled (which
 * frees the period for in-place regeneration) or amended. `inputs_hash`
 * is stored at generation and re-verified at filing - filing fails if the
 * ledger changed underneath (§7.1).
 *
 * @property int $id
 * @property string $declaration_type
 * @property string $period_type
 * @property int $period_year
 * @property int $period_month
 * @property int $fiscal_year_id
 * @property Carbon|null $due_date
 * @property DeclarationStatus $status
 * @property Carbon|null $generated_at
 * @property int|null $generated_by
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $filed_at
 * @property int|null $filed_by
 * @property string|null $filing_channel
 * @property string|null $external_reference
 * @property int $amount_declared
 * @property int $amount_paid
 * @property Carbon|null $paid_at
 * @property string|null $payment_reference
 * @property int $penalty_amount
 * @property int $interest_amount
 * @property int|null $amends_declaration_id
 * @property int $period_slot
 * @property list<int>|null $generated_from_entry_ids
 * @property string|null $inputs_hash
 * @property int|null $document_id
 * @property string|null $notes
 */
final class TaxDeclaration extends Model
{
    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'period_month' => 'integer',
            'due_date' => 'date',
            'status' => DeclarationStatus::class,
            'generated_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'filed_at' => 'datetime',
            'paid_at' => 'datetime',
            'amount_declared' => 'integer',
            'amount_paid' => 'integer',
            'penalty_amount' => 'integer',
            'interest_amount' => 'integer',
            'generated_from_entry_ids' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (TaxDeclaration $declaration): void {
            if ($declaration->status !== DeclarationStatus::Draft) {
                throw new RuntimeException(sprintf(
                    'Declaration %s %04d-%02d is past draft; it is cancelled or amended, never deleted (03-tax-procurement §7.1).',
                    $declaration->declaration_type,
                    $declaration->period_year,
                    $declaration->period_month,
                ));
            }
        });
    }

    /**
     * @return HasMany<TaxDeclarationLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(TaxDeclarationLine::class)->orderBy('line_no');
    }

    /**
     * @return HasMany<TaxDeclarationEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(TaxDeclarationEntry::class);
    }

    /**
     * @return BelongsTo<TaxDeclarationType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(TaxDeclarationType::class, 'declaration_type', 'code');
    }

    /**
     * @return BelongsTo<TaxDeclaration, $this>
     */
    public function amends(): BelongsTo
    {
        return $this->belongsTo(self::class, 'amends_declaration_id');
    }
}
