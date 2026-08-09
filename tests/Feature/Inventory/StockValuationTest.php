<?php

declare(strict_types=1);

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Inventory\Actions\IssueStock;
use App\Modules\Inventory\Domain\WeightedAverageCost;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Accounting/AccountingTestHelpers.php';
require_once __DIR__.'/InventoryTestHelpers.php';

uses(RefreshDatabase::class);

it('replays the §8.1-8.3 golden figures: two receipts move the average, the issue costs 758,355', function (): void {
    $user = phase9StockUser();
    phase9StockReceiptRule($user);
    phase9StockIssueRule($user);
    $calendar = ledgerCalendar('2031-03-15');

    $item = phase9StockItem();
    $location = phase9StockLocationId();

    // §8.1 - 100 boxes, supplier line total 2,921,625 (TVA non-recoverable
    // included; the money is the document's, never recomputed).
    $receipt1 = phase9StockReceive($user, $item, $location, '100.000', 2_921_625, $calendar);

    $balance = phase9StockBalance((int) $item->getKey(), $location);
    expect($balance->quantity_on_hand)->toBe('100.000')
        ->and($balance->value_on_hand)->toBe(2_921_625)
        ->and($receipt1->balance_qty_after)->toBe('100.000')
        ->and($receipt1->balance_value_after)->toBe(2_921_625);

    // The entry INTO stock - the leg v1 omitted. Dr 33 / Cr 6033: 603x is
    // CREDITED on inflow (the most commonly reversed sign in the module).
    expect($receipt1->journal_entry_id)->not->toBeNull();
    [$code1, $debit1, $credit1] = phase9StockEntryLine((int) $receipt1->journal_entry_id, 1);
    [$code2, $debit2, $credit2] = phase9StockEntryLine((int) $receipt1->journal_entry_id, 2);
    expect([$code1, $debit1, $credit1])->toBe(['33', 2_921_625, 0])
        ->and([$code2, $debit2, $credit2])->toBe(['6033', 0, 2_921_625]);

    // §8.2 - 60 boxes at a different price: 1,931,850. The average MOVES,
    // nothing is rounded, there is no drift to accumulate.
    phase9StockReceive($user, $item, $location, '60.000', 1_931_850, $calendar, '2031-03-16');

    $balance = phase9StockBalance((int) $item->getKey(), $location);
    expect($balance->quantity_on_hand)->toBe('160.000')
        ->and($balance->value_on_hand)->toBe(4_853_475);

    // §8.3 - issue 25 boxes: round_half_up(25 x 4,853,475 / 160) = 758,355.
    $issue = app(IssueStock::class)->handle([
        'store_location_id' => $location,
        'lines' => [['item_id' => (int) $item->getKey(), 'quantity' => '25.000']],
        'issued_on' => '2031-03-17',
        'fiscal_year_id' => $calendar['fiscal_year_id'],
        'academic_year_id' => $calendar['academic_year_id'],
    ], phase9StockActor($user));

    /** @var StockMovement $movement */
    $movement = StockMovement::query()
        ->where('reference_type', 'StockIssue')
        ->where('reference_id', $issue->getKey())
        ->firstOrFail();

    expect($movement->quantity)->toBe('-25.000')
        ->and($movement->total_cost)->toBe(-758_355)
        ->and($movement->unit_cost)->toBe(30_334)
        ->and($movement->balance_qty_after)->toBe('135.000')
        ->and($movement->balance_value_after)->toBe(4_095_120);

    // The issue posts Dr 6033 / Cr 33 - 603x DEBITED on outflow.
    expect($issue->journal_entry_id)->not->toBeNull();
    [$codeA, $debitA, $creditA] = phase9StockEntryLine((int) $issue->journal_entry_id, 1);
    [$codeB, $debitB, $creditB] = phase9StockEntryLine((int) $issue->journal_entry_id, 2);
    expect([$codeA, $debitA, $creditA])->toBe(['6033', 758_355, 0])
        ->and([$codeB, $debitB, $creditB])->toBe(['33', 0, 758_355]);

    /** @var JournalEntry $entry */
    $entry = JournalEntry::query()->findOrFail($issue->journal_entry_id);
    expect($entry->status)->toBe(JournalEntry::STATUS_POSTED)
        ->and($entry->total_debit)->toBe(758_355);
});

