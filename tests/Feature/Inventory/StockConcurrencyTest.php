<?php

declare(strict_types=1);

use App\Modules\Inventory\Actions\ReserveStock;
use App\Modules\Inventory\Actions\TransferStock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/../Accounting/AccountingTestHelpers.php';
require_once __DIR__.'/InventoryTestHelpers.php';

// RefreshDatabase is deliberately NOT used: its per-test transaction would
// hide every row from the SECOND real connection these tests lock against
// (the same design as tests/Unit/Support/SequenceTest.php). Everything
// committed here is inert for the other suites - crucially NO journal
// entries and NO documents the RefreshDatabase files count; stock_movements
// are append-only (I11 triggers) so leftovers cannot be deleted and are
// left scoped to this file's own item/location ids. (tests/Pest.php already
// binds Tests\TestCase to the whole Feature suite - no uses() here, and
// deliberately NO RefreshDatabase.)

beforeEach(function (): void {
    if (! Schema::hasTable('stock_balances')) {
        Artisan::call('migrate', ['--force' => true]);
    }
});

afterEach(function (): void {
    // Restore the default lock-wait patience whatever happened.
    DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
    DB::purge('second');
});

if (! function_exists('phase9StockConcurrencyFixture')) {
    /**
     * A COMMITTED bin: item + location + a seeded balance row written
     * directly (no movements, no ledger), visible to a second connection.
     *
     * @return array{user: \App\Modules\Identity\Models\User, item_id: int, location_id: int}
     */
    function phase9StockConcurrencyFixture(string $quantity, int $value): array
    {
        $user = phase9StockUser();
        $item = phase9StockItem();
        $location = phase9StockLocationId();

        DB::table('stock_balances')->insert([
            'item_id' => (int) $item->getKey(),
            'store_location_id' => $location,
            'quantity_on_hand' => $quantity,
            'quantity_reserved' => '0.000',
            'value_on_hand' => $value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config(['database.connections.second' => config('database.connections.'.config('database.default'))]);

        return ['user' => $user, 'item_id' => (int) $item->getKey(), 'location_id' => $location];
    }
}

it('orders balance-row locks identically for opposite transfers (the §7.5 anti-deadlock rule)', function (): void {
    $aToB = TransferStock::balanceLockOrder([[7, 2], [7, 9], [3, 2], [3, 9]]);
    $bToA = TransferStock::balanceLockOrder([[3, 9], [3, 2], [7, 9], [7, 2]]);

    // Whatever order the caller assembles the pairs in, the lock sequence
    // is THE one sequence - so two opposite transfers can never hold one
    // row each and wait on the other's.
    expect($aToB)->toBe([[3, 2], [3, 9], [7, 2], [7, 9]])
        ->and($bToA)->toBe($aToB);
});

it('loses the last-unit race loudly: the balance row lock serialises, then I6 rejects (acceptance 7)', function (): void {
    $fixture = phase9StockConcurrencyFixture('1.000', 9_200);

    // A second real connection holds the §7.5 row lock - an in-flight
    // consumer of the last unit that has not yet committed.
    DB::connection('second')->beginTransaction();
    DB::connection('second')->table('stock_balances')
        ->where('item_id', $fixture['item_id'])
        ->where('store_location_id', $fixture['location_id'])
        ->lockForUpdate()
        ->first();

    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

    try {
        // Our attempt must BLOCK on the same row (and here, time out) -
        // an unlocked read would have "succeeded" and oversold the unit.
        $blocked = false;

        try {
            app(ReserveStock::class)->handle([
                'item_id' => $fixture['item_id'],
                'store_location_id' => $fixture['location_id'],
                'quantity' => '1.000',
                'reserved_for_type' => 'exam',
                'reserved_for_id' => 1,
            ], phase9StockActor($fixture['user']));
        } catch (QueryException $e) {
            $blocked = str_contains($e->getMessage(), 'Lock wait timeout');
        }

        expect($blocked)->toBeTrue('the reservation did not block on the held balance row lock');
    } finally {
        DB::connection('second')->rollBack();
    }

    // The race's loser, replayed sequentially: the first consumer takes
    // the unit, the second is REJECTED (I6) - never permitted-and-warned.
    DB::statement('SET SESSION innodb_lock_wait_timeout = 50');

    app(ReserveStock::class)->handle([
        'item_id' => $fixture['item_id'],
        'store_location_id' => $fixture['location_id'],
        'quantity' => '1.000',
        'reserved_for_type' => 'exam',
        'reserved_for_id' => 2,
    ], phase9StockActor($fixture['user']));

    expect(fn () => app(ReserveStock::class)->handle([
        'item_id' => $fixture['item_id'],
        'store_location_id' => $fixture['location_id'],
        'quantity' => '1.000',
        'reserved_for_type' => 'exam',
        'reserved_for_id' => 3,
    ], phase9StockActor($fixture['user'])))->toThrow(DomainException::class, 'Cannot reserve');

    // And the schema itself is the last line of defence: driving the bin
    // negative raw trips the I6 CHECK.
    expect(fn (): int => DB::table('stock_balances')
        ->where('item_id', $fixture['item_id'])
        ->where('store_location_id', $fixture['location_id'])
        ->update(['quantity_on_hand' => '-1.000']))
        ->toThrow(QueryException::class);
});

it('cannot deadlock two opposite transfers: the ordered lock walk blocks cleanly and rolls back whole (acceptance 9)', function (): void {
    // No posting rule is configured ANYWHERE in this file (a committed
    // rule would leak into the RefreshDatabase suites): transfers post
    // nothing, which is itself part of what this test proves.
    $user = phase9StockUser();
    $calendar = ledgerCalendar('2031-03-15');

    $item = phase9StockItem();
    $itemId = (int) $item->getKey();
    $locationA = phase9StockLocationId(['name' => 'Store A']);
    $locationB = phase9StockLocationId(['name' => 'Store B']);

    foreach ([[$locationA, '10.000', 100_000], [$locationB, '10.000', 100_000]] as [$loc, $qty, $value]) {
        DB::table('stock_balances')->insert([
            'item_id' => $itemId,
            'store_location_id' => $loc,
            'quantity_on_hand' => $qty,
            'quantity_reserved' => '0.000',
            'value_on_hand' => $value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    config(['database.connections.second' => config('database.connections.'.config('database.default'))]);

    // The opposing in-flight transfer holds ONLY the first row of the
    // mandated ascending walk. If our transfer locked its rows in ad-hoc
    // from-then-to order, the B->A direction would grab the B row first
    // and the two would deadlock; the ordered walk instead blocks
    // IMMEDIATELY on the first row, holding nothing.
    [$firstPair] = TransferStock::balanceLockOrder([[$itemId, $locationA], [$itemId, $locationB]]);

    DB::connection('second')->beginTransaction();
    DB::connection('second')->table('stock_balances')
        ->where('item_id', $firstPair[0])
        ->where('store_location_id', $firstPair[1])
        ->lockForUpdate()
        ->first();

    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

    try {
        $blocked = false;

        try {
            app(TransferStock::class)->handle([
                'from_location_id' => $locationB,
                'to_location_id' => $locationA,
                'lines' => [['item_id' => $itemId, 'quantity' => '4.000']],
                'transferred_on' => '2031-03-15',
                'fiscal_year_id' => $calendar['fiscal_year_id'],
                'academic_year_id' => $calendar['academic_year_id'],
            ], phase9StockActor($user));
        } catch (QueryException $e) {
            $blocked = str_contains($e->getMessage(), 'Lock wait timeout');
        }

        expect($blocked)->toBeTrue('the transfer did not walk the locks in the mandated order');

        // Blocked-and-rolled-back means WHOLE: no header, no lines, no
        // movements, untouched balances.
        expect(DB::table('stock_transfers')->where('from_location_id', $locationB)->count())->toBe(0)
            ->and(DB::table('stock_movements')->where('item_id', $itemId)->count())->toBe(0)
            ->and(phase9StockBalance($itemId, $locationB)->quantity_on_hand)->toBe('10.000');
    } finally {
        DB::connection('second')->rollBack();
    }

    DB::statement('SET SESSION innodb_lock_wait_timeout = 50');

    // With the lock released, both directions complete sequentially - two
    // movements each at the sender's derived cost, no ledger event
    // (§7.9: same stock account posts nothing).
    $transfer1 = app(TransferStock::class)->handle([
        'from_location_id' => $locationA,
        'to_location_id' => $locationB,
        'lines' => [['item_id' => $itemId, 'quantity' => '4.000']],
        'transferred_on' => '2031-03-15',
        'fiscal_year_id' => $calendar['fiscal_year_id'],
        'academic_year_id' => $calendar['academic_year_id'],
    ], phase9StockActor($user));

    $transfer2 = app(TransferStock::class)->handle([
        'from_location_id' => $locationB,
        'to_location_id' => $locationA,
        'lines' => [['item_id' => $itemId, 'quantity' => '4.000']],
        'transferred_on' => '2031-03-15',
        'fiscal_year_id' => $calendar['fiscal_year_id'],
        'academic_year_id' => $calendar['academic_year_id'],
    ], phase9StockActor($user));

    expect($transfer1->journal_entry_id)->toBeNull()
        ->and($transfer2->journal_entry_id)->toBeNull()
        ->and(DB::table('stock_movements')->where('item_id', $itemId)->count())->toBe(4)
        ->and(phase9StockBalance($itemId, $locationA)->quantity_on_hand)->toBe('10.000')
        ->and(phase9StockBalance($itemId, $locationA)->value_on_hand)->toBe(100_000)
        ->and(phase9StockBalance($itemId, $locationB)->quantity_on_hand)->toBe('10.000');
});

it('issues under a held location freeze flag are also serialised by the location row lock', function (): void {
    // The §7.10 counting flag is only trustworthy if every movement reads
    // it UNDER the location row lock - so a mover must block while
    // StartStockTake (holding the location row) is mid-flight.
    $fixture = phase9StockConcurrencyFixture('5.000', 50_000);

    DB::connection('second')->beginTransaction();
    DB::connection('second')->table('store_locations')
        ->where('id', $fixture['location_id'])
        ->lockForUpdate()
        ->first();

    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

    try {
        $blocked = false;

        try {
            app(ReserveStock::class)->handle([
                'item_id' => $fixture['item_id'],
                'store_location_id' => $fixture['location_id'],
                'quantity' => '1.000',
                'reserved_for_type' => 'exam',
                'reserved_for_id' => 9,
            ], phase9StockActor($fixture['user']));
        } catch (QueryException $e) {
            $blocked = str_contains($e->getMessage(), 'Lock wait timeout');
        }

        expect($blocked)->toBeTrue('the movement did not serialise on the location row lock');
    } finally {
        DB::connection('second')->rollBack();
    }
});
