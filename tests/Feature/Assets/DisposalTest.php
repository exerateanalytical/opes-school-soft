<?php

declare(strict_types=1);

use App\Modules\Assets\Actions\DisposeAsset;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetDisposal;
use App\Modules\Assets\Models\DepreciationSchedule;

require_once __DIR__.'/DepreciationTestHelpers.php';

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * 06-assets-stores.md §4.5/§6.1/§6.2 - acceptance 4: the disposal entry is
 * GROSS (81-family debit + 82-family credit as separate lines) and NO line
 * carries the gain_or_loss figure; plus mid-period depreciate-to-date,
 * the fully-depreciated zero-line omission, and the component cascade.
 */
it('posts the §6.2 gross disposal with no gain_or_loss line (acceptance 4)', function (): void {
    $baseline = phase9DeprBaseline();

    // The minibus, in service 2431-09-01; sold 40 months later on
    // 2434-12-31 for 22 000 000 on credit. Periods for the disposal year:
    phase9DeprCalendar(2434);
    $asset = phase9DeprAsset($baseline);

    $disposal = app(DisposeAsset::class)->handle((int) $asset->getKey(), [
        'disposal_type' => 'sale',
        'disposal_date' => '2434-12-31',
        'proceeds_amount' => 22_000_000,
        'buyer_partner_id' => $baseline['supplier_id'],
        'settlement' => 'receivable',
        'reason' => 'Fleet renewal',
    ], $baseline['actor']);

    // §6.2: elapsed 40 months → accumulated 11 925 000, NBV 23 850 000.
    expect($disposal->accumulated_at_disposal)->toBe(11_925_000)
        ->and($disposal->nbv_at_disposal)->toBe(23_850_000)
        ->and($disposal->gain_or_loss)->toBe(22_000_000 - 23_850_000);

    $lines = phase9AssetEntryLines($disposal->journal_entry_id);

    // One entry, five lines, gross: Dr 28 / Dr 812 / Cr 2442 / Dr 485 / Cr 822.
    expect($lines)->toHaveCount(5);

    $byCode = [];
    foreach ($lines as $line) {
        $byCode[$line->code][] = $line;
    }

    expect($byCode['28'][0]->debit)->toBe(11_925_000)
        ->and($byCode['812'][0]->debit)->toBe(23_850_000)
        ->and($byCode['2442'][0]->credit)->toBe(35_775_000)
        ->and($byCode['485'][0]->debit)->toBe(22_000_000)
        ->and($byCode['485'][0]->partner_id)->toBe($baseline['supplier_id'])
        ->and($byCode['822'][0]->credit)->toBe(22_000_000);

    // Acceptance 4 verbatim: an 81-family debit, an 82-family credit, and
    // NO line whose amount equals |gain_or_loss|.
    $loss = abs($disposal->gain_or_loss);

    foreach ($lines as $line) {
        expect($line->debit)->not->toBe($loss)
            ->and($line->credit)->not->toBe($loss);
    }

    // The asset is frozen with the A12 pair set.
    $asset->refresh();
    expect($asset->status->value)->toBe('disposed')
        ->and($asset->disposal_id)->toBe((int) $disposal->getKey());
});

it('depreciates to the disposal date inside the disposal transaction (§4.5)', function (): void {
    $baseline = phase9DeprBaseline();
    $asset = phase9DeprAsset($baseline);

    // September and October runs post normally...
    phase9DeprRunPosted($baseline, 9);
    phase9DeprRunPosted($baseline, 10);

    // ...then the bus crashes mid-December. Monthly convention → December
    // counts; the disposal writes the Nov+Dec catch-up itself.
    $disposal = app(DisposeAsset::class)->handle((int) $asset->getKey(), [
        'disposal_type' => 'scrap',
        'disposal_date' => $baseline['year'].'-12-15',
        'reason' => 'Accident write-off',
    ], $baseline['actor']);

    // 4/120 of 35 775 000 = 1 192 500 accumulated in total.
    expect($disposal->accumulated_at_disposal)->toBe(1_192_500)
        ->and($disposal->nbv_at_disposal)->toBe(35_775_000 - 1_192_500);

    /** @var DepreciationSchedule $finalRow */
    $finalRow = DepreciationSchedule::query()
        ->where('asset_id', (int) $asset->getKey())
        ->whereNull('depreciation_run_id')
        ->firstOrFail();

    expect($finalRow->charge)->toBe(2 * 298_125)
        ->and($finalRow->is_catch_up)->toBeTrue()
        ->and($finalRow->journal_entry_id)->not->toBeNull();

    // Zero proceeds scrap: leg 1 only - Dr 28, Dr 812, Cr 2442.
    $lines = phase9AssetEntryLines($disposal->journal_entry_id);
    expect($lines)->toHaveCount(3);
});

it('omits the 812 line entirely for a fully depreciated scrap (§6.2, 00-core §10.3)', function (): void {
    $baseline = phase9DeprBaseline(2431, ['useful_life_months' => 2]);
    phase9DeprCalendar(2432);

    // Life fully served by 2431-10-31; scrapped well after.
    $asset = phase9DeprAsset($baseline);

    $disposal = app(DisposeAsset::class)->handle((int) $asset->getKey(), [
        'disposal_type' => 'scrap',
        'disposal_date' => '2432-06-30',
        'reason' => 'Fully depreciated, unusable',
    ], $baseline['actor']);

    expect($disposal->accumulated_at_disposal)->toBe(35_775_000)
        ->and($disposal->nbv_at_disposal)->toBe(0);

    // The zero-NBV 812 line and both zero proceeds legs are OMITTED, not
    // posted at zero: the entry is exactly Dr 28 / Cr 2442.
    $lines = phase9AssetEntryLines($disposal->journal_entry_id);

    expect($lines)->toHaveCount(2)
        ->and($lines[0]->code)->toBe('28')
        ->and($lines[0]->debit)->toBe(35_775_000)
        ->and($lines[1]->code)->toBe('2442')
        ->and($lines[1]->credit)->toBe(35_775_000);
});

