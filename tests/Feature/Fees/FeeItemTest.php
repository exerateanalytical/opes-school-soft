<?php

declare(strict_types=1);

use App\Modules\Fees\Actions\CreateFeeCategory;
use App\Modules\Fees\Actions\CreateFeeItem;
use App\Modules\Fees\Actions\CreateThirdPartyFund;
use App\Modules\Fees\Domain\AudienceDimension;
use App\Modules\Fees\Domain\BeneficiaryType;
use App\Modules\Fees\Domain\CollectionBasis;
use App\Modules\Fees\Domain\CriterionOperator;
use App\Modules\Fees\Domain\FeeRecurrence;
use App\Modules\Fees\Domain\RemittanceFrequency;
use App\Modules\Fees\Models\FeeCategory;
use App\Modules\Fees\Models\FeeItem;
use App\Modules\Fees\Models\ThirdPartyFund;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

if (! function_exists('feeItemUserAs')) {
    function feeItemUserAs(Role $role = Role::Accountant): User
    {
        (new Database\Seeders\RolePermissionSeeder())->run();
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user->fresh() ?? $user;
    }
}

if (! function_exists('feesItemAssertNotNull')) {
    /**
     * Null-narrowing assertion (see AccountingTestHelpers::assertNotNull):
     * throws on null and hands back a value PHPStan trusts as non-null.
     *
     * @template T
     *
     * @param  T|null  $value
     * @return T
     */
    function feesItemAssertNotNull(mixed $value, string $message = 'Expected a non-null value.'): mixed
    {
        if ($value === null) {
            throw new RuntimeException($message);
        }

        return $value;
    }
}

if (! function_exists('coaId')) {
    function coaId(string $code): int
    {
        return (int) DB::table('chart_of_accounts')->where('code', $code)->value('id');
    }
}

it('creates an own-revenue fee item on a class 7 account, with its category', function () {
    actingAs(feeItemUserAs());

    $category = app(CreateFeeCategory::class)->handle('TUI', 'Tuition', 'Scolarité');

    $item = app(CreateFeeItem::class)->handle(
        code: 'TUITION',
        name: 'Tuition',
        nameFr: 'Scolarité',
        feeCategoryId: (int) $category->id,
        collectionBasis: CollectionBasis::OwnRevenue,
        defaultRecurrence: FeeRecurrence::PerYear,
        revenueAccountId: coaId('706'),
    );

    expect($item->collection_basis)->toBe(CollectionBasis::OwnRevenue)
        ->and($item->revenue_account_id)->toBe(coaId('706'))
        ->and($item->third_party_fund_id)->toBeNull()
        ->and($item->fee_category_id)->toBe((int) $category->id);
});

it('denies fee configuration to the Bursar - the hands on the cash do not set the prices', function () {
    actingAs(feeItemUserAs(Role::Bursar));

    expect(fn () => app(CreateFeeCategory::class)->handle('X', 'X', 'X'))
        ->toThrow(AuthorizationException::class);
});

it('refuses an own-revenue item pointing at a non-class-7 account', function () {
    actingAs(feeItemUserAs());
    $category = FeeCategory::factory()->create();

    expect(fn () => app(CreateFeeItem::class)->handle(
        code: 'BAD',
        name: 'Bad',
        nameFr: 'Mauvais',
        feeCategoryId: (int) $category->id,
        collectionBasis: CollectionBasis::OwnRevenue,
        defaultRecurrence: FeeRecurrence::PerYear,
        revenueAccountId: coaId('4111'),
    ))->toThrow(DomainException::class, 'class 7');
});

it('refuses an own-revenue item that also names a third-party fund', function () {
    actingAs(feeItemUserAs());
    $category = FeeCategory::factory()->create();
    $fund = ThirdPartyFund::factory()->create();

    expect(fn () => app(CreateFeeItem::class)->handle(
        code: 'BAD2',
        name: 'Bad',
        nameFr: 'Mauvais',
        feeCategoryId: (int) $category->id,
        collectionBasis: CollectionBasis::OwnRevenue,
        defaultRecurrence: FeeRecurrence::PerYear,
        revenueAccountId: coaId('706'),
        thirdPartyFundId: (int) $fund->id,
    ))->toThrow(DomainException::class);
});

it('C5: an agent-collected item never carries a revenue account and is blocked until a fund exists', function () {
    actingAs(feeItemUserAs());
    $category = FeeCategory::factory()->create();

    // No fund at all: the blocking gate (04-fees §2.3 / 00-core §16).
    expect(fn () => app(CreateFeeItem::class)->handle(
        code: 'APEE',
        name: 'APEE contribution',
        nameFr: 'Cotisation APEE',
        feeCategoryId: (int) $category->id,
        collectionBasis: CollectionBasis::AgentForThirdParty,
        defaultRecurrence: FeeRecurrence::PerYear,
    ))->toThrow(DomainException::class, 'third-party fund');

    // A revenue account on an agent item is exactly the C5 misstatement.
    $fund = ThirdPartyFund::factory()->create();
    expect(fn () => app(CreateFeeItem::class)->handle(
        code: 'APEE',
        name: 'APEE contribution',
        nameFr: 'Cotisation APEE',
        feeCategoryId: (int) $category->id,
        collectionBasis: CollectionBasis::AgentForThirdParty,
        defaultRecurrence: FeeRecurrence::PerYear,
        revenueAccountId: coaId('706'),
        thirdPartyFundId: (int) $fund->id,
    ))->toThrow(DomainException::class, 'never touches a revenue account');
});

