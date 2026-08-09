<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Accounting\Actions\PostFromEvent;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Fees\Actions\CreateSupplementaryInvoice;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Inventory\Actions\Concerns\MovesStock;
use App\Modules\Inventory\Domain\InventoryPermission;
use App\Modules\Inventory\Domain\ItemStatus;
use App\Modules\Inventory\Domain\ItemType;
use App\Modules\Inventory\Domain\StockMovementType;
use App\Modules\Inventory\Domain\WeightedAverageCost;
use App\Modules\Inventory\Models\StockMovement;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/06-assets-stores.md §8.5 - the sale path v1 never modelled
 * (the 2022 Finance Law made school commercial activity taxable).
 *
 * TWO legs, never one:
 *  - REVENUE: on credit, the receivable is 04-fees.md's Invoice - the
 *    sale joins the student's single debt stream (§10.7) through the
 *    Fees door `CreateSupplementaryInvoice` (Dr 4111 / Cr 701 via
 *    `fee.invoice.issued`). CASH sales are HARD-GATED: 571x Caisse is
 *    NEEDS VERIFICATION V13, so the Action refuses, naming the item.
 *    Taxed sales are likewise gated on V11 (443x TVA facturee).
 *  - COST OF SALES: a stock issue like any other - Dr 6031 / Cr 31 at the
 *    derived average via `inventory.sold`; gross margin is DERIVED for
 *    reporting, never posted.
 */
final class SellMerchandise
{
    use MovesStock;

