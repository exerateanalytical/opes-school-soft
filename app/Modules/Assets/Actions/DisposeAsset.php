<?php

declare(strict_types=1);

namespace App\Modules\Assets\Actions;

use App\Modules\Accounting\Actions\PostFromEvent;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Assets\Domain\AssetPermission;
use App\Modules\Assets\Domain\AssetStatus;
use App\Modules\Assets\Domain\DepreciationBasis;
use App\Modules\Assets\Domain\DepreciationCalculator;
use App\Modules\Assets\Domain\DisposalSettlement;
use App\Modules\Assets\Domain\DisposalType;
use App\Modules\Assets\Domain\SubsidyStatus;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\AssetDisposal;
use App\Modules\Assets\Models\DepreciationSchedule;
use App\Modules\Assets\Models\InvestmentSubsidy;
use App\Modules\Assets\Models\InvestmentSubsidyRelease;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * 06-assets-stores.md §4.5/§6.1/§6.2 - the GROSS disposal. One transaction:
 *
 *  1. §4.5 depreciate-to-date under the asset's own convention (its own
 *     `asset.depreciated` entry + run-less schedule row) BEFORE the NBV is
 *     derived - unless the period already has a posted row.
 *  2. One `asset.disposed` entry per asset: Dr 28x accumulated, Dr 812/816
 *     NBV, Cr class-2 gross cost, plus Dr 485/treasury + Cr 822/826 for
 *     proceeds. Zero-amount legs are OMITTED by skip_if_zero (a
 *     fully-depreciated scrap posts Dr 28x / Cr 2xx only) - never a zero
 *     line (00-core §10.3).
 *  3. gain_or_loss is the GENERATED column on the disposal row: it appears
 *     in NO journal line (C7 - the v1 net-posting defect, closed).
 *  4. §4.6 component cascade: every descendant is disposed in the same
 *     transaction with zero proceeds, each with its own gross legs.
 *  5. §6.4 - a subsidised asset's unreleased balance is written off to the
 *     845 release account in the same transaction (skipped, with an audit
 *     note, while 845 is unconfigured - V5).
 */
