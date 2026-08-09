<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\SavePostingRule;
use App\Modules\Accounting\Domain\AccountSource;
use App\Modules\Accounting\Domain\LineSign;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Actions\ReceiveStock;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../Accounting/AccountingTestHelpers.php';
require_once __DIR__.'/InventoryTestHelpers.php';

uses(RefreshDatabase::class);

if (! function_exists('phase9StockAcquiredRules')) {
    /**
     * §8.6 asset.acquired rules, discriminated by cost against the
     * 1,000,000 test threshold: at/above capitalises (Cr 4812 Fournisseurs
     * d'investissements - capex never credits 401); below expenses
     * (Cr 401). Both school-configured; nothing ships seeded.
     */
    function phase9StockAcquiredRules(User $configurer): void
    {
        $save = app(SavePostingRule::class);

        // Mutually exclusive by condition; distinct priority because the
        // rule validator conservatively refuses same-priority overlaps.
        foreach ([
            ['p9s_acq_cap', 'asset.cost >= 1000000', '4812', 100],
            ['p9s_acq_exp', 'asset.cost < 1000000', '401', 90],
        ] as [$code, $condition, $creditCode, $priority]) {
            $save->handle([
                'code' => $code.'_'.Str::lower(Str::random(4)),
                'event' => PostingEvent::AssetAcquired->value,
                'journal_id' => Journal::factory()->create()->id,
                'label_expression' => 'Acquisition {asset.reference}',
                'condition_expression' => $condition,
                'priority' => $priority,
                'is_active' => true,
                'effective_from' => '2030-01-01',
                'effective_to' => null,
            ], [
                [
                    'sequence' => 1,
                    'account_source' => AccountSource::PayloadPath,
                    'account_path' => 'asset.asset_account_id',
                    'sign' => LineSign::Debit,
                    'amount_expression' => 'asset.cost',
                    'label_expression' => 'Acquisition {asset.reference}',
                ],
                [
                    'sequence' => 2,
                    'account_source' => AccountSource::Literal,
                    'account_code' => $creditCode,
                    'sign' => LineSign::Credit,
                    'amount_expression' => 'asset.cost',
                    'is_balancing' => true,
                    // 4812/401 are collective: the supplier partner rides.
                    'partner_source' => 'asset.partner',
                    'label_expression' => 'Contrepartie {asset.reference}',
                ],
            ], $configurer->toAuditActor());
        }
    }
}

