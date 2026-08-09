<?php

declare(strict_types=1);

use App\Modules\Assets\Actions\CapitaliseAsset;
use App\Modules\Assets\Actions\SplitIntoComponents;
use App\Modules\Assets\Models\Asset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/AssetTestHelpers.php';

uses(RefreshDatabase::class);

if (! function_exists('phase9AssetCapitalised')) {
    /**
     * A capitalised, in-service asset ready to componentise.
     *
     * @param  array{user: \App\Modules\Identity\Models\User, actor: \App\Support\Audit\Actor, category: \App\Modules\Assets\Models\AssetCategory, calendar: array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}, date: string, supplier_id: int}  $baseline
     * @param  array<string, mixed>  $overrides
     */
    function phase9AssetCapitalised(array $baseline, array $overrides = []): Asset
    {
        $asset = phase9AssetRegister($baseline, $overrides);

        return app(CapitaliseAsset::class)->handle(
            (int) $asset->getKey(), $baseline['actor'], $baseline['date'],
        );
    }
}

// ── A11: conservation ───────────────────────────────────────────────────

it('A11: conserves cost to the franc - parent reduction equals the components exactly', function () {
    $baseline = phase9AssetBaseline();
    $parent = phase9AssetCapitalised($baseline, ['acquisition_cost' => 10_000_001]);

    // 1 000 000 does not divide by [3, 3, 1]: Money::allocate's largest-
    // remainder split must still conserve every franc.
    $children = app(SplitIntoComponents::class)->handle((int) $parent->getKey(), 1_000_000, [
        ['name' => 'Engine', 'ratio' => 3, 'useful_life_months' => 36],
        ['name' => 'Gearbox', 'ratio' => 3],
        ['name' => 'Radio', 'ratio' => 1],
    ], $baseline['actor']);

    $parent->refresh();

    $childSum = array_sum(array_map(
        static fn (Asset $c): int => $c->acquisition_cost,
        $children,
    ));

    expect($childSum)->toBe(1_000_000)
        ->and($parent->acquisition_cost)->toBe(9_000_001)
        ->and($parent->acquisition_cost + $childSum)->toBe(10_000_001)
        ->and($children)->toHaveCount(3);

    foreach ($children as $child) {
        expect($child->parent_asset_id)->toBe((int) $parent->getKey())
            ->and($child->status)->toBe($parent->status)
            ->and($child->asset_category_id)->toBe($parent->asset_category_id);
    }

    // A component carries its OWN life; unstated ones inherit the snapshot.
    expect($children[0]->useful_life_months)->toBe(36)
        ->and($children[1]->useful_life_months)->toBe($parent->useful_life_months);
});

it('A11: posts nothing - a split is a register reshape, not a ledger event', function () {
    $baseline = phase9AssetBaseline();
    $parent = phase9AssetCapitalised($baseline);
    $entries = (int) \Illuminate\Support\Facades\DB::table('journal_entries')->count();

    app(SplitIntoComponents::class)->handle((int) $parent->getKey(), 5_000_000, [
        ['name' => 'Component A', 'ratio' => 1],
    ], $baseline['actor']);

    expect((int) \Illuminate\Support\Facades\DB::table('journal_entries')->count())->toBe($entries);
});

// ── A10: depth and cycles ───────────────────────────────────────────────

it('A10: permits three levels and refuses the fourth', function () {
    $baseline = phase9AssetBaseline();
    $root = phase9AssetCapitalised($baseline, ['acquisition_cost' => 40_000_000]);
    $split = app(SplitIntoComponents::class);

    [$level2] = $split->handle((int) $root->getKey(), 20_000_000, [
        ['name' => 'Level 2', 'ratio' => 1],
    ], $baseline['actor']);

    [$level3] = $split->handle((int) $level2->getKey(), 10_000_000, [
        ['name' => 'Level 3', 'ratio' => 1],
    ], $baseline['actor']);

    expect($level3->parent_asset_id)->toBe((int) $level2->getKey());

    expect(fn () => $split->handle((int) $level3->getKey(), 5_000_000, [
        ['name' => 'Level 4', 'ratio' => 1],
    ], $baseline['actor']))->toThrow(DomainException::class, 'A10');
});

it('A10: refuses a corrupted cyclic chain instead of walking it forever', function () {
    $baseline = phase9AssetBaseline();
    $parent = phase9AssetCapitalised($baseline, ['acquisition_cost' => 40_000_000]);

    [$child] = app(SplitIntoComponents::class)->handle((int) $parent->getKey(), 10_000_000, [
        ['name' => 'Child', 'ratio' => 1],
    ], $baseline['actor']);

    // Corrupt the register by hand: parent hangs under its own child.
    Asset::query()->whereKey($parent->getKey())->update(['parent_asset_id' => $child->getKey()]);

    expect(fn () => app(SplitIntoComponents::class)->handle((int) $child->getKey(), 1_000_000, [
        ['name' => 'Grandchild', 'ratio' => 1],
    ], $baseline['actor']))->toThrow(DomainException::class, 'A10');
});

// ── Guards ──────────────────────────────────────────────────────────────

it('A8: refuses a split that would push the parent cost to or below its residual', function () {
    $baseline = phase9AssetBaseline(categoryOverrides: ['default_residual_rate_bp' => 10_000]);
    $parent = phase9AssetCapitalised($baseline, ['acquisition_cost' => 10_000_000]); // residual 1 000 000

    app(SplitIntoComponents::class)->handle((int) $parent->getKey(), 9_000_000, [
        ['name' => 'Too big', 'ratio' => 1],
    ], $baseline['actor']);
})->throws(DomainException::class, 'A8');

it('refuses to split a draft asset', function () {
    $baseline = phase9AssetBaseline();
    $draft = phase9AssetRegister($baseline);

    app(SplitIntoComponents::class)->handle((int) $draft->getKey(), 1_000_000, [
        ['name' => 'Component', 'ratio' => 1],
    ], $baseline['actor']);
})->throws(DomainException::class);

it('A12: refuses to split a written-off asset', function () {
    $baseline = phase9AssetBaseline();
    $parent = phase9AssetCapitalised($baseline);
    $parent->forceFill(['status' => 'written_off'])->save();

    app(SplitIntoComponents::class)->handle((int) $parent->getKey(), 1_000_000, [
        ['name' => 'Component', 'ratio' => 1],
    ], $baseline['actor']);
})->throws(DomainException::class, 'A12');

it('rejects empty component lists, zero amounts and non-positive ratios', function () {
    $baseline = phase9AssetBaseline();
    $parent = phase9AssetCapitalised($baseline);
    $split = app(SplitIntoComponents::class);

    expect(fn () => $split->handle((int) $parent->getKey(), 1_000_000, [], $baseline['actor']))
        ->toThrow(ValidationException::class);

    expect(fn () => $split->handle((int) $parent->getKey(), 0, [
        ['name' => 'X', 'ratio' => 1],
    ], $baseline['actor']))->toThrow(ValidationException::class);

    expect(fn () => $split->handle((int) $parent->getKey(), 1_000_000, [
        ['name' => 'X', 'ratio' => 0],
    ], $baseline['actor']))->toThrow(ValidationException::class);
});
