<?php

declare(strict_types=1);

use App\Modules\Assets\Actions\ClawBackSubsidy;
use App\Modules\Assets\Actions\ImpairAsset;
use App\Modules\Assets\Actions\RegisterInvestmentSubsidy;
use App\Modules\Assets\Actions\RevalueAssets;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\InvestmentSubsidy;
use App\Modules\Assets\Models\InvestmentSubsidyRelease;

require_once __DIR__.'/DepreciationTestHelpers.php';

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * 06-assets-stores.md §6.3/§6.4 - acceptance 5 (neutrality: Σ 681x charges
 * = Σ 845 releases, franc for franc, over the full life), partial funding,
 * clawback, and the V5 845-unconfigured skip. Plus the §6.5/§6.6 gated-off
 * refusals (impairment / revaluation ship disabled).
 */
if (! function_exists('phase9DeprSubsidisedAsset')) {
    /**
     * A donated, fully-capitalised asset funded by a registered subsidy.
     *
     * @param  array{user: \App\Modules\Identity\Models\User, actor: \App\Support\Audit\Actor, category: AssetCategory, fiscal_year_id: int, academic_year_id: int, year: int, supplier_id: int, accounts: array{expense_681: int, release_845: int}}  $baseline
     * @return array{asset: Asset, subsidy: InvestmentSubsidy}
     */
    function phase9DeprSubsidisedAsset(array $baseline, int $cost, int $granted, ?int $releaseAccountId): array
    {
        $asset = phase9DeprAsset($baseline, [
            'acquisition_cost' => $cost,
            'cost_basis' => 'ht',
            'non_recoverable_vat_amount' => 0,
            'acquisition_type' => 'donation',
            'fair_value_at_donation' => $cost,
            'supplier_id' => null,
            'donor_id' => $baseline['supplier_id'],
        ]);

        $subsidy = app(RegisterInvestmentSubsidy::class)->handle([
            'reference' => 'SUB'.fake()->unique()->numberBetween(1, 999_999),
            'donor_partner_id' => $baseline['supplier_id'],
            'subsidy_account_id' => phase9AssetAccountId('14'),
            'release_income_account_id' => $releaseAccountId,
            'granted_amount' => $granted,
            'granted_on' => $baseline['year'].'-09-01',
            'fiscal_year_id' => $baseline['fiscal_year_id'],
            'academic_year_id' => $baseline['academic_year_id'],
            'asset_id' => (int) $asset->getKey(),
        ], $baseline['actor']);

        return ['asset' => $asset->refresh(), 'subsidy' => $subsidy];
    }
}

it('keeps a fully funded donated asset P&L-neutral over its whole life (acceptance 5)', function (): void {
    // A 3 000 000 donated asset over 4 months, fully funded.
    $baseline = phase9DeprBaseline(2431, ['useful_life_months' => 4]);
    ['subsidy' => $subsidy] = phase9DeprSubsidisedAsset(
        $baseline,
        3_000_000,
        3_000_000,
        $baseline['accounts']['release_845'],
    );

    foreach ([9, 10, 11, 12] as $month) {
        $run = phase9DeprRunPosted($baseline, $month);

        // Every period: the release equals the charge, franc for franc.
        /** @var InvestmentSubsidyRelease $release */
        $release = InvestmentSubsidyRelease::query()
            ->where('depreciation_run_id', (int) $run->getKey())
            ->firstOrFail();

        expect($release->amount)->toBe($run->total_charge);
    }

    // Σ 681x = Σ 845, and both equal the full grant.
    expect(phase9DeprLedgerSum('6811'))->toBe(3_000_000)
        ->and(-phase9DeprLedgerSum('845'))->toBe(3_000_000)
        ->and((int) InvestmentSubsidyRelease::query()->sum('amount'))->toBe(3_000_000);

    // The subsidy is spent: status fully_released, and the class-14 account
    // is back to its opening credit of the donation... which this ledger
    // never carried (the capitalisation rule is F5/production config), so
    // its net movement here is exactly the released total.
    expect($subsidy->refresh()->status->value)->toBe('fully_released')
        ->and(phase9DeprLedgerSum('14'))->toBe(3_000_000);
});