    public function __construct(
        private readonly PostFromEvent $post,
        private readonly CreateSupplementaryInvoice $invoice,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param array{
     *     item_id: int,
     *     store_location_id: int,
     *     quantity: int,
     *     payment: 'credit'|'cash',
     *     sold_on: string,
     *     fiscal_year_id: int,
     *     academic_year_id: int,
     *     enrollment_id?: int|null,
     *     unit_price?: int|null,
     *     due_date?: string|null,
     *     idempotency_key?: string|null,
     * } $data
     * @return array{movement_id: int, cost_of_sales: int, cost_entry_id: int, invoice_id: int, invoice_no: string|null, revenue_entry_id: int|null, revenue: int}
     */
    public function handle(array $data, Actor $actor): array
    {
        Gate::authorize(InventoryPermission::POST);

        $idempotencyKey = $data['idempotency_key'] ?? null;

        if ($idempotencyKey !== null) {
            /** @var StockMovement|null $existing */
            $existing = StockMovement::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $this->replay($existing);
            }
        }

        if ($data['payment'] === 'cash') {
            throw new DomainException(
                'Cash merchandise sales are blocked: the 571x Caisse treasury subdivision is NEEDS VERIFICATION (06-assets-stores V13); the accountant must confirm it before cash sales can post. Sell on credit (student invoice) meanwhile.'
            );
        }

        if ($data['quantity'] < 1) {
            throw new DomainException('A merchandise sale needs a whole positive quantity.');
        }

        if (($data['enrollment_id'] ?? null) === null) {
            throw new DomainException('A credit merchandise sale needs the buying student\'s enrollment.');
        }

        return DB::transaction(function () use ($data, $actor, $idempotencyKey): array {
            $item = $this->itemWithAccounts($data['item_id'], ['stock', 'variation']);

            if ($item->item_type !== ItemType::Merchandise->value) {
                throw new DomainException(
                    "Item '{$item->item_code}' is {$item->item_type}, not merchandise; only merchandise sells (I3)."
                );
            }

            if ($item->sales_account_id === null) {
                throw new DomainException(
                    "Item category '{$item->category_code}' has no configured sales account; the accountant must configure it before item '{$item->item_code}' can sell (invariant I2/I3, 00-core §16)."
                );
            }

            if ($item->status === ItemStatus::Discontinued->value) {
                throw new DomainException(
                    "Item '{$item->item_code}' is discontinued (I5): sales are blocked."
                );
            }

            if ($item->sale_tax_code_id !== null) {
                throw new DomainException(
                    "Item '{$item->item_code}' carries a sale tax code, but the 443x TVA facturee subdivision is NEEDS VERIFICATION (06-assets-stores V11); taxed sales are blocked until the accountant confirms it."
                );
            }

            $unitPrice = $data['unit_price'] ?? $item->standard_sale_price;

            if ($unitPrice === null || $unitPrice <= 0) {
                throw new DomainException(
                    "Item '{$item->item_code}' has no standard sale price and none was given; a sale needs a price."
                );
            }

            $location = $this->lockLocation($data['store_location_id']);

            if (! $location->is_sellable_point) {
                throw new DomainException(
                    "Store location '{$location->code}' is not a sellable point; merchandise sells from sale points only."
                );
            }

            $balance = $this->lockBalance($item->id, $data['store_location_id']);
            $quantity = sprintf('%d.000', $data['quantity']);

            $available = WeightedAverageCost::subtract($balance->quantity_on_hand, $balance->quantity_reserved);

            if (WeightedAverageCost::compare($quantity, $available) > 0) {
                throw new DomainException(sprintf(
                    "Insufficient stock of '%s': %s on hand, %s reserved, %d requested (I6/I7).",
                    $item->item_code,
                    $balance->quantity_on_hand,
                    $balance->quantity_reserved,
                    $data['quantity'],
                ));
            }

            $cost = WeightedAverageCost::issueCost($quantity, $balance->quantity_on_hand, $balance->value_on_hand);
            $revenue = $unitPrice * $data['quantity'];

            // REVENUE leg - through the Fees door, single debt stream.
            $invoice = $this->invoice->handle([
                'enrollment_id' => (int) $data['enrollment_id'],
                'academic_year_id' => $data['academic_year_id'],
                'fiscal_year_id' => $data['fiscal_year_id'],
                'issue_date' => $data['sold_on'],
                'due_date' => $data['due_date'] ?? $data['sold_on'],
                'lines' => [
                    [
                        'description' => sprintf('%s x%d (%s)', $item->name, $data['quantity'], $item->item_code),
                        'revenue_account_id' => (int) $item->sales_account_id,
                        'amount' => $revenue,
                        'quantity' => $data['quantity'],
                        'unit_amount' => $unitPrice,
                    ],
                ],
                'idempotency_key' => $idempotencyKey === null ? null : 'sale:'.$idempotencyKey,
            ], $actor);

            // COST-OF-SALES leg - a stock issue like any other.
            $entry = $this->post->handle(
                PostingEvent::InventorySold->value,
                [
                    'movement' => [
                        'amount' => $cost,
                        'reference' => (string) ($invoice['invoice_no'] ?? $invoice['invoice_id']),
                        'stock_account_id' => (int) $item->stock_account_id,
                        'variation_account_id' => (int) $item->variation_account_id,
                    ],
                ],
                $data['sold_on'],
                $actor,
                $invoice['invoice_no'],
            );

            $movement = $this->applyMovement(
                $balance,
                StockMovementType::Sale,
                '-'.$quantity,
                -$cost,
                $data['sold_on'],
                $data['fiscal_year_id'],
                $data['academic_year_id'],
                $actor,
                [
                    'journal_entry_id' => (int) $entry->getKey(),
                    'reference_type' => 'Invoice',
                    'reference_id' => $invoice['invoice_id'],
                    'idempotency_key' => $idempotencyKey,
                ],
            );

            $this->audit->handle(
                AuditAction::Created,
                'inventory',
                StockMovement::class,
                (int) $movement->getKey(),
                null,
                [
                    'movement_type' => 'sale',
                    'item_id' => $item->id,
                    'quantity' => $data['quantity'],
                    'revenue' => $revenue,
                    'cost_of_sales' => $cost,
                    'invoice_id' => $invoice['invoice_id'],
                ],
                $actor,
            );

            return [
                'movement_id' => (int) $movement->getKey(),
                'cost_of_sales' => $cost,
                'cost_entry_id' => (int) $entry->getKey(),
                'invoice_id' => $invoice['invoice_id'],
                'invoice_no' => $invoice['invoice_no'],
                'revenue_entry_id' => $invoice['journal_entry_id'],
                'revenue' => $revenue,
            ];
        });
    }

    /**
     * Rebuild the result for an idempotent replay from the recorded
     * movement (its reference is the invoice).
     *
     * @return array{movement_id: int, cost_of_sales: int, cost_entry_id: int, invoice_id: int, invoice_no: string|null, revenue_entry_id: int|null, revenue: int}
     */
    private function replay(StockMovement $movement): array
    {
        /** @var object{id: int|string, invoice_no: string|null, journal_entry_id: int|string|null}|null $invoice */
        $invoice = DB::table('invoices')->where('id', $movement->reference_id)->first(['id', 'invoice_no', 'journal_entry_id']);

        $revenue = $movement->reference_id === null
            ? 0
            : (int) DB::table('invoice_lines')->where('invoice_id', $movement->reference_id)->sum('amount');

        return [
            'movement_id' => (int) $movement->getKey(),
            'cost_of_sales' => -$movement->total_cost,
            'cost_entry_id' => (int) $movement->journal_entry_id,
            'invoice_id' => $invoice === null ? 0 : (int) $invoice->id,
            'invoice_no' => $invoice?->invoice_no,
            'revenue_entry_id' => $invoice?->journal_entry_id === null ? null : (int) $invoice->journal_entry_id,
            'revenue' => $revenue,
        ];
    }
}
