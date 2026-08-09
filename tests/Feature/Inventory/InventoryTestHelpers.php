<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\SavePostingRule;
use App\Modules\Accounting\Domain\AccountSource;
use App\Modules\Accounting\Domain\LineSign;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Actions\ReceiveStock;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/**
 * Shared fixtures for the P9-F3 inventory suites. Prefix phase9Stock,
 * every helper function_exists-guarded (globally unique names; Pest loads
 * helper files once per suite but these names must never collide with
 * another agent's).
 *
 * The inventory.* permissions are granted DIRECTLY (Spatie rows), not
 * through RolePermissionSeeder: the Phase 9 wiring package (F5) owns the
 * Permission enum cases and role mapping, while these suites exercise the
 * Actions' own gates.
 */
if (! function_exists('phase9StockUser')) {
    /**
     * A signed-in user holding the given abilities (defaults to the full
     * inventory + ledger set).
     *
     * @param  list<string>|null  $permissions
     */
    function phase9StockUser(?array $permissions = null): User
    {
        $permissions ??= [
            'inventory.view', 'inventory.manage', 'inventory.post',
            'ledger.view', 'ledger.post', 'ledger.configure',
            'fee.collect', 'asset.manage',
        ];

        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            \Spatie\Permission\Models\Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        $user = $user->fresh() ?? $user;
        actingAs($user);

        return $user;
    }
}

if (! function_exists('phase9StockActor')) {
    function phase9StockActor(User $user): Actor
    {
        return $user->toAuditActor();
    }
}

if (! function_exists('phase9StockAccountId')) {
    /** A statutory account id from the seeded chart (31/33/6031/6033/601/604/701/4111/481/401...). */
    function phase9StockAccountId(string $code): int
    {
        $id = DB::table('chart_of_accounts')->where('code', $code)->value('id');

        if ($id === null) {
            throw new RuntimeException("Account {$code} is not seeded.");
        }

        return (int) $id;
    }
}