it('creates an agent-collected item once the fund is configured on a class 47 account', function () {
    actingAs(feeItemUserAs());
    $category = FeeCategory::factory()->create();

    $fund = app(CreateThirdPartyFund::class)->handle(
        code: 'APEE-HA',
        name: 'APEE Heritage Academy',
        nameFr: 'APEE Heritage Academy',
        beneficiaryType: BeneficiaryType::Apee,
        beneficiaryName: 'APEE Heritage Academy',
        liabilityAccountId: coaId('47'),
        remittanceFrequency: RemittanceFrequency::Termly,
    );

    $item = app(CreateFeeItem::class)->handle(
        code: 'APEE',
        name: 'APEE contribution',
        nameFr: 'Cotisation APEE',
        feeCategoryId: (int) $category->id,
        collectionBasis: CollectionBasis::AgentForThirdParty,
        defaultRecurrence: FeeRecurrence::PerYear,
        thirdPartyFundId: (int) $fund->id,
    );

    expect($item->collection_basis)->toBe(CollectionBasis::AgentForThirdParty)
        ->and($item->revenue_account_id)->toBeNull()
        ->and($item->third_party_fund_id)->toBe((int) $fund->id);
});

it('refuses a third-party fund held on a non-47 account', function () {
    actingAs(feeItemUserAs());

    expect(fn () => app(CreateThirdPartyFund::class)->handle(
        code: 'BADFUND',
        name: 'Bad fund',
        nameFr: 'Mauvais fonds',
        beneficiaryType: BeneficiaryType::ExamBoard,
        beneficiaryName: 'GCE Board',
        liabilityAccountId: coaId('706'),
        remittanceFrequency: RemittanceFrequency::Annual,
    ))->toThrow(DomainException::class, 'class 47');
});

it('refuses an agent item whose fund sits on a non-47 account even if the fund row exists', function () {
    actingAs(feeItemUserAs());
    $category = FeeCategory::factory()->create();

    // Planted past the Action (the fund table has no CHECK - the 47 shape
    // is Action-enforced), so CreateFeeItem must re-verify.
    $fund = ThirdPartyFund::factory()->create(['liability_account_id' => coaId('706')]);

    expect(fn () => app(CreateFeeItem::class)->handle(
        code: 'APEE2',
        name: 'APEE contribution',
        nameFr: 'Cotisation APEE',
        feeCategoryId: (int) $category->id,
        collectionBasis: CollectionBasis::AgentForThirdParty,
        defaultRecurrence: FeeRecurrence::PerYear,
        thirdPartyFundId: (int) $fund->id,
    ))->toThrow(DomainException::class, 'class 47');
});

it('chk_fee_items_basis holds at the database even when the Action layer is bypassed', function () {
    $category = FeeCategory::factory()->create();

    expect(fn () => DB::table('fee_items')->insert([
        'code' => 'RAW',
        'name' => 'Raw',
        'name_fr' => 'Brut',
        'fee_category_id' => $category->id,
        'collection_basis' => 'own_revenue',
        // own_revenue with NO revenue account: the CHECK must throw.
        'revenue_account_id' => null,
        'third_party_fund_id' => null,
        'recognition_method' => 'on_issue',
        'default_recurrence' => 'per_year',
        'is_refundable' => false,
        'is_mandatory' => true,
        'is_archived' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('persists audience and exclusion criteria with their conjunctive/disjunctive homes', function () {
    actingAs(feeItemUserAs());
    $category = FeeCategory::factory()->create();

    $item = app(CreateFeeItem::class)->handle(
        code: 'BOARD',
        name: 'Boarding',
        nameFr: 'Internat',
        feeCategoryId: (int) $category->id,
        collectionBasis: CollectionBasis::OwnRevenue,
        defaultRecurrence: FeeRecurrence::PerTerm,
        revenueAccountId: coaId('707'),
        audienceCriteria: [
            ['dimension' => AudienceDimension::BoardingStatus, 'operator' => CriterionOperator::In, 'values' => ['boarding']],
            ['dimension' => AudienceDimension::EnrollmentStatus, 'operator' => CriterionOperator::In, 'values' => ['new']],
        ],
        exclusionCriteria: [
            ['dimension' => AudienceDimension::ClassLevel, 'operator' => CriterionOperator::In, 'values' => [3, 4]],
        ],
    );

    $item->refresh();

    expect($item->audienceCriteria)->toHaveCount(2)
        ->and($item->exclusionCriteria)->toHaveCount(1)
        ->and(feesItemAssertNotNull($item->audienceCriteria[0])->dimension)->toBe(AudienceDimension::BoardingStatus)
        ->and(feesItemAssertNotNull($item->exclusionCriteria[0])->values_json)->toBe([3, 4]);
});

it('duplicate fee item codes collide on the unique index', function () {
    FeeItem::factory()->create(['code' => 'DUP']);

    expect(fn () => FeeItem::factory()->create(['code' => 'DUP']))
        ->toThrow(QueryException::class);
});
