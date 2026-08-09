<?php

declare(strict_types=1);

use App\Modules\Assets\Actions\CapitaliseAsset;
use App\Modules\Assets\Actions\CreateAssetCategory;
use App\Modules\Assets\Domain\AssetPermission;
use App\Modules\Assets\Models\AssetCategory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/AssetTestHelpers.php';

uses(RefreshDatabase::class);

if (! function_exists('phase9AssetCategoryData')) {
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    function phase9AssetCategoryData(array $overrides = []): array
    {
        return [
        'code' => 'CAT'.fake()->unique()->numberBetween(1, 999_999),
        'name' => 'ICT equipment',
        'name_fr' => 'Materiel informatique',
        'asset_account_id' => phase9AssetAccountId('2442'),
        'accumulated_depreciation_account_id' => phase9AssetAccountId('28'),
        'disposal_nbv_account_id' => phase9AssetAccountId('812'),
        'disposal_proceeds_account_id' => phase9AssetAccountId('822'),
        'depreciation_method' => 'straight_line',
        'useful_life_months' => 60,
            ...$overrides,
        ];
    }
}

it('creates a category through the gate with verified accounts', function () {
    $user = phase9AssetUser(AssetPermission::MANAGE);

    $category = app(CreateAssetCategory::class)->handle(
        null,
        phase9AssetCategoryData(),
        phase9AssetActor($user),
    );

    expect($category->exists)->toBeTrue()
        ->and($category->depreciation_method->value)->toBe('straight_line')
        ->and($category->useful_life_months)->toBe(60)
        // V1: never defaulted - the accountant must declare a policy.
        ->and($category->prorata_convention)->toBeNull();
});

it('refuses creation without asset.manage', function () {
    phase9AssetUser(); // signed in, no abilities

    app(CreateAssetCategory::class)->handle(
        null,
        phase9AssetCategoryData(),
        \App\Support\Audit\Actor::system(),
    );
})->throws(AuthorizationException::class);

// ── A1 ──────────────────────────────────────────────────────────────────

it('A1: refuses a depreciating method without a useful life, and `none` with one', function () {
    $user = phase9AssetUser(AssetPermission::MANAGE);
    $action = app(CreateAssetCategory::class);

    expect(fn () => $action->handle(null, phase9AssetCategoryData([
        'depreciation_method' => 'straight_line',
        'useful_life_months' => null,
    ]), phase9AssetActor($user)))->toThrow(ValidationException::class);

    expect(fn () => $action->handle(null, phase9AssetCategoryData([
        'depreciation_method' => 'none',
        'useful_life_months' => 48,
    ]), phase9AssetActor($user)))->toThrow(ValidationException::class);
});