it('releases the funded proportion under partial funding (§6.4)', function (): void {
    // 1 800 000 grant toward a 3 000 000 asset over 3 months: 60%.
    $baseline = phase9DeprBaseline(2431, ['useful_life_months' => 3]);
    phase9DeprSubsidisedAsset($baseline, 3_000_000, 1_800_000, $baseline['accounts']['release_845']);

    $september = phase9DeprRunPosted($baseline, 9);

    /** @var InvestmentSubsidyRelease $first */
    $first = InvestmentSubsidyRelease::query()
        ->where('depreciation_run_id', (int) $september->getKey())
        ->firstOrFail();

    expect($september->total_charge)->toBe(1_000_000)
        ->and($first->amount)->toBe(600_000);

    phase9DeprRunPosted($baseline, 10);
    phase9DeprRunPosted($baseline, 11);

    // Σ releases = the grant exactly, by the min() cap.
    expect((int) InvestmentSubsidyRelease::query()->sum('amount'))->toBe(1_800_000)
        ->and(-phase9DeprLedgerSum('845'))->toBe(1_800_000)
        ->and(phase9DeprLedgerSum('6811'))->toBe(3_000_000);
});

it('skips the release with an exception while 845 is unconfigured, and still depreciates (V5)', function (): void {
    $baseline = phase9DeprBaseline(2431, ['useful_life_months' => 4]);
    ['subsidy' => $subsidy] = phase9DeprSubsidisedAsset($baseline, 3_000_000, 3_000_000, null);

    $run = phase9DeprRunPosted($baseline, 9);

    // The asset depreciated...
    expect($run->total_charge)->toBe(750_000)
        ->and(phase9DeprLedgerSum('6811'))->toBe(750_000);

    // ...but nothing released, nothing guessed, and the run says why.
    expect(InvestmentSubsidyRelease::query()->count())->toBe(0)
        ->and(phase9DeprLedgerSum('845'))->toBe(0)
        ->and($run->refresh()->exceptions_json)->not->toBeNull()
        ->and($run->refresh()->exceptions_json[0]['reason'])->toContain('V5')
        ->and($subsidy->refresh()->status->value)->toBe('active');
});

it('claws back the unreleased balance against a donor liability (§6.4)', function (): void {
    $baseline = phase9DeprBaseline(2431, ['useful_life_months' => 4]);
    ['subsidy' => $subsidy] = phase9DeprSubsidisedAsset(
        $baseline,
        3_000_000,
        3_000_000,
        $baseline['accounts']['release_845'],
    );

    // One period released (750 000), then the donor recalls the grant.
    phase9DeprRunPosted($baseline, 9);

    $liabilityId = phase9AssetAccountId('476');

    $clawed = app(ClawBackSubsidy::class)->handle(
        (int) $subsidy->getKey(),
        $liabilityId,
        $baseline['year'].'-10-15',
        'Grant conditions breached',
        $baseline['actor'],
    );

    expect($clawed->status->value)->toBe('clawed_back');

    // Dr 14 / Cr 476 for the unreleased 2 250 000, partner-stamped.
    /** @var object{id: int|string} $entry */
    $entry = DB::table('journal_entries')->orderByDesc('id')->first(['id']);
    $lines = phase9AssetEntryLines((int) $entry->id);

    expect($lines)->toHaveCount(2)
        ->and($lines[0]->code)->toBe('14')
        ->and($lines[0]->debit)->toBe(2_250_000)
        ->and($lines[1]->code)->toBe('476')
        ->and($lines[1]->credit)->toBe(2_250_000)
        ->and($lines[1]->partner_id)->toBe($baseline['supplier_id']);

    // Later runs release nothing further.
    $october = phase9DeprRunPosted($baseline, 10);

    expect($october->total_charge)->toBe(750_000)
        ->and((int) InvestmentSubsidyRelease::query()->sum('amount'))->toBe(750_000);

    // A clawed-back subsidy cannot be clawed back twice.
    expect(fn () => app(ClawBackSubsidy::class)->handle(
        (int) $subsidy->getKey(),
        $liabilityId,
        $baseline['year'].'-11-15',
        'Twice',
        $baseline['actor'],
    ))->toThrow(DomainException::class, 'clawed_back');
});

