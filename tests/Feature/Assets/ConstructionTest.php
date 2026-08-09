<?php

declare(strict_types=1);

use App\Modules\Assets\Actions\CapitaliseAsset;
use App\Modules\Assets\Actions\CommissionAsset;
use App\Modules\Assets\Actions\RecordConstructionCost;
use App\Modules\Assets\Domain\AssetStatus;
use App\Modules\Assets\Models\Asset;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/AssetTestHelpers.php';

uses(RefreshDatabase::class);

if (! function_exists('phase9AssetInProgress')) {
    /**
     * A self-constructed shell: registered at zero cost, capitalised as
     * work in progress (nothing posts - costs come later).
     *
     * @param  array{user: \App\Modules\Identity\Models\User, actor: \App\Support\Audit\Actor, category: \App\Modules\Assets\Models\AssetCategory, calendar: array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}, date: string, supplier_id: int}  $baseline
     */
    function phase9AssetInProgress(array $baseline): Asset
    {
        $asset = phase9AssetRegister($baseline, [
            'name' => 'Classroom block '.fake()->unique()->numberBetween(1, 999_999),
            'acquisition_type' => 'self_constructed',
            'acquisition_cost' => 0,
            'cost_basis' => 'ht',
            'non_recoverable_vat_amount' => 0,
            'supplier_id' => null,
        ]);

        return app(CapitaliseAsset::class)->handle((int) $asset->getKey(), $baseline['actor']);
    }
}

// ── Entering construction ───────────────────────────────────────────────

it('capitalises a self-constructed shell as in_progress with no entry and no depreciation start (A14 precondition)', function () {
    $baseline = phase9AssetBaseline();
    $shell = phase9AssetInProgress($baseline);

    expect($shell->status)->toBe(AssetStatus::InProgress)
        ->and($shell->journal_entry_id)->toBeNull()
        ->and($shell->in_service_date)->toBeNull()
        // A14: nothing for a depreciation run to key on.
        ->and($shell->depreciation_start_date)->toBeNull();
});

it('routes a purchased work-in-progress capitalisation to the in-progress account, not the gross account', function () {
    $baseline = phase9AssetBaseline();
    $asset = phase9AssetRegister($baseline, ['acquisition_cost' => 12_000_000]);

    $wip = app(CapitaliseAsset::class)->handle(
        (int) $asset->getKey(), $baseline['actor'], null, asInProgress: true,
    );

    expect($wip->status)->toBe(AssetStatus::InProgress)
        ->and($wip->journal_entry_id)->not->toBeNull();

    $lines = phase9AssetEntryLines((int) $wip->journal_entry_id);

    expect($lines[0]->code)->toBe('249')
        ->and($lines[0]->debit)->toBe(12_000_000)
        ->and($lines[1]->code)->toBe('4812');
});

it('refuses work-in-progress capitalisation when the category has no in-progress account, naming the configuration', function () {
    $baseline = phase9AssetBaseline(categoryOverrides: ['in_progress_account_id' => null]);
    $asset = phase9AssetRegister($baseline, ['acquisition_type' => 'self_constructed', 'acquisition_cost' => 0]);

    app(CapitaliseAsset::class)->handle((int) $asset->getKey(), $baseline['actor']);
})->throws(DomainException::class, 'in_progress_account_id');

// ── Cost accumulation ───────────────────────────────────────────────────

it('accumulates construction costs on an in_progress asset only', function () {
    $baseline = phase9AssetBaseline();
    $shell = phase9AssetInProgress($baseline);
    $record = app(RecordConstructionCost::class);

    $record->handle((int) $shell->getKey(), 5_000_000, $baseline['date'], 'Foundations', $baseline['actor']);
    $record->handle((int) $shell->getKey(), 2_500_000, $baseline['date'], 'Walls', $baseline['actor'], [
        'document_ref' => 'MANUAL-42',
    ]);

    expect((int) DB::table('asset_construction_costs')->where('asset_id', $shell->getKey())->sum('amount'))
        ->toBe(7_500_000);

    // An in-service asset refuses further accumulation.
    $inService = phase9AssetRegister($baseline);
    app(CapitaliseAsset::class)->handle((int) $inService->getKey(), $baseline['actor'], $baseline['date']);

    expect(fn () => $record->handle((int) $inService->getKey(), 1_000_000, $baseline['date'], 'Late cost', $baseline['actor']))
        ->toThrow(DomainException::class, 'A14');
});

it('is idempotent on the construction-cost key', function () {
    $baseline = phase9AssetBaseline();
    $shell = phase9AssetInProgress($baseline);
    $record = app(RecordConstructionCost::class);

    $first = $record->handle((int) $shell->getKey(), 5_000_000, $baseline['date'], 'Foundations', $baseline['actor'], [
        'idempotency_key' => 'p9f1-cc-1',
    ]);
    $second = $record->handle((int) $shell->getKey(), 5_000_000, $baseline['date'], 'Foundations', $baseline['actor'], [
        'idempotency_key' => 'p9f1-cc-1',
    ]);

    expect($second->getKey())->toBe($first->getKey())
        ->and(DB::table('asset_construction_costs')->count())->toBe(1);
});