it('A1: the database CHECK refuses a raw insert violating the pairing', function () {
    expect(fn () => DB::table('asset_categories')->insert([
        'code' => 'RAW-A1',
        'name' => 'Raw',
        'name_fr' => 'Brut',
        'asset_account_id' => phase9AssetAccountId('2442'),
        'accumulated_depreciation_account_id' => phase9AssetAccountId('28'),
        'disposal_nbv_account_id' => phase9AssetAccountId('812'),
        'disposal_proceeds_account_id' => phase9AssetAccountId('822'),
        'depreciation_method' => 'straight_line',
        'useful_life_months' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

// ── A2 ──────────────────────────────────────────────────────────────────

it('A2: declining balance requires its rate', function () {
    $user = phase9AssetUser(AssetPermission::MANAGE);

    app(CreateAssetCategory::class)->handle(null, phase9AssetCategoryData([
        'depreciation_method' => 'declining_balance',
        'useful_life_months' => 60,
        'declining_rate_bp' => null,
    ]), phase9AssetActor($user));
})->throws(ValidationException::class);

// ── A3 ──────────────────────────────────────────────────────────────────

it('A3: the gross account must resolve to class 2, the accumulated to class 28', function () {
    $user = phase9AssetUser(AssetPermission::MANAGE);
    $action = app(CreateAssetCategory::class);

    // A revenue account is not a class-2 gross account.
    expect(fn () => $action->handle(null, phase9AssetCategoryData([
        'asset_account_id' => phase9AssetAccountId('822'),
    ]), phase9AssetActor($user)))->toThrow(DomainException::class, 'A3');

    // 28 itself offered as the GROSS account is the contra family.
    expect(fn () => $action->handle(null, phase9AssetCategoryData([
        'asset_account_id' => phase9AssetAccountId('28'),
    ]), phase9AssetActor($user)))->toThrow(DomainException::class, 'A3');

    // A class-2 gross account is not an accumulated-depreciation account.
    expect(fn () => $action->handle(null, phase9AssetCategoryData([
        'accumulated_depreciation_account_id' => phase9AssetAccountId('2442'),
    ]), phase9AssetActor($user)))->toThrow(DomainException::class, 'A3');
});

// ── A4 ──────────────────────────────────────────────────────────────────

it('A4: a positive capitalisation threshold requires the below-threshold expense account', function () {
    $user = phase9AssetUser(AssetPermission::MANAGE);

    app(CreateAssetCategory::class)->handle(null, phase9AssetCategoryData([
        'capitalisation_threshold' => 100_000,
        'below_threshold_expense_account_id' => null,
    ]), phase9AssetActor($user));
})->throws(ValidationException::class);

// ── A5 ──────────────────────────────────────────────────────────────────

it('A5: freezes account mappings once a posted asset exists, while non-account edits stay open', function () {
    $baseline = phase9AssetBaseline();
    $asset = phase9AssetRegister($baseline);
    app(CapitaliseAsset::class)->handle((int) $asset->getKey(), $baseline['actor'], $baseline['date']);

    $action = app(CreateAssetCategory::class);
    $categoryId = (int) $baseline['category']->getKey();

    // 2441 is equally a legitimate class-2 account - the refusal is about
    // reconcilability of history, not about A3.
    expect(fn () => $action->handle($categoryId, [
        'asset_account_id' => phase9AssetAccountId('2441'),
    ], $baseline['actor']))->toThrow(DomainException::class, 'A5');

    // A rename is not an account change.
    $renamed = $action->handle($categoryId, ['name' => 'Renamed'], $baseline['actor']);
    expect($renamed->name)->toBe('Renamed');
});

it('A5 companion: account mappings on a category with only DRAFT assets may still change', function () {
    $baseline = phase9AssetBaseline();
    phase9AssetRegister($baseline); // draft - nothing posted

    $updated = app(CreateAssetCategory::class)->handle(
        (int) $baseline['category']->getKey(),
        ['asset_account_id' => phase9AssetAccountId('2441')],
        $baseline['actor'],
    );

    expect($updated->asset_account_id)->toBe(phase9AssetAccountId('2441'));
});

// ── Parent chains ───────────────────────────────────────────────────────

it('caps the category hierarchy at depth 3 and refuses cycles', function () {
    $user = phase9AssetUser(AssetPermission::MANAGE);
    $action = app(CreateAssetCategory::class);

    $a = $action->handle(null, phase9AssetCategoryData(), phase9AssetActor($user));
    $b = $action->handle(null, phase9AssetCategoryData(['parent_id' => $a->getKey()]), phase9AssetActor($user));
    $c = $action->handle(null, phase9AssetCategoryData(['parent_id' => $b->getKey()]), phase9AssetActor($user));

    // Depth 4 refused.
    expect(fn () => $action->handle(null, phase9AssetCategoryData([
        'parent_id' => $c->getKey(),
    ]), phase9AssetActor($user)))->toThrow(DomainException::class, 'depth');

    // Cycle refused: A may not hang under C.
    expect(fn () => $action->handle((int) $a->getKey(), [
        'parent_id' => $c->getKey(),
    ], phase9AssetActor($user)))->toThrow(DomainException::class);
});

it('keeps the archived flag honest: an archived category is data, not a deletion', function () {
    $category = phase9AssetCategory(['is_archived' => true]);

    expect(AssetCategory::query()->whereKey($category->getKey())->exists())->toBeTrue();
});
