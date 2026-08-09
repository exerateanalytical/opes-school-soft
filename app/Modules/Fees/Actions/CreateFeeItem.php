<?php

declare(strict_types=1);

namespace App\Modules\Fees\Actions;

use App\Modules\Fees\Domain\AudienceDimension;
use App\Modules\Fees\Domain\CollectionBasis;
use App\Modules\Fees\Domain\CriterionOperator;
use App\Modules\Fees\Domain\FeeRecurrence;
use App\Modules\Fees\Domain\RecognitionMethod;
use App\Modules\Fees\Models\FeeCategory;
use App\Modules\Fees\Models\FeeItem;
use App\Modules\Fees\Models\ThirdPartyFund;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 04-fees.md §2.2 / §2.3 (C5). The friendlier, earlier layer over
 * chk_fee_items_basis, plus the two rules a CHECK cannot carry:
 *
 *  - an own-revenue item must point at a class 7 account;
 *  - an agent item's fund must hold a class 47 liability account -
 *    and per §23 the seed ships EMPTY, so an agent item simply cannot be
 *    created until the accountant has configured the fund (00-core §16
 *    blocking-gate discipline).
 */
final class CreateFeeItem
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @param  list<array{dimension: AudienceDimension, operator: CriterionOperator, values: list<int|string>}>  $audienceCriteria
     * @param  list<array{dimension: AudienceDimension, operator: CriterionOperator, values: list<int|string>}>  $exclusionCriteria
     */
    public function handle(
        string $code,
        string $name,
        string $nameFr,
        int $feeCategoryId,
        CollectionBasis $collectionBasis,
        FeeRecurrence $defaultRecurrence,
        ?int $revenueAccountId = null,
        ?int $thirdPartyFundId = null,
        RecognitionMethod $recognitionMethod = RecognitionMethod::OnIssue,
        ?int $taxCodeId = null,
        bool $isRefundable = false,
        bool $isMandatory = true,
        ?string $assetOrServiceNote = null,
        array $audienceCriteria = [],
        array $exclusionCriteria = [],
        ?Actor $actor = null,
    ): FeeItem {
        Gate::authorize(Permission::FeeConfigure->value);

        $category = FeeCategory::query()->findOrFail($feeCategoryId);

        if ($category->is_archived) {
            throw new DomainException('Cannot create a fee item under an archived category.');
        }

        match ($collectionBasis) {
            CollectionBasis::OwnRevenue => $this->assertOwnRevenue($revenueAccountId, $thirdPartyFundId),
            CollectionBasis::AgentForThirdParty => $this->assertAgent($revenueAccountId, $thirdPartyFundId),
        };

        return DB::transaction(function () use (
            $code, $name, $nameFr, $feeCategoryId, $collectionBasis, $defaultRecurrence,
            $revenueAccountId, $thirdPartyFundId, $recognitionMethod, $taxCodeId,
            $isRefundable, $isMandatory, $assetOrServiceNote,
            $audienceCriteria, $exclusionCriteria, $actor
        ): FeeItem {
            $item = FeeItem::query()->create([
                'code' => $code,
                'name' => $name,
                'name_fr' => $nameFr,
                'fee_category_id' => $feeCategoryId,
                'collection_basis' => $collectionBasis,
                'third_party_fund_id' => $thirdPartyFundId,
                'revenue_account_id' => $revenueAccountId,
                'recognition_method' => $recognitionMethod,
                'tax_code_id' => $taxCodeId,
                'is_refundable' => $isRefundable,
                'is_mandatory' => $isMandatory,
                'default_recurrence' => $defaultRecurrence,
                'asset_or_service_note' => $assetOrServiceNote,
                'is_archived' => false,
            ]);

            foreach ($audienceCriteria as $criterion) {
                $item->audienceCriteria()->create([
                    'dimension' => $criterion['dimension'],
                    'operator' => $criterion['operator'],
                    'values_json' => $criterion['values'],
                ]);
            }

            foreach ($exclusionCriteria as $criterion) {
                $item->exclusionCriteria()->create([
                    'dimension' => $criterion['dimension'],
                    'operator' => $criterion['operator'],
                    'values_json' => $criterion['values'],
                ]);
            }

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Fees',
                auditableType: FeeItem::class,
                auditableId: (int) $item->getKey(),
                after: [
                    'code' => $code,
                    'name' => $name,
                    'collection_basis' => $collectionBasis->value,
                    'revenue_account_id' => $revenueAccountId,
                    'third_party_fund_id' => $thirdPartyFundId,
                ],
                actor: $actor ?? auth()->user()?->toAuditActor() ?? Actor::system(),
            );

            return $item;
        });
    }

    private function assertOwnRevenue(?int $revenueAccountId, ?int $thirdPartyFundId): void
    {
        if ($revenueAccountId === null) {
            throw new DomainException('An own-revenue fee item requires a revenue account.');
        }

        if ($thirdPartyFundId !== null) {
            throw new DomainException('An own-revenue fee item cannot reference a third-party fund.');
        }

        // Query builder, not an Accounting model import - ModuleBoundaryTest.
        $code = DB::table('chart_of_accounts')->where('id', $revenueAccountId)->value('code');

        if (! is_string($code)) {
            throw new DomainException('The revenue account does not exist.');
        }

        if (! str_starts_with($code, '7')) {
            throw new DomainException(sprintf(
                'C5: an own-revenue fee item must post to a class 7 account; %s is not one.',
                $code,
            ));
        }
    }

    private function assertAgent(?int $revenueAccountId, ?int $thirdPartyFundId): void
    {
        if ($revenueAccountId !== null) {
            throw new DomainException('C5: an agent-collected fee item never touches a revenue account.');
        }

        if ($thirdPartyFundId === null) {
            throw new DomainException(
                'An agent-collected fee item requires a third-party fund. None is configured: '
                .'the accountant must create the fund (and choose its class 47 liability account) first.'
            );
        }

        $fund = ThirdPartyFund::query()->find($thirdPartyFundId);

        if ($fund === null) {
            throw new DomainException('The third-party fund does not exist.');
        }

        $code = DB::table('chart_of_accounts')->where('id', $fund->liability_account_id)->value('code');

        if (! is_string($code) || ! str_starts_with($code, '47')) {
            throw new DomainException(
                'C5: the fund\'s liability account must be a class 47 account (Débiteurs et créditeurs divers).'
            );
        }
    }
}
