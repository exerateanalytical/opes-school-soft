<?php

declare(strict_types=1);

use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Actions\ApproveStockTake;
use App\Modules\Inventory\Actions\IssueStock;
use App\Modules\Inventory\Actions\PostStockTakeVariance;
use App\Modules\Inventory\Actions\RecordStockTakeCounts;
use App\Modules\Inventory\Actions\StartStockTake;
use App\Modules\Inventory\Domain\StockTakeStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/../Accounting/AccountingTestHelpers.php';
require_once __DIR__.'/InventoryTestHelpers.php';

uses(RefreshDatabase::class);

if (! function_exists('phase9StockTakeBaseline')) {
    /**
     * The §8.4 standing start: Store C at 135.000 / 4,095,120 after the
     * §8.1-8.3 walk, with every rule the take needs.
     *
     * @return array{user: User, item: Item, location: int, calendar: array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}}
     */
    function phase9StockTakeBaseline(): array
    {
        $user = phase9StockUser();
        phase9StockReceiptRule($user);
        phase9StockIssueRule($user);
        phase9StockVarianceRules($user);
        $calendar = ledgerCalendar('2031-03-15');

        $item = phase9StockItem();
        $location = phase9StockLocationId();

        phase9StockReceive($user, $item, $location, '100.000', 2_921_625, $calendar);
        phase9StockReceive($user, $item, $location, '60.000', 1_931_850, $calendar, '2031-03-16');

        app(IssueStock::class)->handle([
            'store_location_id' => $location,
            'lines' => [['item_id' => (int) $item->getKey(), 'quantity' => '25.000']],
            'issued_on' => '2031-03-17',
            'fiscal_year_id' => $calendar['fiscal_year_id'],
            'academic_year_id' => $calendar['academic_year_id'],
        ], phase9StockActor($user));

        return ['user' => $user, 'item' => $item, 'location' => $location, 'calendar' => $calendar];
    }
}

it('freezes, counts, approves and posts the §8.4 shortage: Dr 6033 / Cr 33 for 91,003', function (): void {
    $fixture = phase9StockTakeBaseline();
    $counter = $fixture['user'];

    $take = app(StartStockTake::class)->handle([
        'store_location_id' => $fixture['location'],
        'count_date' => '2031-03-20',
        'fiscal_year_id' => $fixture['calendar']['fiscal_year_id'],
        'academic_year_id' => $fixture['calendar']['academic_year_id'],
    ], phase9StockActor($counter));

    // The frozen system position.
    expect($take->status)->toBe(StockTakeStatus::Counting);
    /** @var object{system_quantity: string, system_value: int|string} $frozen */
    $frozen = DB::table('stock_take_lines')->where('stock_take_id', $take->getKey())->first(['system_quantity', 'system_value']);
    expect($frozen->system_quantity)->toBe('135.000')
        ->and((int) $frozen->system_value)->toBe(4_095_120);

    // §7.10: movements at the location are BLOCKED while counting.
    expect(fn () => phase9StockReceive($counter, $fixture['item'], $fixture['location'], '5.000', 100_000, $fixture['calendar'], '2031-03-20'))
        ->toThrow(DomainException::class, 'frozen for stock take');

    // Physical count: 132 (shortage of 3).
    app(RecordStockTakeCounts::class)->handle((int) $take->getKey(), [
        (int) $fixture['item']->getKey() => ['counted_quantity' => '132.000', 'reason_code' => 'damage'],
    ], phase9StockActor($counter));

    /** @var object{variance_quantity: string, variance_value: int|string} $line */
    $line = DB::table('stock_take_lines')->where('stock_take_id', $take->getKey())->first(['variance_quantity', 'variance_value']);
    expect($line->variance_quantity)->toBe('-3.000')
        ->and((int) $line->variance_value)->toBe(-91_003);

    // Approval is a DIFFERENT hand.
    $approver = phase9StockUser();
    app(ApproveStockTake::class)->handle((int) $take->getKey(), phase9StockActor($approver));

    $posted = app(PostStockTakeVariance::class)->handle((int) $take->getKey(), phase9StockActor($approver));

    expect($posted->status)->toBe(StockTakeStatus::Posted)
        ->and($posted->journal_entry_id)->not->toBeNull();

    // §8.4 shortage entry: Dr 6033 / Cr 33 for 91,003.
    $lines = JournalEntryLine::query()
        ->where('journal_entry_id', $posted->journal_entry_id)
        ->orderBy('sequence')
        ->get()
        ->map(fn (JournalEntryLine $l): array => [
            (string) DB::table('chart_of_accounts')->where('id', $l->account_id)->value('code'),
            $l->debit,
            $l->credit,
        ])
        ->all();
    expect($lines)->toBe([['6033', 91_003, 0], ['33', 0, 91_003]]);

    // Balance landed at 132.000 / 4,004,117, the freeze lifted, and the
    // movement is an adjustment_out tied to the entry.
    $balance = phase9StockBalance((int) $fixture['item']->getKey(), $fixture['location']);
    expect($balance->quantity_on_hand)->toBe('132.000')
        ->and($balance->value_on_hand)->toBe(4_004_117)
        ->and(DB::table('store_locations')->where('id', $fixture['location'])->value('counting_stock_take_id'))->toBeNull();

    /** @var StockMovement $movement */
    $movement = StockMovement::query()->where('reference_type', 'StockTakeLine')->firstOrFail();
    expect($movement->quantity)->toBe('-3.000')
        ->and($movement->total_cost)->toBe(-91_003)
        ->and($movement->journal_entry_id)->toBe($posted->journal_entry_id);

    // The bin accepts movements again.
    phase9StockReceive($counter, $fixture['item'], $fixture['location'], '5.000', 100_000, $fixture['calendar'], '2031-03-21');
});

