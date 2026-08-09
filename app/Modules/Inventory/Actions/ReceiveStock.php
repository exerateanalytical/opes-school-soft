<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Accounting\Actions\PostFromEvent;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Assets\Actions\CapitaliseAsset;
use App\Modules\Assets\Actions\RegisterAsset;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Inventory\Actions\Concerns\MovesStock;
use App\Modules\Inventory\Domain\InventoryPermission;
use App\Modules\Inventory\Domain\ItemStatus;
use App\Modules\Inventory\Domain\ItemType;
use App\Modules\Inventory\Domain\StockMovementType;
use App\Modules\Inventory\Models\StockMovement;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/06-assets-stores.md §8.1/§8.2 - the entry INTO stock, the leg
 * v1 omitted entirely. The supplier's line total is authoritative
 * (`total_cost`, whole FCFA, incl. any non-recoverable VAT per the prorata
 * rule); the receipt moves the average because ONLY receipts move it (I1).
 *
 * Ledger: Dr stock (3x) / Cr variation (603x) via PostFromEvent
 * `inventory.received_into_stock` - 603x is CREDITED on inflow, the single
 * most commonly reversed sign in the module.
 *
 * §8.6 equipment: an `equipment` item carrying `asset_category_id` is a
 * CAPITALISATION HANDOFF, in the same transaction:
 *  - at/above the category's capitalisation_threshold, the stock-entry leg
 *    is REPLACED by the asset entry - RegisterAsset + CapitaliseAsset
 *    (Assets' doors; Dr 2xxx / Cr 481x via `asset.acquired`), NO
 *    StockMovement, tag from the sequence allocator;
 *  - below it, `below_threshold_behaviour` decides: `expense_only` posts
 *    Dr below-threshold expense / Cr 401 (rule-configured) with no asset
 *    and no stock; `expense_and_track` adds a DRAFT zero-cost Asset row
 *    for custody tracking only - it never appears in the fixed-asset note.
 *
 * Until Phase 5's goods-receipt flow connects, `document_ref` names the
 * manual source document.
 */
final class ReceiveStock
{
    use MovesStock;

    public function __construct(
        private readonly PostFromEvent $post,
        private readonly RegisterAsset $registerAsset,
        private readonly CapitaliseAsset $capitalise,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param array{
     *     item_id: int,
     *     store_location_id: int,
     *     quantity: string,
     *     total_cost: int,
     *     moved_on: string,
     *     fiscal_year_id: int,
     *     academic_year_id: int,
     *     document_ref?: string|null,
     *     supplier_id?: int|null,
     *     store_requisition_id?: int|null,
     *     idempotency_key?: string|null,
     * } $data
     * @return array{movement_id: int|null, asset_id: int|null, journal_entry_id: int|null}
     */
    public function handle(array $data, Actor $actor): array
    {
        Gate::authorize(InventoryPermission::MANAGE);

        $idempotencyKey = $data['idempotency_key'] ?? null;

        if ($idempotencyKey !== null) {
            /** @var StockMovement|null $existing */
            $existing = StockMovement::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                // Retry-safe: same key, same movement.
                return [
                    'movement_id' => (int) $existing->getKey(),
                    'asset_id' => null,
                    'journal_entry_id' => $existing->journal_entry_id,
                ];
            }
        }

        if ($data['total_cost'] < 0) {
            throw new DomainException('A receipt cost cannot be negative; use a reversal for corrections (I11).');
        }

        return DB::transaction(function () use ($data, $actor, $idempotencyKey): array {
            $item = $this->itemWithAccounts($data['item_id'], []);

            if ($item->status === ItemStatus::Discontinued->value) {
                throw new DomainException(
                    "Item '{$item->item_code}' is discontinued (I5): receipts are blocked; run the remaining stock down."
                );
            }

            if ($item->item_type === ItemType::Equipment->value && $item->asset_category_id !== null) {
                return $this->receiveAsAsset($item, $data, $actor, $idempotencyKey);
            }

            // Ordinary stock entry - now the I2 account gate applies.
            $item = $this->itemWithAccounts($data['item_id']);

            $this->lockLocation($data['store_location_id']);
            $balance = $this->lockBalance($item->id, $data['store_location_id']);

            $documentRef = $data['document_ref'] ?? sprintf('RCPT/%s/%s', $data['moved_on'], $item->item_code);

            $entry = $this->post->handle(
                PostingEvent::InventoryReceivedIntoStock->value,
                [
                    'movement' => [
                        'amount' => $data['total_cost'],
                        'reference' => $documentRef,
                        'purchase_account_id' => (int) $item->purchase_account_id,
                        'stock_account_id' => (int) $item->stock_account_id,
                        'variation_account_id' => (int) $item->variation_account_id,
                    ],
                ],
                $data['moved_on'],
                $actor,
                $documentRef,
            );

            $movement = $this->applyMovement(
                $balance,
                StockMovementType::Receipt,
                $data['quantity'],
                $data['total_cost'],
                $data['moved_on'],
                $data['fiscal_year_id'],
                $data['academic_year_id'],
                $actor,
                [
                    'journal_entry_id' => (int) $entry->getKey(),
                    'document_ref' => $documentRef,
                    'store_requisition_id' => $data['store_requisition_id'] ?? null,
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
                    'movement_type' => 'receipt',
                    'item_id' => $item->id,
                    'store_location_id' => $data['store_location_id'],
                    'quantity' => $data['quantity'],
                    'total_cost' => $data['total_cost'],
                ],
                $actor,
            );

            return [
                'movement_id' => (int) $movement->getKey(),
                'asset_id' => null,
                'journal_entry_id' => (int) $entry->getKey(),
            ];
        });
    }

    /**
     * The §8.6 handoff. NO StockMovement in any branch - equipment that
     * capitalises (or expenses) never enters the stock ledger.
     *
     * @param  object{id: int, item_code: string, name: string, asset_category_id: int|null}  $item
     * @param array{
     *     item_id: int,
     *     store_location_id: int,
     *     quantity: string,
     *     total_cost: int,
     *     moved_on: string,
     *     fiscal_year_id: int,
     *     academic_year_id: int,
     *     document_ref?: string|null,
     *     supplier_id?: int|null,
     *     idempotency_key?: string|null,
     * } $data
     * @return array{movement_id: int|null, asset_id: int|null, journal_entry_id: int|null}
     */
    private function receiveAsAsset(object $item, array $data, Actor $actor, ?string $idempotencyKey): array
    {
        $supplierId = $data['supplier_id'] ?? null;

        if ($supplierId === null) {
            // 481x / 401 are COLLECTIVE accounts: the credit side of the
            // §8.6 posting demands the supplier partner (02-accounting L8).
            throw new DomainException(
                "Item '{$item->item_code}' is an §8.6 equipment receipt: name the supplier (supplier_id) - the 481x/401 credit is a collective account and demands its partner."
            );
        }

        /** @var object{id: int|string, code: string, capitalisation_threshold: int|string, below_threshold_behaviour: string, below_threshold_expense_account_id: int|string|null}|null $category */
        $category = DB::table('asset_categories')
            ->where('id', $item->asset_category_id)
            ->first(['id', 'code', 'capitalisation_threshold', 'below_threshold_behaviour', 'below_threshold_expense_account_id']);

        if ($category === null) {
            throw new DomainException(
                "Item '{$item->item_code}' names asset category #{$item->asset_category_id}, which does not exist."
            );
        }

        $threshold = (int) $category->capitalisation_threshold;
        $documentRef = $data['document_ref'] ?? sprintf('RCPT/%s/%s', $data['moved_on'], $item->item_code);

        if ($threshold === 0 || $data['total_cost'] >= $threshold) {
            // Capitalise: the stock-entry leg is replaced by the asset
            // entry, posted by Assets' own door (asset.acquired).
            $asset = $this->registerAsset->handle([
                'asset_category_id' => (int) $category->id,
                'name' => sprintf('%s (%s)', $item->name, $documentRef),
                'acquisition_type' => 'purchase',
                'acquisition_cost' => $data['total_cost'],
                'acquisition_date' => $data['moved_on'],
                'supplier_id' => $supplierId,
                'fiscal_year_id' => $data['fiscal_year_id'],
                'academic_year_id' => $data['academic_year_id'],
                'notes' => sprintf('Equipment receipt of item %s via %s (06-assets-stores §8.6).', $item->item_code, $documentRef),
                'idempotency_key' => $idempotencyKey === null ? null : 'rcv-asset:'.$idempotencyKey,
            ], $actor);

            $asset = $this->capitalise->handle((int) $asset->getKey(), $actor);

            $this->audit->handle(
                AuditAction::Created,
                'inventory',
                null,
                null,
                null,
                [
                    'event' => 'equipment_receipt_capitalised',
                    'item_id' => $item->id,
                    'asset_id' => (int) $asset->getKey(),
                    'total_cost' => $data['total_cost'],
                ],
                $actor,
            );

            return [
                'movement_id' => null,
                'asset_id' => (int) $asset->getKey(),
                'journal_entry_id' => $asset->journal_entry_id,
            ];
        }

        // Below the threshold.
        if ($category->below_threshold_expense_account_id === null) {
            throw new DomainException(
                "Asset category '{$category->code}' has no below-threshold expense account configured; the accountant must configure it before below-threshold equipment can be received (00-core §16)."
            );
        }

        $entry = $this->post->handle(
            PostingEvent::AssetAcquired->value,
            [
                'asset' => [
                    'cost' => $data['total_cost'],
                    'accumulated_depreciation' => 0,
                    'net_book_value' => $data['total_cost'],
                    'proceeds' => 0,
                    'reference' => $documentRef,
                    'partner' => ['type' => 'supplier', 'id' => $supplierId],
                    'asset_account_id' => (int) $category->below_threshold_expense_account_id,
                ],
            ],
            $data['moved_on'],
            $actor,
            $documentRef,
        );

        $assetId = null;

        if ($category->below_threshold_behaviour === 'expense_and_track') {
            // A non-depreciating custody shell: zero cost, draft, tagged.
            // Explicitly NOT an off-balance-sheet asset; it never appears
            // in the fixed-asset note.
            $asset = $this->registerAsset->handle([
                'asset_category_id' => (int) $category->id,
                'name' => sprintf('%s (%s)', $item->name, $documentRef),
                'acquisition_type' => 'purchase',
                'acquisition_cost' => 0,
                'acquisition_date' => $data['moved_on'],
                'supplier_id' => $supplierId,
                'fiscal_year_id' => $data['fiscal_year_id'],
                'academic_year_id' => $data['academic_year_id'],
                'notes' => sprintf(
                    'Custody tracking only: expensed below the %d threshold (real cost %d, %s; 06-assets-stores §8.6).',
                    $threshold,
                    $data['total_cost'],
                    $documentRef,
                ),
                'idempotency_key' => $idempotencyKey === null ? null : 'rcv-track:'.$idempotencyKey,
            ], $actor);

            $assetId = (int) $asset->getKey();
        }

        $this->audit->handle(
            AuditAction::Created,
            'inventory',
            null,
            null,
            null,
            [
                'event' => 'equipment_receipt_below_threshold',
                'behaviour' => $category->below_threshold_behaviour,
                'item_id' => $item->id,
                'asset_id' => $assetId,
                'total_cost' => $data['total_cost'],
                'journal_entry_id' => (int) $entry->getKey(),
            ],
            $actor,
        );

        return [
            'movement_id' => null,
            'asset_id' => $assetId,
            'journal_entry_id' => (int) $entry->getKey(),
        ];
    }
}