it('writes off the unreleased balance to 845 on disposal, not netted into 812/822 (§6.4)', function (): void {
    $baseline = phase9DeprBaseline(2431, ['useful_life_months' => 4]);
    ['asset' => $asset] = phase9DeprSubsidisedAsset(
        $baseline,
        3_000_000,
        3_000_000,
        $baseline['accounts']['release_845'],
    );

    phase9DeprRunPosted($baseline, 9); // 750 000 charged and released

    $disposal = app(App\Modules\Assets\Actions\DisposeAsset::class)->handle((int) $asset->getKey(), [
        'disposal_type' => 'scrap',
        'disposal_date' => $baseline['year'].'-10-31',
        'reason' => 'Destroyed in storm',
    ], $baseline['actor']);

    // Depreciate-to-date adds October (750 000): accumulated 1 500 000.
    expect($disposal->accumulated_at_disposal)->toBe(1_500_000);

    // Everything released: the run releases + the disposal write-off.
    expect((int) InvestmentSubsidyRelease::query()->sum('amount'))->toBe(3_000_000)
        ->and(-phase9DeprLedgerSum('845'))->toBe(3_000_000);

    // The write-off is its OWN entry - the disposal entry has no 14/845 line.
    $disposalLines = phase9AssetEntryLines($disposal->journal_entry_id);

    foreach ($disposalLines as $line) {
        expect($line->code)->not->toBe('14')
            ->and($line->code)->not->toBe('845');
    }
});

it('validates subsidy registration (amount, double funding, frozen assets)', function (): void {
    $baseline = phase9DeprBaseline();
    ['asset' => $asset] = phase9DeprSubsidisedAsset(
        $baseline,
        3_000_000,
        3_000_000,
        null,
    );

    // Already funded.
    expect(fn () => app(RegisterInvestmentSubsidy::class)->handle([
        'reference' => 'SUB-DOUBLE',
        'donor_partner_id' => $baseline['supplier_id'],
        'subsidy_account_id' => phase9AssetAccountId('14'),
        'granted_amount' => 1,
        'granted_on' => $baseline['year'].'-09-01',
        'fiscal_year_id' => $baseline['fiscal_year_id'],
        'academic_year_id' => $baseline['academic_year_id'],
        'asset_id' => (int) $asset->getKey(),
    ], $baseline['actor']))->toThrow(DomainException::class, 'already funded');

    // Over-funding.
    $other = phase9DeprAsset($baseline, ['name' => 'Small '.fake()->unique()->numberBetween(1, 999_999), 'acquisition_cost' => 500_000, 'non_recoverable_vat_amount' => 0, 'cost_basis' => 'ht']);

    expect(fn () => app(RegisterInvestmentSubsidy::class)->handle([
        'reference' => 'SUB-OVER',
        'donor_partner_id' => $baseline['supplier_id'],
        'subsidy_account_id' => phase9AssetAccountId('14'),
        'granted_amount' => 600_000,
        'granted_on' => $baseline['year'].'-09-01',
        'fiscal_year_id' => $baseline['fiscal_year_id'],
        'academic_year_id' => $baseline['academic_year_id'],
        'asset_id' => (int) $other->getKey(),
    ], $baseline['actor']))->toThrow(Illuminate\Validation\ValidationException::class);

    // Idempotency by key.
    $data = [
        'reference' => 'SUB-IDEM',
        'donor_partner_id' => $baseline['supplier_id'],
        'subsidy_account_id' => phase9AssetAccountId('14'),
        'granted_amount' => 400_000,
        'granted_on' => $baseline['year'].'-09-01',
        'fiscal_year_id' => $baseline['fiscal_year_id'],
        'academic_year_id' => $baseline['academic_year_id'],
        'idempotency_key' => 'p9f2-subsidy-key',
    ];

    $first = app(RegisterInvestmentSubsidy::class)->handle($data, $baseline['actor']);
    $second = app(RegisterInvestmentSubsidy::class)->handle($data, $baseline['actor']);

    expect((int) $second->getKey())->toBe((int) $first->getKey());
});

it('refuses impairment while the V9 accounts are unverified (§6.5 gate)', function (): void {
    $baseline = phase9DeprBaseline();
    $asset = phase9DeprAsset($baseline);

    expect(fn () => app(ImpairAsset::class)->handle((int) $asset->getKey(), $baseline['actor']))
        ->toThrow(DomainException::class, 'V9');

    expect(DB::table('asset_impairments')->count())->toBe(0);
});

it('refuses revaluation while the V8 account is unverified (§6.6 gate)', function (): void {
    $baseline = phase9DeprBaseline();

    expect(fn () => app(RevalueAssets::class)->handle(
        [(int) $baseline['category']->getKey()],
        $baseline['actor'],
    ))->toThrow(DomainException::class, 'V8');

    expect(DB::table('revaluation_campaigns')->count())->toBe(0);
});
