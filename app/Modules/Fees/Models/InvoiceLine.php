<?php

declare(strict_types=1);

namespace App\Modules\Fees\Models;

use Database\Factories\InvoiceLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/specs/04-fees.md §3.2. Every money-bearing attribute is a SNAPSHOT
 * taken at issue - the fee item reference is carried for reporting only and
 * a later change to the item must never reclassify historical revenue.
 *
 * @property int $id
 * @property int $invoice_id
 * @property int $line_no
 * @property int|null $fee_item_id
 * @property string $description
 * @property string|null $description_fr
 * @property string|null $fee_category_code
 * @property string $collection_basis own_revenue|agent_for_third_party
 * @property int|null $third_party_fund_id
 * @property int|null $revenue_account_id
 * @property string $recognition_method on_issue|straight_line_over_period|on_collection
 * @property int|null $tax_code_id
 * @property int $quantity
 * @property int $unit_amount
 * @property int $amount
 * @property int $tax_amount
 * @property Carbon|null $service_period_start
 * @property Carbon|null $service_period_end
 * @property array<string, mixed>|null $analytic_values_json
 */
final class InvoiceLine extends Model
{
    /** @use HasFactory<InvoiceLineFactory> */
    use HasFactory;

    public const BASIS_OWN_REVENUE = 'own_revenue';

    public const BASIS_AGENT = 'agent_for_third_party';

    /** @var list<string> */
    protected $fillable = [
        'invoice_id',
        'line_no',
        'fee_item_id',
        'description',
        'description_fr',
        'fee_category_code',
        'collection_basis',
        'third_party_fund_id',
        'revenue_account_id',
        'recognition_method',
        'tax_code_id',
        'quantity',
        'unit_amount',
        'amount',
        'tax_amount',
        'service_period_start',
        'service_period_end',
        'analytic_values_json',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'service_period_start' => 'date',
            'service_period_end' => 'date',
            'analytic_values_json' => 'array',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    protected static function newFactory(): InvoiceLineFactory
    {
        return InvoiceLineFactory::new();
    }
}