it('rejects non-positive construction amounts, in code and by CHECK', function () {
    $baseline = phase9AssetBaseline();
    $shell = phase9AssetInProgress($baseline);

    expect(fn () => app(RecordConstructionCost::class)->handle(
        (int) $shell->getKey(), 0, $baseline['date'], 'Zero', $baseline['actor'],
    ))->toThrow(ValidationException::class);

    expect(fn () => DB::table('asset_construction_costs')->insert([
        'asset_id' => $shell->getKey(),
        'amount' => -5,
        'incurred_on' => $baseline['date'],
        'description' => 'Negative',
        'fiscal_year_id' => $baseline['calendar']['fiscal_year_id'],
        'academic_year_id' => $baseline['calendar']['academic_year_id'],
        'recorded_by' => $baseline['user']->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('keeps the cost trail append-only: the triggers reject UPDATE and DELETE', function () {
    $baseline = phase9AssetBaseline();
    $shell = phase9AssetInProgress($baseline);

    $cost = app(RecordConstructionCost::class)->handle(
        (int) $shell->getKey(), 5_000_000, $baseline['date'], 'Foundations', $baseline['actor'],
    );

    expect(fn () => DB::table('asset_construction_costs')
        ->where('id', $cost->getKey())
        ->update(['amount' => 4_000_000]))->toThrow(QueryException::class);

    expect(fn () => DB::table('asset_construction_costs')
        ->where('id', $cost->getKey())
        ->delete())->toThrow(QueryException::class);
});

// ── Commissioning (§3) ──────────────────────────────────────────────────

it('commissions the accumulated balance in one transfer entry: Dr gross / Cr in-progress', function () {
    $baseline = phase9AssetBaseline(categoryOverrides: ['default_residual_rate_bp' => 10_000]);
    $shell = phase9AssetInProgress($baseline);
    $record = app(RecordConstructionCost::class);

    $record->handle((int) $shell->getKey(), 5_000_000, $baseline['date'], 'Foundations', $baseline['actor']);
    $record->handle((int) $shell->getKey(), 2_500_000, $baseline['date'], 'Walls', $baseline['actor']);

    $commissionDate = '2431-03-25'; // inside the open period
    $commissioned = app(CommissionAsset::class)->handle((int) $shell->getKey(), $commissionDate, $baseline['actor']);

    expect($commissioned->status)->toBe(AssetStatus::InService)
        ->and($commissioned->acquisition_cost)->toBe(7_500_000)
        // A7 against the FINAL cost, first knowable now.
        ->and($commissioned->residual_value)->toBe(750_000)
        ->and($commissioned->in_service_date)->toBe($commissionDate)
        // §5.1: derived, stored.
        ->and($commissioned->depreciation_start_date)->toBe($commissionDate);

    // The transfer entry is the LAST entry: Dr 2442 / Cr 249, full balance.
    $entryId = (int) DB::table('journal_entries')->max('id');
    $lines = phase9AssetEntryLines($entryId);

    expect($lines)->toHaveCount(2)
        ->and($lines[0]->code)->toBe('2442')
        ->and($lines[0]->debit)->toBe(7_500_000)
        ->and($lines[1]->code)->toBe('249')
        ->and($lines[1]->credit)->toBe(7_500_000);
});

it('refuses to commission without accumulated cost, twice, or outside in_progress', function () {
    $baseline = phase9AssetBaseline();
    $shell = phase9AssetInProgress($baseline);
    $commission = app(CommissionAsset::class);

    // Nothing accumulated yet.
    expect(fn () => $commission->handle((int) $shell->getKey(), $baseline['date'], $baseline['actor']))
        ->toThrow(DomainException::class, 'no construction cost');

    app(RecordConstructionCost::class)->handle(
        (int) $shell->getKey(), 5_000_000, $baseline['date'], 'Foundations', $baseline['actor'],
    );
    $commission->handle((int) $shell->getKey(), '2431-03-25', $baseline['actor']);

    // Once in service, commissioning again is refused - not silently repeated.
    expect(fn () => $commission->handle((int) $shell->getKey(), '2431-03-26', $baseline['actor']))
        ->toThrow(DomainException::class, 'in_progress');
});

it('refuses a commissioning date before acquisition (A6)', function () {
    $baseline = phase9AssetBaseline();
    $shell = phase9AssetInProgress($baseline);

    app(RecordConstructionCost::class)->handle(
        (int) $shell->getKey(), 5_000_000, $baseline['date'], 'Foundations', $baseline['actor'],
    );

    app(CommissionAsset::class)->handle((int) $shell->getKey(), '2431-03-01', $baseline['actor']);
})->throws(ValidationException::class);
