<?php

declare(strict_types=1);

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Inventory\Actions\IssueStock;
use App\Modules\Inventory\Actions\ReverseStockMovement;
use App\Modules\Inventory\Domain\StockMovementType;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Accounting/AccountingTestHelpers.php';
require_once __DIR__.'/InventoryTestHelpers.php';

uses(RefreshDatabase::class);

it('rejects UPDATE and DELETE on stock_movements at the engine level (I11 triggers)', function (): void {
    $user = phase9StockUser();
    phase9StockReceiptRule($user);
    $calendar = ledgerCalendar('2031-03-15');

    $item = phase9StockItem();
    $location = phase9StockLocationId();
    $movement = phase9StockReceive($user, $item, $location, '10.000', 100_000, $calendar);

    expect(fn (): int => DB::table('stock_movements')->where('id', $movement->getKey())->update(['total_cost' => 1]))
        ->toThrow(QueryException::class, 'append-only');

    expect(fn (): int => DB::table('stock_movements')->where('id', $movement->getKey())->delete())
        ->toThrow(QueryException::class, 'append-only');

    // Untouched.
    expect((int) DB::table('stock_movements')->where('id', $movement->getKey())->value('total_cost'))->toBe(100_000);
});

it('reverses a receipt with a compensating movement AND a ledger reversal through the one door', function (): void {
    $user = phase9StockUser();
    phase9StockReceiptRule($user);
    $calendar = ledgerCalendar('2031-03-15');

    $item = phase9StockItem();
    $location = phase9StockLocationId();
    $movement = phase9StockReceive($user, $item, $location, '10.000', 100_000, $calendar);

    $reversal = app(ReverseStockMovement::class)->handle(
        (int) $movement->getKey(),
        'Receipt keyed against the wrong document',
        phase9StockActor($user),
    );

    expect($reversal->movement_type)->toBe(StockMovementType::ReturnOut)
        ->and($reversal->quantity)->toBe('-10.000')
        ->and($reversal->total_cost)->toBe(-100_000)
        ->and($reversal->reversal_of_movement_id)->toBe((int) $movement->getKey())
        ->and($reversal->journal_entry_id)->not->toBeNull()
        ->and($reversal->journal_entry_id)->not->toBe($movement->journal_entry_id);

    // The bin returned to zero-zero (I8) and the GL got a REVERSING entry,
    // not an edit of the original (C9 mirror).
    $balance = phase9StockBalance((int) $item->getKey(), $location);
    expect($balance->quantity_on_hand)->toBe('0.000')
        ->and($balance->value_on_hand)->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(2);
});

it('never reverses twice, and never reverses a reversal (I11)', function (): void {
    $user = phase9StockUser();
    phase9StockReceiptRule($user);
    $calendar = ledgerCalendar('2031-03-15');

    $item = phase9StockItem();
    $location = phase9StockLocationId();
    $movement = phase9StockReceive($user, $item, $location, '10.000', 100_000, $calendar);

    $reversal = app(ReverseStockMovement::class)->handle(
        (int) $movement->getKey(),
        'Receipt keyed against the wrong document',
        phase9StockActor($user),
    );

    expect(fn (): StockMovement => app(ReverseStockMovement::class)->handle(
        (int) $movement->getKey(),
        'Trying to reverse it again',
        phase9StockActor($user),
    ))->toThrow(DomainException::class, 'already reversed');

    expect(fn (): StockMovement => app(ReverseStockMovement::class)->handle(
        (int) $reversal->getKey(),
        'Trying to reverse the reversal',
        phase9StockActor($user),
    ))->toThrow(DomainException::class, 'never itself reversed');
});

