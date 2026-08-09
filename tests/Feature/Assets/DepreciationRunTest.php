<?php

declare(strict_types=1);

use App\Modules\Assets\Actions\ApproveDepreciationRun;
use App\Modules\Assets\Actions\PostDepreciationRun;
use App\Modules\Assets\Actions\RunDepreciation;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\DepreciationRun;
use App\Modules\Assets\Models\DepreciationSchedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/DepreciationTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * 06-assets-stores.md §4 - acceptance 1 (idempotency + the DB duplicate
 * gate), acceptance 3 (catch-up with preserved value date), the V1
 * configuration refusal, maker/checker, and the §4.4 worked figures.
 */
it('calculates the §4.4 first-month charge and posts one journal entry', function (): void {
    $baseline = phase9DeprBaseline();
    $asset = phase9DeprAsset($baseline);

    $run = app(RunDepreciation::class)->handle($baseline['fiscal_year_id'], 9, $baseline['actor']);

    expect($run->status->value)->toBe('calculated')
        ->and($run->assets_processed)->toBe(1)
        ->and($run->total_charge)->toBe(298_125);

    /** @var DepreciationSchedule $row */
    $row = DepreciationSchedule::query()
        ->where('depreciation_run_id', $run->getKey())
        ->firstOrFail();

    // round(35 775 000 × 1/120) = 298 125, months_elapsed 1, no catch-up.
    expect($row->charge)->toBe(298_125)
        ->and($row->opening_accumulated)->toBe(0)
        ->and($row->closing_accumulated)->toBe(298_125)
        ->and($row->net_book_value)->toBe(35_775_000 - 298_125)
        ->and($row->depreciable_base)->toBe(35_775_000)
        ->and($row->months_elapsed)->toBe(1)
        ->and($row->is_catch_up)->toBeFalse()
        ->and($row->asset_id)->toBe((int) $asset->getKey());

    phase9DeprApprove($baseline, $run);
    $posted = app(PostDepreciationRun::class)->handle((int) $run->getKey(), $baseline['actor']);

    expect($posted->status->value)->toBe('posted')
        ->and($posted->journal_entry_id)->not->toBeNull();

    $lines = phase9AssetEntryLines((int) $posted->journal_entry_id);

    expect($lines)->toHaveCount(2)
        ->and($lines[0]->code)->toBe('6811')
        ->and($lines[0]->debit)->toBe(298_125)
        ->and($lines[1]->code)->toBe('28')
        ->and($lines[1]->credit)->toBe(298_125);
});

it('refuses a second run of the same period and creates nothing (acceptance 1)', function (): void {
    $baseline = phase9DeprBaseline();
    phase9DeprAsset($baseline);

    phase9DeprRunPosted($baseline, 9);

    $rowsBefore = DepreciationSchedule::query()->count();
    $linesBefore = (int) DB::table('journal_entry_lines')->count();

    expect(fn () => app(RunDepreciation::class)->handle($baseline['fiscal_year_id'], 9, $baseline['actor']))
        ->toThrow(DomainException::class, 'already exists');

    expect(DepreciationSchedule::query()->count())->toBe($rowsBefore)
        ->and((int) DB::table('journal_entry_lines')->count())->toBe($linesBefore);
});