if (! function_exists('phase9StockUnitId')) {
    function phase9StockUnitId(string $code = 'BOX'): int
    {
        $existing = DB::table('units_of_measure')->where('code', $code)->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('units_of_measure')->insertGetId([
            'code' => $code,
            'name' => $code,
            'name_fr' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('phase9StockCategoryId')) {
    /**
     * An Office Supplies-shaped category: 604 / 33 / 6033 (§8.1). Override
     * accounts for the merchandise (601/31/6031/701) shape.
     *
     * @param  array<string, mixed>  $overrides
     */
    function phase9StockCategoryId(array $overrides = []): int
    {
        return (int) DB::table('item_categories')->insertGetId($overrides + [
            'code' => 'CAT'.Str::upper(Str::random(6)),
            'name' => 'Office Supplies',
            'name_fr' => 'Fournitures de bureau',
            'purchase_account_id' => phase9StockAccountId('604'),
            'stock_account_id' => phase9StockAccountId('33'),
            'variation_account_id' => phase9StockAccountId('6033'),
            'sales_account_id' => null,
            'cost_of_sales_uses_variation' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('phase9StockItem')) {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function phase9StockItem(array $overrides = []): Item
    {
        /** @var Item $item */
        $item = Item::query()->create($overrides + [
            'item_code' => 'ITM'.Str::upper(Str::random(6)),
            'name' => 'A4 Copier Paper (Box)',
            'item_category_id' => phase9StockCategoryId(),
            'item_type' => 'consumable',
            'unit_of_measure_id' => phase9StockUnitId(),
            'is_stock_tracked' => true,
            'status' => 'active',
        ]);

        return $item;
    }
}

if (! function_exists('phase9StockLocationId')) {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function phase9StockLocationId(array $overrides = []): int
    {
        return (int) DB::table('store_locations')->insertGetId($overrides + [
            'code' => 'LOC'.Str::upper(Str::random(6)),
            'name' => 'Store C',
            'type' => 'store',
            'is_sellable_point' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('phase9StockBalance')) {
    /**
     * @return object{quantity_on_hand: string, quantity_reserved: string, value_on_hand: int}
     */
    function phase9StockBalance(int $itemId, int $locationId): object
    {
        /** @var object{quantity_on_hand: string, quantity_reserved: string, value_on_hand: int|string}|null $row */
        $row = DB::table('stock_balances')
            ->where('item_id', $itemId)
            ->where('store_location_id', $locationId)
            ->first(['quantity_on_hand', 'quantity_reserved', 'value_on_hand']);

        if ($row === null) {
            return (object) ['quantity_on_hand' => '0.000', 'quantity_reserved' => '0.000', 'value_on_hand' => 0];
        }

        return (object) [
            'quantity_on_hand' => $row->quantity_on_hand,
            'quantity_reserved' => $row->quantity_reserved,
            'value_on_hand' => (int) $row->value_on_hand,
        ];
    }
}

if (! function_exists('phase9StockRule')) {
    /**
     * One two-line payload-path rule for an inventory movement event.
     * $debitPath / $creditPath name payload account paths.
     */
    function phase9StockRule(
        User $configurer,
        PostingEvent $event,
        string $debitPath,
        string $creditPath,
        string $amount = 'movement.amount',
        ?string $condition = null,
        ?string $codeSuffix = null,
        int $priority = 100,
    ): void {
        app(SavePostingRule::class)->handle([
            'code' => 'p9s_'.substr($event->value, -12).($codeSuffix ?? '').'_'.Str::lower(Str::random(4)),
            'event' => $event->value,
            'journal_id' => Journal::factory()->create()->id,
            'label_expression' => 'Stock {movement.reference}',
            'condition_expression' => $condition,
            'priority' => $priority,
            'is_active' => true,
            'effective_from' => '2030-01-01',
            'effective_to' => null,
        ], [
            [
                'sequence' => 1,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => $debitPath,
                'sign' => LineSign::Debit,
                'amount_expression' => $amount,
                'label_expression' => 'Stock {movement.reference}',
            ],
            [
                'sequence' => 2,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => $creditPath,
                'sign' => LineSign::Credit,
                'amount_expression' => $amount,
                'label_expression' => 'Stock {movement.reference}',
            ],
        ], $configurer->toAuditActor());
    }
}

if (! function_exists('phase9StockReceiptRule')) {
    /** §8.1: receipt = Dr stock (3x) / Cr variation (603x) - THE sign golden. */
    function phase9StockReceiptRule(User $configurer): void
    {
        phase9StockRule(
            $configurer,
            PostingEvent::InventoryReceivedIntoStock,
            'movement.stock_account_id',
            'movement.variation_account_id',
        );
    }
}

if (! function_exists('phase9StockIssueRule')) {
    /** §8.3: issue = Dr variation (603x) / Cr stock (3x). */
    function phase9StockIssueRule(User $configurer): void
    {
        phase9StockRule(
            $configurer,
            PostingEvent::InventoryIssued,
            'movement.variation_account_id',
            'movement.stock_account_id',
        );
    }
}

if (! function_exists('phase9StockSoldRule')) {
    /** §8.5 cost-of-sales leg: Dr 6031 / Cr 31. */
    function phase9StockSoldRule(User $configurer): void
    {
        phase9StockRule(
            $configurer,
            PostingEvent::InventorySold,
            'movement.variation_account_id',
            'movement.stock_account_id',
        );
    }
}

if (! function_exists('phase9StockVarianceRules')) {
    /**
     * §8.4: the payload amount is SIGNED (negative = shortage). Shortage:
     * Dr 603x / Cr 3x; overage the mirror - discriminated by condition.
     */
    function phase9StockVarianceRules(User $configurer): void
    {
        phase9StockRule(
            $configurer,
            PostingEvent::InventoryStocktakeVariance,
            'movement.variation_account_id',
            'movement.stock_account_id',
            'abs(movement.amount)',
            'movement.amount < 0',
            '_short',
        );

        // Mutually exclusive by condition; distinct priority because the
        // rule validator conservatively refuses same-priority overlaps.
        phase9StockRule(
            $configurer,
            PostingEvent::InventoryStocktakeVariance,
            'movement.stock_account_id',
            'movement.variation_account_id',
            'movement.amount',
            'movement.amount > 0',
            '_over',
            90,
        );
    }
}

if (! function_exists('phase9StockWrittenOffRule')) {
    /** Standalone negative adjustment: Dr 603x / Cr 3x (V16 unresolved, default). */
    function phase9StockWrittenOffRule(User $configurer): void
    {
        phase9StockRule(
            $configurer,
            PostingEvent::InventoryWrittenOff,
            'movement.variation_account_id',
            'movement.stock_account_id',
        );
    }
}

if (! function_exists('phase9StockReceive')) {
    /**
     * A receipt through the REAL door, returning the movement.
     *
     * @param  array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}  $calendar
     * @param  array<string, mixed>  $overrides
     */
    function phase9StockReceive(
        User $user,
        Item $item,
        int $locationId,
        string $quantity,
        int $totalCost,
        array $calendar,
        string $movedOn = '2031-03-15',
        array $overrides = [],
    ): StockMovement {
        $result = app(ReceiveStock::class)->handle($overrides + [
            'item_id' => (int) $item->getKey(),
            'store_location_id' => $locationId,
            'quantity' => $quantity,
            'total_cost' => $totalCost,
            'moved_on' => $movedOn,
            'fiscal_year_id' => $calendar['fiscal_year_id'],
            'academic_year_id' => $calendar['academic_year_id'],
        ], $user->toAuditActor());

        if ($result['movement_id'] === null) {
            throw new RuntimeException('Receipt produced no stock movement (equipment handoff?).');
        }

        /** @var StockMovement $movement */
        $movement = StockMovement::query()->findOrFail($result['movement_id']);

        return $movement;
    }
}

if (! function_exists('phase9StockEntryLine')) {
    /**
     * The [account code, debit, credit] shape of an entry line, by sequence.
     *
     * @return array{0: string, 1: int, 2: int}
     */
    function phase9StockEntryLine(int $entryId, int $sequence): array
    {
        $line = phase9StockEntryLineModel($entryId, $sequence);

        $code = (string) DB::table('chart_of_accounts')->where('id', $line->account_id)->value('code');

        return [$code, $line->debit, $line->credit];
    }
}

if (! function_exists('phase9StockEntryLineModel')) {
    function phase9StockEntryLineModel(int $entryId, int $sequence): \App\Modules\Accounting\Models\JournalEntryLine
    {
        /** @var \App\Modules\Accounting\Models\JournalEntryLine $line */
        $line = \App\Modules\Accounting\Models\JournalEntryLine::query()
            ->where('journal_entry_id', $entryId)
            ->where('sequence', $sequence)
            ->firstOrFail();

        return $line;
    }
}