it('backstops the reversed-at-most-once rule with the DB UNIQUE on reversal_of_movement_id', function (): void {
    $user = phase9StockUser();
    phase9StockReceiptRule($user);
    $calendar = ledgerCalendar('2031-03-15');

    $item = phase9StockItem();
    $location = phase9StockLocationId();
    $movement = phase9StockReceive($user, $item, $location, '10.000', 100_000, $calendar);
    phase9StockReceive($user, $item, $location, '10.000', 100_000, $calendar);

    app(ReverseStockMovement::class)->handle(
        (int) $movement->getKey(),
        'Receipt keyed against the wrong document',
        phase9StockActor($user),
    );

    // A raw second compensator against the same original: UNIQUE rejects.
    expect(fn (): bool => DB::table('stock_movements')->insert([
        'movement_type' => 'return_out',
        'item_id' => (int) $item->getKey(),
        'store_location_id' => $location,
        'quantity' => '-10.000',
        'unit_cost' => 10_000,
        'total_cost' => -100_000,
        'balance_qty_after' => '0.000',
        'balance_value_after' => 0,
        'moved_on' => '2031-03-15',
        'fiscal_year_id' => $calendar['fiscal_year_id'],
        'academic_year_id' => $calendar['academic_year_id'],
        'performed_by' => $user->getKey(),
        'reversal_of_movement_id' => (int) $movement->getKey(),
        'created_at' => now(),
    ]))->toThrow(QueryException::class, 'uq_stock_movements_reversal');
});

it('refuses to reverse when the bin has since been consumed (I6 guards the compensator too)', function (): void {
    $user = phase9StockUser();
    phase9StockReceiptRule($user);
    phase9StockIssueRule($user);
    $calendar = ledgerCalendar('2031-03-15');

    $item = phase9StockItem();
    $location = phase9StockLocationId();
    $movement = phase9StockReceive($user, $item, $location, '10.000', 100_000, $calendar);

    app(IssueStock::class)->handle([
        'store_location_id' => $location,
        'lines' => [['item_id' => (int) $item->getKey(), 'quantity' => '6.000']],
        'issued_on' => '2031-03-16',
        'fiscal_year_id' => $calendar['fiscal_year_id'],
        'academic_year_id' => $calendar['academic_year_id'],
    ], phase9StockActor($user));

    app(ReverseStockMovement::class)->handle(
        (int) $movement->getKey(),
        'Receipt keyed against the wrong document',
        phase9StockActor($user),
    );
})->throws(DomainException::class, 'Insufficient stock');

it('refuses to reverse one movement of a shared journal entry: reverse the document, not a line', function (): void {
    $user = phase9StockUser();
    phase9StockReceiptRule($user);
    phase9StockIssueRule($user);
    $calendar = ledgerCalendar('2031-03-15');

    $category = phase9StockCategoryId();
    $itemA = phase9StockItem(['item_category_id' => $category]);
    $itemB = phase9StockItem(['item_category_id' => $category]);
    $location = phase9StockLocationId();

    phase9StockReceive($user, $itemA, $location, '10.000', 100_000, $calendar);
    phase9StockReceive($user, $itemB, $location, '10.000', 200_000, $calendar);

    // A two-line issue: ONE journal entry per header (§7.8).
    $issue = app(IssueStock::class)->handle([
        'store_location_id' => $location,
        'lines' => [
            ['item_id' => (int) $itemA->getKey(), 'quantity' => '2.000'],
            ['item_id' => (int) $itemB->getKey(), 'quantity' => '3.000'],
        ],
        'issued_on' => '2031-03-16',
        'fiscal_year_id' => $calendar['fiscal_year_id'],
        'academic_year_id' => $calendar['academic_year_id'],
    ], phase9StockActor($user));

    /** @var StockMovement $lineMovement */
    $lineMovement = StockMovement::query()
        ->where('reference_type', 'StockIssue')
        ->where('reference_id', $issue->getKey())
        ->where('item_id', $itemA->getKey())
        ->firstOrFail();

    app(ReverseStockMovement::class)->handle(
        (int) $lineMovement->getKey(),
        'Trying to reverse one line of a header entry',
        phase9StockActor($user),
    );
})->throws(DomainException::class, 'shares its journal entry');