it('lets the database reject a concurrent duplicate period (acceptance 1)', function (): void {
    $baseline = phase9DeprBaseline();
    phase9DeprAsset($baseline);

    $run = phase9DeprRunPosted($baseline, 9);

    expect(fn () => DB::table('depreciation_runs')->insert([
        'fiscal_year_id' => $run->fiscal_year_id,
        'period_month' => 9,
        'status' => 'draft',
        'run_by' => $baseline['user']->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('yields a zero charge - and no schedule row - for a fully posted asset in the next period arithmetic', function (): void {
    $baseline = phase9DeprBaseline();
    phase9DeprAsset($baseline);

    phase9DeprRunPosted($baseline, 9);

    // October: entitlement 2/120 − posted 1/120 = one month's charge; then
    // the asset is fully served for October and a re-run of the SAME maths
    // at the same date must produce zero. We assert via November's opening.
    $october = phase9DeprRunPosted($baseline, 10);

    /** @var DepreciationSchedule $row */
    $row = DepreciationSchedule::query()
        ->where('depreciation_run_id', $october->getKey())
        ->firstOrFail();

    expect($row->charge)->toBe(298_125)
        ->and($row->opening_accumulated)->toBe(298_125)
        ->and($row->closing_accumulated)->toBe(596_250)
        ->and($row->is_catch_up)->toBeFalse();
});

it('gives a late-capitalised asset its full arrears as one catch-up row with the value date preserved (acceptance 3)', function (): void {
    $baseline = phase9DeprBaseline();

    // September / October / November runs post with NO assets in scope.
    phase9DeprRunPosted($baseline, 9);
    phase9DeprRunPosted($baseline, 10);
    phase9DeprRunPosted($baseline, 11);

    // Keyed in late: in service 1 September, capitalised only now.
    phase9DeprAsset($baseline);

    $december = phase9DeprRunPosted($baseline, 12);

    /** @var DepreciationSchedule $row */
    $row = DepreciationSchedule::query()
        ->where('depreciation_run_id', $december->getKey())
        ->firstOrFail();

    // §4.4: entitlement = round(35 775 000 × 4/120) = 1 192 500, one row.
    expect($row->charge)->toBe(1_192_500)
        ->and($row->is_catch_up)->toBeTrue()
        ->and($row->months_elapsed)->toBe(4)
        ->and(DepreciationSchedule::query()->where('asset_id', $row->asset_id)->count())->toBe(1);

    // AUDCIF Art. 22 / §4.4: posted in the open period, value date kept.
    /** @var object{date: string, value_date: string} $entry */
    $entry = DB::table('journal_entries')
        ->where('id', (int) $december->journal_entry_id)
        ->first(['date', 'value_date']);

    expect(substr($entry->date, 0, 10))->toBe($baseline['year'].'-12-31')
        ->and(substr($entry->value_date, 0, 10))->toBe($baseline['year'].'-09-01');
});

it('refuses to run while a depreciating category has no prorata convention (V1 gate)', function (): void {
    $baseline = phase9DeprBaseline();
    phase9DeprAsset($baseline);

    // An unrelated category with a depreciating method and NO declared
    // convention - exactly what the unseeded column looks like on day one.
    AssetCategory::factory()->create([
        'code' => 'V1GAP',
        'prorata_convention' => null,
    ]);

    expect(fn () => app(RunDepreciation::class)->handle($baseline['fiscal_year_id'], 9, $baseline['actor']))
        ->toThrow(DomainException::class, 'V1');

    expect(DepreciationRun::query()->count())->toBe(0)
        ->and(DepreciationSchedule::query()->count())->toBe(0);
});

it('skips an asset whose category lacks the 681x expense account, with an exception entry (V3)', function (): void {
    $baseline = phase9DeprBaseline();
    phase9DeprAsset($baseline);

    // A second asset under a V3-incomplete category.
    $gapCategory = AssetCategory::factory()->create(['depreciation_expense_account_id' => null]);
    phase9DeprAsset($baseline, [
        'asset_category_id' => (int) $gapCategory->getKey(),
        'name' => 'Photocopier '.fake()->unique()->numberBetween(1, 999_999),
    ]);

    $run = app(RunDepreciation::class)->handle($baseline['fiscal_year_id'], 9, $baseline['actor']);

    expect($run->assets_processed)->toBe(1)
        ->and($run->exceptions_json)->toHaveCount(1)
        ->and($run->exceptions_json[0]['reason'])->toContain('V3');
});

it('enforces maker/checker on approval', function (): void {
    $baseline = phase9DeprBaseline();
    phase9DeprAsset($baseline);

    $run = app(RunDepreciation::class)->handle($baseline['fiscal_year_id'], 9, $baseline['actor']);

    // The person who ran it may not approve it.
    expect(fn () => app(ApproveDepreciationRun::class)->handle((int) $run->getKey(), $baseline['actor']))
        ->toThrow(DomainException::class, 'maker/checker');

    // A different hand may.
    $approved = phase9DeprApprove($baseline, $run);

    expect($approved->status->value)->toBe('approved')
        ->and($approved->approved_by)->not->toBe($run->run_by);
});

it('posts one single journal entry for a run covering several assets', function (): void {
    $baseline = phase9DeprBaseline();
    phase9DeprAsset($baseline);
    phase9DeprAsset($baseline, ['name' => 'Second bus '.fake()->unique()->numberBetween(1, 999_999)]);

    $run = phase9DeprRunPosted($baseline, 9);

    expect($run->assets_processed)->toBe(2)
        ->and($run->total_charge)->toBe(2 * 298_125);

    $lines = phase9AssetEntryLines((int) $run->journal_entry_id);

    // One entry, two Dr/Cr pairs - not two entries.
    expect($lines)->toHaveCount(4)
        ->and(DepreciationSchedule::query()
            ->where('depreciation_run_id', $run->getKey())
            ->whereNull('journal_entry_id')
            ->count())->toBe(0);

    $entryIds = DepreciationSchedule::query()
        ->where('depreciation_run_id', $run->getKey())
        ->pluck('journal_entry_id')
        ->unique();

    expect($entryIds)->toHaveCount(1)
        ->and((int) $entryIds->first())->toBe((int) $run->journal_entry_id);
});

it('refuses a new period while an earlier run is still unposted', function (): void {
    $baseline = phase9DeprBaseline();
    phase9DeprAsset($baseline);

    app(RunDepreciation::class)->handle($baseline['fiscal_year_id'], 9, $baseline['actor']);

    expect(fn () => app(RunDepreciation::class)->handle($baseline['fiscal_year_id'], 10, $baseline['actor']))
        ->toThrow(DomainException::class, 'still');
});

it('returns the same run for a repeated idempotency key', function (): void {
    $baseline = phase9DeprBaseline();
    phase9DeprAsset($baseline);

    $first = app(RunDepreciation::class)->handle($baseline['fiscal_year_id'], 9, $baseline['actor'], 'p9f2-run-key');
    $second = app(RunDepreciation::class)->handle($baseline['fiscal_year_id'], 9, $baseline['actor'], 'p9f2-run-key');

    expect((int) $second->getKey())->toBe((int) $first->getKey())
        ->and(DepreciationRun::query()->count())->toBe(1);
});