it('posts an overage as the mirror entry (Dr 33 / Cr 6033) at the derived cost', function (): void {
    $fixture = phase9StockTakeBaseline();
    $counter = $fixture['user'];

    $take = app(StartStockTake::class)->handle([
        'store_location_id' => $fixture['location'],
        'count_date' => '2031-03-20',
        'fiscal_year_id' => $fixture['calendar']['fiscal_year_id'],
        'academic_year_id' => $fixture['calendar']['academic_year_id'],
    ], phase9StockActor($counter));

    // Counted 140 against system 135: +5 at 4,095,120/135 => 151,671.11 -> 151,671.
    app(RecordStockTakeCounts::class)->handle((int) $take->getKey(), [
        (int) $fixture['item']->getKey() => ['counted_quantity' => '140.000', 'reason_code' => 'uncounted_delivery'],
    ], phase9StockActor($counter));

    $approver = phase9StockUser();
    app(ApproveStockTake::class)->handle((int) $take->getKey(), phase9StockActor($approver));
    $posted = app(PostStockTakeVariance::class)->handle((int) $take->getKey(), phase9StockActor($approver));

    $lines = JournalEntryLine::query()
        ->where('journal_entry_id', $posted->journal_entry_id)
        ->orderBy('sequence')
        ->get()
        ->map(fn (JournalEntryLine $l): array => [
            (string) DB::table('chart_of_accounts')->where('id', $l->account_id)->value('code'),
            $l->debit,
            $l->credit,
        ])
        ->all();
    expect($lines)->toBe([['33', 151_671, 0], ['6033', 0, 151_671]]);

    $balance = phase9StockBalance((int) $fixture['item']->getKey(), $fixture['location']);
    expect($balance->quantity_on_hand)->toBe('140.000')
        ->and($balance->value_on_hand)->toBe(4_095_120 + 151_671);
});

it('segregates approval: the hand that counted may not approve (§7.10)', function (): void {
    $fixture = phase9StockTakeBaseline();
    $counter = $fixture['user'];

    $take = app(StartStockTake::class)->handle([
        'store_location_id' => $fixture['location'],
        'count_date' => '2031-03-20',
        'fiscal_year_id' => $fixture['calendar']['fiscal_year_id'],
        'academic_year_id' => $fixture['calendar']['academic_year_id'],
    ], phase9StockActor($counter));

    app(RecordStockTakeCounts::class)->handle((int) $take->getKey(), [
        (int) $fixture['item']->getKey() => ['counted_quantity' => '132.000', 'reason_code' => 'damage'],
    ], phase9StockActor($counter));

    app(ApproveStockTake::class)->handle((int) $take->getKey(), phase9StockActor($counter));
})->throws(DomainException::class, 'cannot be approved by the hand that counted it');

it('demands a reason code on every variance line before approval', function (): void {
    $fixture = phase9StockTakeBaseline();
    $counter = $fixture['user'];

    $take = app(StartStockTake::class)->handle([
        'store_location_id' => $fixture['location'],
        'count_date' => '2031-03-20',
        'fiscal_year_id' => $fixture['calendar']['fiscal_year_id'],
        'academic_year_id' => $fixture['calendar']['academic_year_id'],
    ], phase9StockActor($counter));

    app(RecordStockTakeCounts::class)->handle((int) $take->getKey(), [
        (int) $fixture['item']->getKey() => ['counted_quantity' => '132.000'],
    ], phase9StockActor($counter));

    $approver = phase9StockUser();
    app(ApproveStockTake::class)->handle((int) $take->getKey(), phase9StockActor($approver));
})->throws(DomainException::class, 'without a reason code');

it('refuses a second concurrent take at a frozen location and enforces the status ladder', function (): void {
    $fixture = phase9StockTakeBaseline();
    $counter = $fixture['user'];

    $take = app(StartStockTake::class)->handle([
        'store_location_id' => $fixture['location'],
        'count_date' => '2031-03-20',
        'fiscal_year_id' => $fixture['calendar']['fiscal_year_id'],
        'academic_year_id' => $fixture['calendar']['academic_year_id'],
    ], phase9StockActor($counter));

    // Second take at the same location: blocked by the freeze flag.
    expect(fn (): StockTake => app(StartStockTake::class)->handle([
        'store_location_id' => $fixture['location'],
        'count_date' => '2031-03-20',
        'fiscal_year_id' => $fixture['calendar']['fiscal_year_id'],
        'academic_year_id' => $fixture['calendar']['academic_year_id'],
    ], phase9StockActor($counter)))->toThrow(DomainException::class, 'frozen for stock take');

    // Posting before approval refused.
    expect(fn (): StockTake => app(PostStockTakeVariance::class)->handle((int) $take->getKey(), phase9StockActor($counter)))
        ->toThrow(DomainException::class, 'only an approved take can post');

    // Approving before counting refused.
    $approver = phase9StockUser();
    expect(fn (): StockTake => app(ApproveStockTake::class)->handle((int) $take->getKey(), phase9StockActor($approver)))
        ->toThrow(DomainException::class, 'only a counted take can be approved');

    actingAs($counter);
});