it('empties the bin exactly (acceptance 8): the last issue absorbs the residual value and I8 holds', function (): void {
    $user = phase9StockUser();
    phase9StockReceiptRule($user);
    phase9StockIssueRule($user);
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

    // Empty the bin: issue_qty == quantity_on_hand, so issue_cost =
    // value_on_hand EXACTLY - not round(135 x v/135).
    $issue = app(IssueStock::class)->handle([
        'store_location_id' => $location,
        'lines' => [['item_id' => (int) $item->getKey(), 'quantity' => '135.000']],
        'issued_on' => '2031-03-18',
        'fiscal_year_id' => $calendar['fiscal_year_id'],
        'academic_year_id' => $calendar['academic_year_id'],
    ], phase9StockActor($user));

    /** @var object{issue_cost: int|string} $line */
    $line = DB::table('stock_issue_lines')->where('stock_issue_id', $issue->getKey())->first(['issue_cost']);
    expect((int) $line->issue_cost)->toBe(4_095_120);

    $balance = phase9StockBalance((int) $item->getKey(), $location);
    expect($balance->quantity_on_hand)->toBe('0.000')
        ->and($balance->value_on_hand)->toBe(0);
});

it('rejects an issue beyond the bin (I6) and leaves nothing half-posted', function (): void {
    $user = phase9StockUser();
    phase9StockReceiptRule($user);
    phase9StockIssueRule($user);
    $calendar = ledgerCalendar('2031-03-15');

    $item = phase9StockItem();
    $location = phase9StockLocationId();

    phase9StockReceive($user, $item, $location, '10.000', 100_000, $calendar);

    $entriesBefore = JournalEntry::query()->count();

    expect(fn () => app(IssueStock::class)->handle([
        'store_location_id' => $location,
        'lines' => [['item_id' => (int) $item->getKey(), 'quantity' => '10.001']],
        'issued_on' => '2031-03-17',
        'fiscal_year_id' => $calendar['fiscal_year_id'],
        'academic_year_id' => $calendar['academic_year_id'],
    ], phase9StockActor($user)))->toThrow(DomainException::class, 'Insufficient stock');

    $balance = phase9StockBalance((int) $item->getKey(), $location);
    expect($balance->quantity_on_hand)->toBe('10.000')
        ->and($balance->value_on_hand)->toBe(100_000)
        ->and(JournalEntry::query()->count())->toBe($entriesBefore)
        ->and(DB::table('stock_issues')->count())->toBe(0);
});

it('refuses to move an item whose category accounts are unconfigured, naming the missing account (I2)', function (): void {
    $user = phase9StockUser();
    phase9StockReceiptRule($user);
    $calendar = ledgerCalendar('2031-03-15');

    $item = phase9StockItem([
        'item_category_id' => phase9StockCategoryId(['stock_account_id' => null]),
    ]);
    $location = phase9StockLocationId();

    phase9StockReceive($user, $item, $location, '10.000', 100_000, $calendar);
})->throws(DomainException::class, 'no configured stock account');

it('computes the WeightedAverageCost domain goldens as pure scalars', function (): void {
    // §8.3.
    expect(WeightedAverageCost::issueCost('25.000', '160.000', 4_853_475))->toBe(758_355)
        // Empty-bin override - exact, not round(q x v / q).
        ->and(WeightedAverageCost::issueCost('135.000', '135.000', 4_095_120))->toBe(4_095_120)
        // §8.4 shortage of 3 at the frozen 135 / 4,095,120 position.
        ->and(WeightedAverageCost::varianceValue('-3.000', '135.000', 4_095_120))->toBe(-91_003)
        // Whole-position shortage takes the whole value.
        ->and(WeightedAverageCost::varianceValue('-135.000', '135.000', 4_095_120))->toBe(-4_095_120)
        // Overage priced at the derived cost.
        ->and(WeightedAverageCost::varianceValue('3.000', '135.000', 4_095_120))->toBe(91_003)
        // No cost basis on an empty system position.
        ->and(WeightedAverageCost::varianceValue('5.000', '0.000', 0))->toBe(0);

    expect(fn (): int => WeightedAverageCost::issueCost('11.000', '10.000', 100))
        ->toThrow(DomainException::class);
});

it('lets no Action read items.weighted_avg_cost (§7.1: display-only, never a posting input)', function (): void {
    $actionsDir = dirname(__DIR__, 3).'/app/Modules/Inventory/Actions';
    $offenders = [];

    /** @var iterable<string, SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($actionsDir));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        // The single sanctioned appearance is the display-mirror WRITER in
        // the MovesStock concern; everywhere else the column may not appear.
        if ($file->getFilename() === 'MovesStock.php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (str_contains($source, 'weighted_avg_cost')) {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBe([]);
});