final class DisposeAsset
{
    public function __construct(
        private readonly PostFromEvent $post,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param  array{disposal_type: string, disposal_date: string, proceeds_amount?: int, buyer_partner_id?: int|null, settlement?: string|null, settlement_account_id?: int|null, reason: string, document_ref?: string|null, idempotency_key?: string|null}  $data
     */
    public function handle(int $assetId, array $data, Actor $actor): AssetDisposal
    {
        Gate::authorize(AssetPermission::DISPOSE);

        return DB::transaction(function () use ($assetId, $data, $actor): AssetDisposal {
            $idempotencyKey = $data['idempotency_key'] ?? null;

            if ($idempotencyKey !== null) {
                $existing = AssetDisposal::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            /** @var Asset|null $asset */
            $asset = Asset::query()->lockForUpdate()->find($assetId);

            if ($asset === null) {
                throw new DomainException("Asset {$assetId} does not exist.");
            }

            if ($asset->status->isFrozen()) {
                throw new DomainException(
                    "Asset '{$asset->tag_number}' is {$asset->status->value} (A12); it cannot be disposed again."
                );
            }

            if (in_array($asset->status, [AssetStatus::Draft, AssetStatus::InProgress], true)) {
                throw new DomainException(
                    "Asset '{$asset->tag_number}' is {$asset->status->value}; only a capitalised asset can be disposed."
                );
            }

            $type = DisposalType::from($data['disposal_type']);
            $proceeds = $data['proceeds_amount'] ?? 0;
            $buyerId = $data['buyer_partner_id'] ?? null;
            $settlementRaw = $data['settlement'] ?? null;
            $settlement = $settlementRaw === null ? null : DisposalSettlement::from($settlementRaw);
            $reason = trim($data['reason']);

            if ($proceeds < 0) {
                throw ValidationException::withMessages([
                    'proceeds_amount' => 'Disposal proceeds cannot be negative.',
                ]);
            }

            if ($reason === '') {
                throw ValidationException::withMessages([
                    'reason' => 'A disposal requires a reason.',
                ]);
            }

            if ($type === DisposalType::Sale && $buyerId === null) {
                throw ValidationException::withMessages([
                    'buyer_partner_id' => 'A sale requires the buyer partner (§6.1).',
                ]);
            }

            if ($proceeds > 0 && $settlement === null) {
                throw ValidationException::withMessages([
                    'settlement' => 'Proceeds require a settlement route (receivable / cash / bank / mobile money).',
                ]);
            }

            if ($data['disposal_date'] < $asset->acquisition_date) {
                throw ValidationException::withMessages([
                    'disposal_date' => 'An asset cannot be disposed before it was acquired (A6).',
                ]);
            }

            $settlementAccountId = $this->resolveSettlementAccount(
                $settlement,
                $data['settlement_account_id'] ?? null,
            );

            // §4.6 - the cascade set: the asset plus every descendant, each
            // locked; the root carries the proceeds, components go at zero.
            $family = [$asset, ...$this->lockedDescendants((int) $asset->getKey())];

            $rootDisposal = null;

            foreach ($family as $member) {
                // The buyer stays on every cascaded row (a sold parent's
                // components leave with the same buyer, at zero proceeds).
                $disposal = $this->disposeOne(
                    $member,
                    $type,
                    $data['disposal_date'],
                    $member->getKey() === $asset->getKey() ? $proceeds : 0,
                    $buyerId,
                    $member->getKey() === $asset->getKey() ? $settlement : null,
                    $settlementAccountId,
                    $reason,
                    $data['document_ref'] ?? null,
                    $member->getKey() === $asset->getKey() ? $idempotencyKey : null,
                    $actor,
                );

                if ($member->getKey() === $asset->getKey()) {
                    $rootDisposal = $disposal;
                }
            }

            assert($rootDisposal instanceof AssetDisposal);

            return $rootDisposal;
        });
    }

    /**
     * @return list<Asset>
     */
    private function lockedDescendants(int $assetId): array
    {
        $found = [];
        $frontier = [$assetId];

        while ($frontier !== []) {
            /** @var list<Asset> $children */
            $children = Asset::query()
                ->whereIn('parent_asset_id', $frontier)
                ->whereNotIn('status', ['disposed', 'written_off', 'lost'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->all();

            $frontier = [];

            foreach ($children as $child) {
                $found[] = $child;
                $frontier[] = (int) $child->getKey();
            }
        }

        return $found;
    }

    private function disposeOne(
        Asset $asset,
        DisposalType $type,
        string $disposalDate,
        int $proceeds,
        ?int $buyerId,
        ?DisposalSettlement $settlement,
        int $settlementAccountId,
        string $reason,
        ?string $documentRef,
        ?string $idempotencyKey,
        Actor $actor,
    ): AssetDisposal {
        /** @var AssetCategory $category */
        $category = AssetCategory::query()->findOrFail($asset->asset_category_id);

        // §4.5 - depreciate up to and including the disposal date, BEFORE
        // deriving the NBV, in this same transaction.
        $this->depreciateToDate($asset, $category, $disposalDate, $actor);

        $accumulated = (int) DepreciationSchedule::query()
            ->where('asset_id', (int) $asset->getKey())
            ->where('basis', DepreciationBasis::Accounting->value)
            ->sum('charge');

        $nbv = $asset->acquisition_cost - $accumulated;

        $entry = $this->post->handle(
            PostingEvent::AssetDisposed->value,
            [
                'asset' => [
                    'cost' => $asset->acquisition_cost,
                    'accumulated_depreciation' => $accumulated,
                    'net_book_value' => $nbv,
                    'proceeds' => $proceeds,
                    'reference' => $asset->tag_number,
                    'partner' => $buyerId === null ? null : ['type' => 'supplier', 'id' => $buyerId],
                    'asset_account_id' => $category->asset_account_id,
                    'depreciation_account_id' => $category->accumulated_depreciation_account_id,
                    'disposal_value_account_id' => $category->disposal_nbv_account_id,
                    'disposal_proceeds_account_id' => $category->disposal_proceeds_account_id,
                    'settlement_account_id' => $settlementAccountId,
                ],
            ],
            $disposalDate,
            $actor,
            $asset->tag_number,
        );

        $disposal = AssetDisposal::query()->create([
            'asset_id' => (int) $asset->getKey(),
            'disposal_type' => $type->value,
            'disposal_date' => $disposalDate,
            'proceeds_amount' => $proceeds,
            'buyer_partner_id' => $buyerId,
            'settlement' => $settlement?->value,
            'nbv_at_disposal' => $nbv,
            'accumulated_at_disposal' => $accumulated,
            'approved_by' => $actor->id,
            'approved_at' => now(),
            'reason' => $reason,
            'document_ref' => $documentRef,
            'journal_entry_id' => (int) $entry->getKey(),
            'idempotency_key' => $idempotencyKey,
        ]);

        $asset->forceFill([
            'disposal_id' => (int) $disposal->getKey(),
            'status' => AssetStatus::Disposed->value,
        ])->save();

        $this->writeOffSubsidy($asset, $disposalDate, $actor);

        $this->audit->handle(
            action: AuditAction::Updated,
            module: 'Assets',
            auditableType: Asset::class,
            auditableId: (int) $asset->getKey(),
            after: [
                'event' => 'disposed',
                'disposal_type' => $type->value,
                'proceeds_amount' => $proceeds,
                'nbv_at_disposal' => $nbv,
                'accumulated_at_disposal' => $accumulated,
                'journal_entry_id' => (int) $entry->getKey(),
            ],
            actor: $actor,
        );

        // Refresh so the GENERATED gain_or_loss column is hydrated.
        return $disposal->refresh();
    }

    /**
     * §4.5 - the final charge under the asset's own convention. Skipped
     * when the disposal period already carries a posted schedule row (a
     * scheduled run for P that later executes then finds charge = 0).
     */
    private function depreciateToDate(
        Asset $asset,
        AssetCategory $category,
        string $disposalDate,
        Actor $actor,
    ): void {
        if ($asset->depreciation_method === null || ! $asset->depreciation_method->depreciates()
            || $asset->prorata_convention === null || $asset->useful_life_months === null
            || $asset->depreciation_start_date === null
            || $disposalDate < $asset->depreciation_start_date) {
            return;
        }

        if ($category->depreciation_expense_account_id === null) {
            // V3 - never guess; the disposal still proceeds on the charges
            // already posted.
            return;
        }

        $posted = (int) DepreciationSchedule::query()
            ->where('asset_id', (int) $asset->getKey())
            ->where('basis', DepreciationBasis::Accounting->value)
            ->sum('charge');

        $charge = DepreciationCalculator::charge(
            $asset->depreciation_method,
            $asset->prorata_convention,
            $asset->acquisition_cost,
            $asset->residual_value,
            $asset->useful_life_months,
            $category->declining_rate_bp,
            $asset->depreciation_start_date,
            $disposalDate,
            $posted,
        );

        if ($charge <= 0) {
            return;
        }

        [$fiscalYearId, $periodMonth] = $this->fiscalPeriodOf($disposalDate);

        $alreadyRun = DepreciationSchedule::query()
            ->where('asset_id', (int) $asset->getKey())
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('period_month', $periodMonth)
            ->where('basis', DepreciationBasis::Accounting->value)
            ->exists();

        if ($alreadyRun) {
            return;
        }

        $entry = $this->post->handle(
            PostingEvent::AssetDepreciated->value,
            [
                'run' => [
                    'total_charge' => $charge,
                    'reference' => 'DISP-'.$asset->tag_number,
                    'lines' => [
                        [
                            'charge' => $charge,
                            'expense_account_id' => (int) $category->depreciation_expense_account_id,
                            'accumulated_account_id' => $category->accumulated_depreciation_account_id,
                            'reference' => $asset->tag_number,
                        ],
                    ],
                ],
            ],
            $disposalDate,
            $actor,
            'DISP-'.$asset->tag_number,
        );

        DepreciationSchedule::query()->create([
            'asset_id' => (int) $asset->getKey(),
            'depreciation_run_id' => null,
            'fiscal_year_id' => $fiscalYearId,
            'period_month' => $periodMonth,
            'basis' => DepreciationBasis::Accounting->value,
            'opening_accumulated' => $posted,
            'charge' => $charge,
            'closing_accumulated' => $posted + $charge,
            'net_book_value' => $asset->acquisition_cost - ($posted + $charge),
            'depreciable_base' => $asset->acquisition_cost - $asset->residual_value,
            'months_elapsed' => DepreciationCalculator::monthsElapsed(
                $asset->prorata_convention,
                Carbon::parse($asset->depreciation_start_date)->startOfDay(),
                Carbon::parse($disposalDate)->startOfDay(),
                $asset->useful_life_months,
            ),
            'is_catch_up' => true,
            'journal_entry_id' => (int) $entry->getKey(),
        ]);
    }

    /**
     * §6.4 - on disposal before full release, the unreleased balance goes
     * to the same 845 account, in this transaction, NOT netted into the
     * 812/822 legs. Skipped while 845 is unconfigured (V5).
     */
    private function writeOffSubsidy(Asset $asset, string $disposalDate, Actor $actor): void
    {
        if ($asset->investment_subsidy_id === null) {
            return;
        }

        /** @var InvestmentSubsidy $subsidy */
        $subsidy = InvestmentSubsidy::query()
            ->lockForUpdate()
            ->findOrFail($asset->investment_subsidy_id);

        if ($subsidy->status !== SubsidyStatus::Active) {
            return;
        }

        if ($subsidy->release_income_account_id === null) {
            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Assets',
                auditableType: InvestmentSubsidy::class,
                auditableId: (int) $subsidy->getKey(),
                after: [
                    'event' => 'release_skipped_unconfigured',
                    'reason' => 'NEEDS VERIFICATION (V5): no release income account (845); unreleased balance stays in 14.',
                ],
                actor: $actor,
            );

            return;
        }

        $released = (int) InvestmentSubsidyRelease::query()
            ->where('investment_subsidy_id', (int) $subsidy->getKey())
            ->sum('amount');

        $unreleased = $subsidy->granted_amount - $released;

        if ($unreleased <= 0) {
            return;
        }

        [$fiscalYearId, $periodMonth] = $this->fiscalPeriodOf($disposalDate);

        $entry = $this->post->handle(
            PostingEvent::AssetSubsidyReleased->value,
            [
                'subsidy' => [
                    'amount' => $unreleased,
                    'reference' => $subsidy->reference,
                    'partner' => ['type' => 'supplier', 'id' => $subsidy->donor_partner_id],
                    'subsidy_account_id' => $subsidy->subsidy_account_id,
                    'counterpart_account_id' => (int) $subsidy->release_income_account_id,
                ],
            ],
            $disposalDate,
            $actor,
            $subsidy->reference,
        );

        InvestmentSubsidyRelease::query()->create([
            'investment_subsidy_id' => (int) $subsidy->getKey(),
            'asset_id' => (int) $asset->getKey(),
            'depreciation_run_id' => null,
            'fiscal_year_id' => $fiscalYearId,
            'period_month' => $periodMonth,
            'amount' => $unreleased,
            'journal_entry_id' => (int) $entry->getKey(),
        ]);

        $subsidy->forceFill(['status' => SubsidyStatus::FullyReleased->value])->save();
    }

    /**
     * Resolve where proceeds land: 485 Créances sur cessions (verified)
     * for `receivable`, an explicit treasury account otherwise. Defaults
     * to 485 for the zero-proceeds case so the payload is complete (the
     * settlement lines are skip_if_zero and never materialise).
     */
    private function resolveSettlementAccount(
        ?DisposalSettlement $settlement,
        ?int $settlementAccountId,
    ): int {
        if ($settlement !== null && $settlement !== DisposalSettlement::Receivable) {
            if ($settlementAccountId === null) {
                throw new DomainException(
                    "A {$settlement->value} settlement requires an explicit treasury account."
                );
            }

            return $settlementAccountId;
        }

        $id = DB::table('chart_of_accounts')->where('code', '485')->value('id');

        if ($id === null) {
            throw new DomainException(
                "Account 485 (Créances sur cessions d'immobilisations) is missing from the chart."
            );
        }

        return (int) $id;
    }

    /**
     * The fiscal year and 1-12 period containing a date.
     *
     * @return array{int, int}
     */
    private function fiscalPeriodOf(string $date): array
    {
        /** @var object{id: int|string, starts_on: string}|null $fiscalYear */
        $fiscalYear = DB::table('fiscal_years')
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->orderByDesc('starts_on')
            ->first(['id', 'starts_on']);

        if ($fiscalYear === null) {
            throw new DomainException("No fiscal year covers {$date}; open the year before disposing.");
        }

        $start = Carbon::parse($fiscalYear->starts_on)->startOfMonth();
        $target = Carbon::parse($date)->startOfMonth();

        $month = ($target->year - $start->year) * 12 + ($target->month - $start->month) + 1;

        return [(int) $fiscalYear->id, $month];
    }
}