if (! function_exists('phase9StockEquipmentFixture')) {
    /**
     * An equipment item (projector) linked to an IT asset category with a
     * 1,000,000 threshold, plus the supplier the 481x/401 credit demands.
     *
     * @param  array<string, mixed>  $categoryOverrides
     * @return array{user: User, item: \App\Modules\Inventory\Models\Item, location: int, asset_category: AssetCategory, supplier_id: int, calendar: array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}}
     */
    function phase9StockEquipmentFixture(array $categoryOverrides = []): array
    {
        $user = phase9StockUser();
        phase9StockAcquiredRules($user);
        phase9StockReceiptRule($user);
        $calendar = ledgerCalendar('2031-03-15');

        $supplierId = (int) DB::table('suppliers')->insertGetId([
            'code' => 'P9E'.fake()->unique()->numberBetween(1, 999_999),
            'name' => 'Equipements Scolaires '.fake()->unique()->numberBetween(1, 999_999),
            'supplier_type' => 'company',
            'payable_account_id' => phase9StockAccountId('401'),
            'created_by' => $user->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var AssetCategory $assetCategory */
        $assetCategory = AssetCategory::factory()->create($categoryOverrides + [
            'capitalisation_threshold' => 1_000_000,
            'below_threshold_behaviour' => 'expense_only',
            'below_threshold_expense_account_id' => phase9StockAccountId('604'),
        ]);

        $item = phase9StockItem([
            'name' => 'Video Projector',
            'item_type' => 'equipment',
            'asset_category_id' => (int) $assetCategory->getKey(),
        ]);

        return [
            'user' => $user,
            'item' => $item,
            'location' => phase9StockLocationId(),
            'asset_category' => $assetCategory,
            'supplier_id' => $supplierId,
            'calendar' => $calendar,
        ];
    }
}

it('capitalises an equipment receipt at/above the threshold: asset created, Dr 2442 / Cr 4812, NO stock movement (§8.6)', function (): void {
    $fixture = phase9StockEquipmentFixture();

    $result = app(ReceiveStock::class)->handle([
        'item_id' => (int) $fixture['item']->getKey(),
        'store_location_id' => $fixture['location'],
        'quantity' => '1.000',
        'total_cost' => 2_000_000,
        'moved_on' => '2031-03-15',
        'fiscal_year_id' => $fixture['calendar']['fiscal_year_id'],
        'academic_year_id' => $fixture['calendar']['academic_year_id'],
        'supplier_id' => $fixture['supplier_id'],
        'document_ref' => 'INV-EQ-001',
    ], phase9StockActor($fixture['user']));

    expect($result['movement_id'])->toBeNull()
        ->and($result['asset_id'])->not->toBeNull()
        ->and($result['journal_entry_id'])->not->toBeNull()
        // The stock-entry leg was REPLACED: no movement, no balance.
        ->and(StockMovement::query()->count())->toBe(0)
        ->and(phase9StockBalance((int) $fixture['item']->getKey(), $fixture['location'])->value_on_hand)->toBe(0);

    /** @var object{asset_category_id: int|string, acquisition_cost: int|string, status: string, tag_number: string} $asset */
    $asset = DB::table('assets')->where('id', $result['asset_id'])
        ->first(['asset_category_id', 'acquisition_cost', 'status', 'tag_number']);
    expect((int) $asset->asset_category_id)->toBe((int) $fixture['asset_category']->getKey())
        ->and((int) $asset->acquisition_cost)->toBe(2_000_000)
        ->and($asset->tag_number)->toMatch('/^AST\/\d{6}$/');

    $code = fn (JournalEntryLine $l): string => (string) DB::table('chart_of_accounts')->where('id', $l->account_id)->value('code');
    $lines = JournalEntryLine::query()
        ->where('journal_entry_id', $result['journal_entry_id'])
        ->orderBy('sequence')
        ->get();
    expect($code(assertNotNull($lines[0])))->toBe('2442')
        ->and(assertNotNull($lines[0])->debit)->toBe(2_000_000)
        ->and($code(assertNotNull($lines[1])))->toBe('4812')
        ->and(assertNotNull($lines[1])->credit)->toBe(2_000_000);
});

it('expenses a below-threshold equipment receipt (expense_only): Dr expense / Cr 401, no asset, no stock', function (): void {
    $fixture = phase9StockEquipmentFixture();

    $result = app(ReceiveStock::class)->handle([
        'item_id' => (int) $fixture['item']->getKey(),
        'store_location_id' => $fixture['location'],
        'quantity' => '1.000',
        'total_cost' => 300_000,
        'moved_on' => '2031-03-15',
        'fiscal_year_id' => $fixture['calendar']['fiscal_year_id'],
        'academic_year_id' => $fixture['calendar']['academic_year_id'],
        'supplier_id' => $fixture['supplier_id'],
    ], phase9StockActor($fixture['user']));

    expect($result['movement_id'])->toBeNull()
        ->and($result['asset_id'])->toBeNull()
        ->and(StockMovement::query()->count())->toBe(0)
        ->and(DB::table('assets')->count())->toBe(0);

    $code = fn (JournalEntryLine $l): string => (string) DB::table('chart_of_accounts')->where('id', $l->account_id)->value('code');
    $lines = JournalEntryLine::query()
        ->where('journal_entry_id', $result['journal_entry_id'])
        ->orderBy('sequence')
        ->get();
    expect($code(assertNotNull($lines[0])))->toBe('604')
        ->and(assertNotNull($lines[0])->debit)->toBe(300_000)
        ->and($code(assertNotNull($lines[1])))->toBe('401')
        ->and(assertNotNull($lines[1])->credit)->toBe(300_000);
});

it('expense_and_track adds a zero-cost draft custody shell alongside the expense posting', function (): void {
    $fixture = phase9StockEquipmentFixture(['below_threshold_behaviour' => 'expense_and_track']);

    $result = app(ReceiveStock::class)->handle([
        'item_id' => (int) $fixture['item']->getKey(),
        'store_location_id' => $fixture['location'],
        'quantity' => '1.000',
        'total_cost' => 300_000,
        'moved_on' => '2031-03-15',
        'fiscal_year_id' => $fixture['calendar']['fiscal_year_id'],
        'academic_year_id' => $fixture['calendar']['academic_year_id'],
        'supplier_id' => $fixture['supplier_id'],
    ], phase9StockActor($fixture['user']));

    expect($result['asset_id'])->not->toBeNull()
        ->and($result['movement_id'])->toBeNull();

    // Custody tracking only: zero cost, draft, tagged; the real spend went
    // to the expense account. Explicitly NOT an off-balance-sheet asset.
    /** @var object{acquisition_cost: int|string, status: string, notes: string|null} $asset */
    $asset = DB::table('assets')->where('id', $result['asset_id'])->first(['acquisition_cost', 'status', 'notes']);
    expect((int) $asset->acquisition_cost)->toBe(0)
        ->and($asset->status)->toBe('draft')
        ->and((string) $asset->notes)->toContain('Custody tracking only');
});

it('capitalises exactly AT the threshold (the boundary is >=, §8.6)', function (): void {
    $fixture = phase9StockEquipmentFixture();

    $result = app(ReceiveStock::class)->handle([
        'item_id' => (int) $fixture['item']->getKey(),
        'store_location_id' => $fixture['location'],
        'quantity' => '1.000',
        'total_cost' => 1_000_000,
        'moved_on' => '2031-03-15',
        'fiscal_year_id' => $fixture['calendar']['fiscal_year_id'],
        'academic_year_id' => $fixture['calendar']['academic_year_id'],
        'supplier_id' => $fixture['supplier_id'],
    ], phase9StockActor($fixture['user']));

    expect($result['asset_id'])->not->toBeNull()
        ->and($result['movement_id'])->toBeNull();

    /** @var object{acquisition_cost: int|string} $asset */
    $asset = DB::table('assets')->where('id', $result['asset_id'])->first(['acquisition_cost']);
    expect((int) $asset->acquisition_cost)->toBe(1_000_000);
});

it('receives equipment WITHOUT an asset category as ordinary stock (I4: the link is optional)', function (): void {
    $fixture = phase9StockEquipmentFixture();

    $tool = phase9StockItem([
        'name' => 'Hand Drill',
        'item_type' => 'equipment',
        'asset_category_id' => null,
    ]);

    $movement = phase9StockReceive($fixture['user'], $tool, $fixture['location'], '4.000', 180_000, $fixture['calendar']);

    expect($movement->quantity)->toBe('4.000')
        ->and($movement->total_cost)->toBe(180_000);

    $balance = phase9StockBalance((int) $tool->getKey(), $fixture['location']);
    expect($balance->quantity_on_hand)->toBe('4.000')
        ->and($balance->value_on_hand)->toBe(180_000);
});
