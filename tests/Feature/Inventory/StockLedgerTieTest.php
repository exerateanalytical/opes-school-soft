<?php

declare(strict_types=1);

use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Inventory\Actions\IssueStock;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Accounting/AccountingTestHelpers.php';
require_once __DIR__.'/InventoryTestHelpers.php';

uses(RefreshDatabase::class);

/*
 * Acceptance 6 (06-assets-stores §13), reduced N for CI: after a seeded
 * random walk of receipts and issues, the stock account still ties -
 * balance == Σ movements == net Dr-Cr on account 33 - because the average
 * is recomputed from totals every time rather than carried forward as a
 * rounded scalar. One issue rounds by at most half a franc; the roundings
 * NEVER compound.
 */
it('keeps stock ledger, balance row and general ledger tied over a seeded random walk', function (): void {
    $user = phase9StockUser();
    phase9StockReceiptRule($user);
    phase9StockIssueRule($user);
    $calendar = ledgerCalendar('2031-03-15');

    $item = phase9StockItem();
    $location = phase9StockLocationId();
    $itemId = (int) $item->getKey();

    mt_srand(90317); // Deterministic: a failure replays exactly.

    $onHandMillis = 0;

    for ($i = 0; $i < 30; $i++) {
        $receiptTurn = $onHandMillis === 0 || mt_rand(0, 99) < 55;

        if ($receiptTurn) {
            $qtyMillis = mt_rand(1, 200) * 250;   // up to 50.000 in 0.250 steps
            $unit = mt_rand(500, 40_000);
            $cost = (int) round($qtyMillis * $unit / 1000);

            phase9StockReceive(
                $user,
                $item,
                $location,
                sprintf('%d.%03d', intdiv($qtyMillis, 1000), $qtyMillis % 1000),
                $cost,
                $calendar,
            );

            $onHandMillis += $qtyMillis;

            continue;
        }

        $qtyMillis = mt_rand(1, max(1, intdiv($onHandMillis, 250))) * 250;
        $qtyMillis = min($qtyMillis, $onHandMillis);

        app(IssueStock::class)->handle([
            'store_location_id' => $location,
            'lines' => [[
                'item_id' => $itemId,
                'quantity' => sprintf('%d.%03d', intdiv($qtyMillis, 1000), $qtyMillis % 1000),
            ]],
            'issued_on' => '2031-03-15',
            'fiscal_year_id' => $calendar['fiscal_year_id'],
            'academic_year_id' => $calendar['academic_year_id'],
        ], phase9StockActor($user));

        $onHandMillis -= $qtyMillis;
    }

    $balance = phase9StockBalance($itemId, $location);

    // 1. Balance quantity ties to the movement deltas.
    $movementQty = (string) StockMovement::query()
        ->where('item_id', $itemId)
        ->sum('quantity');
    expect(bccomp($balance->quantity_on_hand, $movementQty, 3))->toBe(0);

    // 2. Balance value ties to the movement money.
    $movementValue = (int) StockMovement::query()
        ->where('item_id', $itemId)
        ->sum('total_cost');
    expect($balance->value_on_hand)->toBe($movementValue);

    // 3. The general ledger ties: net Dr-Cr on 33 across every posted
    // entry equals the same figure - the property v1's missing stock leg
    // could never satisfy.
    $stockAccountId = phase9StockAccountId('33');
    $net = (int) JournalEntryLine::query()
        ->where('account_id', $stockAccountId)
        ->selectRaw('COALESCE(SUM(debit - credit), 0) as net')
        ->value('net');
    expect($net)->toBe($balance->value_on_hand);

    // 4. The printable stock ledger agrees: the LAST movement's snapshot
    // is the live balance (no replay needed to print as at any date).
    /** @var StockMovement $last */
    $last = StockMovement::query()
        ->where('item_id', $itemId)
        ->orderByDesc('id')
        ->firstOrFail();
    expect(bccomp($last->balance_qty_after, $balance->quantity_on_hand, 3))->toBe(0)
        ->and($last->balance_value_after)->toBe($balance->value_on_hand);

    // 5. I8 held throughout (the walk empties the bin whenever the random
    // issue consumes everything): whatever the final position, zero
    // quantity means zero value.
    if (bccomp($balance->quantity_on_hand, '0', 3) === 0) {
        expect($balance->value_on_hand)->toBe(0);
    }

    // And the invariants held at the database layer the whole time: any
    // I6-I9 violation would have raised a CHECK error mid-walk.
    expect(DB::table('stock_balances')->where('quantity_on_hand', '<', 0)->count())->toBe(0);
});
