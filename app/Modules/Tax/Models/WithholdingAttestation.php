<?php

declare(strict_types=1);

namespace App\Modules\Tax\Models;

use App\Modules\Tax\Domain\AttestationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * docs/specs/03-tax-procurement.md §6.6 - the attestation de retenue à la
 * source. Without it the supplier cannot credit the withholding against
 * their own tax, and the school's withholding - however correctly
 * remitted - is a de facto confiscation.
 *
 * Invariant 1 lives HERE: an `issued` attestation is IMMUTABLE. The
 * observer freezes the snapshotted amounts and identities once issued -
 * the ONLY moves allowed afterwards are the lifecycle ones (cancel,
 * replace, deliver, include in a declaration). Corrections issue a
 * REPLACEMENT (`replaced_by_attestation_id`, UNIQUE chain), never an edit.
 * Deletion never happens at all (§9, 10-year AUDCIF retention).
 *
 * @property int $id
 * @property string $attestation_no
 * @property int $supplier_id
 * @property int|null $supplier_payment_id
 * @property int|null $supplier_invoice_id
 * @property int $withholding_rule_id
 * @property int $period_month
 * @property int $period_year
 * @property int $base_amount
 * @property int $rate_bp_applied
 * @property int $withheld_amount
 * @property int|null $tax_declaration_id
 * @property AttestationStatus $status
 * @property Carbon|null $issued_at
 * @property int|null $issued_by
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by
 * @property string|null $cancellation_reason
 * @property int|null $replaced_by_attestation_id
 * @property string|null $document_hash
 * @property Carbon|null $delivered_at
 * @property string|null $delivery_method
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class WithholdingAttestation extends Model
{
    /**
     * The lifecycle columns that may still move once ISSUED - §6.6
     * invariant 1's exhaustive list. Everything else (amounts, supplier,
     * rule, period, number) is frozen.
     */
    private const MUTABLE_AFTER_ISSUE = [
        'status',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'replaced_by_attestation_id',
        'tax_declaration_id',
        'document_hash',
        'delivered_at',
        'delivery_method',
        'updated_at',
    ];

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AttestationStatus::class,
            'period_month' => 'integer',
            'period_year' => 'integer',
            'base_amount' => 'integer',
            'rate_bp_applied' => 'integer',
            'withheld_amount' => 'integer',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (WithholdingAttestation $attestation): void {
            /** @var string|AttestationStatus|null $original */
            $original = $attestation->getOriginal('status');
            $originalStatus = $original instanceof AttestationStatus
                ? $original
                : AttestationStatus::from((string) $original);

            if ($originalStatus === AttestationStatus::Draft) {
                return;
            }

            foreach (array_keys($attestation->getDirty()) as $column) {
                if (! in_array($column, self::MUTABLE_AFTER_ISSUE, true)) {
                    throw new RuntimeException(sprintf(
                        'Attestation %s is issued and immutable; corrections issue a REPLACEMENT, '
                        .'never an in-place edit of [%s] (03-tax-procurement 6.6 invariant 1).',
                        (string) $attestation->getOriginal('attestation_no'),
                        $column,
                    ));
                }
            }
        });

        static::deleting(function (WithholdingAttestation $attestation): void {
            throw new RuntimeException(sprintf(
                'Attestation %s is a tax document under 10-year retention; it is cancelled or replaced, never deleted (03-tax-procurement 9).',
                $attestation->attestation_no,
            ));
        });
    }

    /**
     * @return BelongsTo<WithholdingRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(WithholdingRule::class, 'withholding_rule_id');
    }

    /**
     * @return BelongsTo<WithholdingAttestation, $this>
     */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_attestation_id');
    }
}