it('cascades the disposal over every component, each with its own gross legs (§4.6)', function (): void {
    $baseline = phase9DeprBaseline();
    $parent = phase9DeprAsset($baseline);

    // A component: its own asset row, its own life, under the parent.
    $component = phase9DeprAsset($baseline, [
        'name' => 'Engine '.fake()->unique()->numberBetween(1, 999_999),
        'parent_asset_id' => (int) $parent->getKey(),
        'acquisition_cost' => 5_000_000,
        'non_recoverable_vat_amount' => 0,
    ]);

    $disposal = app(DisposeAsset::class)->handle((int) $parent->getKey(), [
        'disposal_type' => 'sale',
        'disposal_date' => $baseline['year'].'-10-31',
        'proceeds_amount' => 30_000_000,
        'buyer_partner_id' => $baseline['supplier_id'],
        'settlement' => 'receivable',
        'reason' => 'Sold with engine',
    ], $baseline['actor']);

    $parent->refresh();
    $component->refresh();

    expect($parent->status->value)->toBe('disposed')
        ->and($component->status->value)->toBe('disposed');

    /** @var AssetDisposal $componentDisposal */
    $componentDisposal = AssetDisposal::query()
        ->where('asset_id', (int) $component->getKey())
        ->firstOrFail();

    // The component goes at ZERO proceeds, in its OWN journal entry.
    expect($componentDisposal->proceeds_amount)->toBe(0)
        ->and($componentDisposal->journal_entry_id)->not->toBe($disposal->journal_entry_id);

    $componentLines = phase9AssetEntryLines($componentDisposal->journal_entry_id);
    $gross = array_sum(array_map(static fn (object $l): int => $l->credit, $componentLines));

    expect($gross)->toBe(5_000_000);
});

it('is idempotent under a repeated key and frozen against a second disposal (A12)', function (): void {
    $baseline = phase9DeprBaseline();
    $asset = phase9DeprAsset($baseline);

    $data = [
        'disposal_type' => 'scrap',
        'disposal_date' => $baseline['year'].'-11-30',
        'reason' => 'Broken beyond repair',
        'idempotency_key' => 'p9f2-disposal-key',
    ];

    $first = app(DisposeAsset::class)->handle((int) $asset->getKey(), $data, $baseline['actor']);
    $second = app(DisposeAsset::class)->handle((int) $asset->getKey(), $data, $baseline['actor']);

    expect((int) $second->getKey())->toBe((int) $first->getKey())
        ->and(AssetDisposal::query()->count())->toBe(1);

    // Without the key, a disposed asset refuses outright.
    expect(fn () => app(DisposeAsset::class)->handle((int) $asset->getKey(), [
        'disposal_type' => 'scrap',
        'disposal_date' => $baseline['year'].'-12-31',
        'reason' => 'Again',
    ], $baseline['actor']))->toThrow(DomainException::class, 'A12');
});

it('requires the buyer for a sale and a settlement route for proceeds (§6.1)', function (): void {
    $baseline = phase9DeprBaseline();
    $asset = phase9DeprAsset($baseline);

    expect(fn () => app(DisposeAsset::class)->handle((int) $asset->getKey(), [
        'disposal_type' => 'sale',
        'disposal_date' => $baseline['year'].'-10-31',
        'proceeds_amount' => 1_000_000,
        'settlement' => 'receivable',
        'reason' => 'No buyer named',
    ], $baseline['actor']))->toThrow(Illuminate\Validation\ValidationException::class);

    expect(fn () => app(DisposeAsset::class)->handle((int) $asset->getKey(), [
        'disposal_type' => 'sale',
        'disposal_date' => $baseline['year'].'-10-31',
        'proceeds_amount' => 1_000_000,
        'buyer_partner_id' => $baseline['supplier_id'],
        'reason' => 'No settlement route',
    ], $baseline['actor']))->toThrow(Illuminate\Validation\ValidationException::class);

    expect(Asset::query()->findOrFail((int) $asset->getKey())->status->value)->not->toBe('disposed');
});

it('runs executed after a disposal find a zero charge for the disposed asset (§4.5)', function (): void {
    $baseline = phase9DeprBaseline();
    $disposed = phase9DeprAsset($baseline);
    phase9DeprAsset($baseline, ['name' => 'Survivor '.fake()->unique()->numberBetween(1, 999_999)]);

    app(DisposeAsset::class)->handle((int) $disposed->getKey(), [
        'disposal_type' => 'scrap',
        'disposal_date' => $baseline['year'].'-09-30',
        'reason' => 'Early write-off',
    ], $baseline['actor']);

    $run = phase9DeprRunPosted($baseline, 9);

    // Only the surviving asset produced a row; the disposed one is out of
    // the population and its period is already fully served.
    expect($run->assets_processed)->toBe(1)
        ->and(DepreciationSchedule::query()
            ->where('asset_id', (int) $disposed->getKey())
            ->whereNotNull('depreciation_run_id')
            ->count())->toBe(0);
});
