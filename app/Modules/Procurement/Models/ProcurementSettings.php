<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use App\Modules\Procurement\Domain\BudgetEnforcement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * docs/specs/03-tax-procurement.md §4 - the procurement policy singleton
 * (CHECK id = 1 at the database).
 *
 * Every step except SupplierInvoice is optional-by-configuration: NULL
 * threshold = the step is never required, 0 = always required, N = required
 * above N FCFA. `current()` returns a DEFAULTS row (id unset, never saved)
 * when the school has not configured procurement yet - all steps optional,
 * zero tolerances, no budget enforcement - so the module works out of the
 * box without seeding policy the school never chose.
 *
 * @property int $id
 * @property int|null $requisition_required_above
 * @property int|null $po_required_above
 * @property bool $receipt_required_for_goods
 * @property int $over_receipt_tolerance_bp
 * @property int $price_tolerance_bp
 * @property int $price_tolerance_absolute
 * @property int $quantity_tolerance_bp
 * @property BudgetEnforcement $budget_enforcement
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ProcurementSettings extends Model
{
    public const SINGLETON_ID = 1;

    /** @var list<string> */
    protected $fillable = [
        'requisition_required_above',
        'po_required_above',
        'receipt_required_for_goods',
        'over_receipt_tolerance_bp',
        'price_tolerance_bp',
        'price_tolerance_absolute',
        'quantity_tolerance_bp',
        'budget_enforcement',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'receipt_required_for_goods' => 'boolean',
            'budget_enforcement' => BudgetEnforcement::class,
        ];
    }

    public static function current(): self
    {
        /** @var self|null $row */
        $row = self::query()->find(self::SINGLETON_ID);

        return $row ?? new self([
            'requisition_required_above' => null,
            'po_required_above' => null,
            'receipt_required_for_goods' => false,
            'over_receipt_tolerance_bp' => 0,
            'price_tolerance_bp' => 0,
            'price_tolerance_absolute' => 0,
            'quantity_tolerance_bp' => 0,
            'budget_enforcement' => BudgetEnforcement::None,
        ]);
    }
}
