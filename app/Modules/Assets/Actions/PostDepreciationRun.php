<?php

declare(strict_types=1);

namespace App\Modules\Assets\Actions;

use App\Modules\Accounting\Actions\PostFromEvent;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Assets\Domain\AssetPermission;
use App\Modules\Assets\Domain\DepreciationRunStatus;
use App\Modules\Assets\Domain\SubsidyReleaseCalculator;
use App\Modules\Assets\Domain\SubsidyStatus;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\DepreciationRun;
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

/**
 * 06-assets-stores.md §4 - approved → posted. ONE journal entry for the
 * whole run (`asset.depreciated`, Dr 681x / Cr 28x per asset via the
 * iterating rule), stamped onto the run and every schedule row.
 *
 *  - Catch-up value date (§4.4 / AUDCIF Art. 22): the entry lands in the
 *    run's open period but carries value_date = the earliest
 *    depreciation_start_date among catch-up rows.
 *  - Subsidy releases (§6.4) post IN THE SAME RUN via
 *    `asset.subsidy.released`, one entry per (subsidy, asset), guarded by
 *    UNIQUE(subsidy, asset, run). A subsidy whose 845 release account is
 *    unconfigured (V5) is SKIPPED WITH AN EXCEPTION and the asset still
 *    depreciates - the subsidy sits in 14 until configured, never guessed.
 */
final class PostDepreciationRun
{
    public function __construct(
        private readonly PostFromEvent $post,
        private readonly WriteAuditEntry $audit,
    ) {}

    public function handle(int $runId, Actor $actor): DepreciationRun
    {
        Gate::authorize(AssetPermission::DEPRECIATE);

        return DB::transaction(function () use ($runId, $actor): DepreciationRun {
            /** @var DepreciationRun|null $run */
            $run = DepreciationRun::query()->lockForUpdate()->find($runId);

            if ($run === null) {
                throw new DomainException("Depreciation run {$runId} does not exist.");
            }

            if ($run->status !== DepreciationRunStatus::Approved) {
                throw new DomainException(
                    "Depreciation run #{$runId} is {$run->status->value}; only an approved run can post."
                );
            }

            /** @var list<DepreciationSchedule> $rows */
            $rows = DepreciationSchedule::query()
                ->where('depreciation_run_id', $runId)
                ->where('basis', 'accounting')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->all();

            $periodEnd = $this->periodEnd($run);
            $reference = sprintf('DEPR-%d-%02d', $run->fiscal_year_id, $run->period_month);

            $entryId = null;

            if ($rows !== []) {
                $entryId = $this->postCharge($run, $rows, $periodEnd, $reference, $actor);
            }

            $affected = DepreciationRun::query()
                ->whereKey($runId)
                ->where('status', DepreciationRunStatus::Approved->value)
                ->update([
                    'status' => DepreciationRunStatus::Posted->value,
                    'journal_entry_id' => $entryId,
                ]);

            if ($affected !== 1) {
                throw new DomainException(
                    "Depreciation run #{$runId} changed state concurrently; posting aborted."
                );
            }

            if ($entryId !== null) {
                DepreciationSchedule::query()
                    ->where('depreciation_run_id', $runId)
                    ->where('basis', 'accounting')
                    ->update(['journal_entry_id' => $entryId]);
            }

            // §6.4 - the mirrored quote-part releases, same run.
            $this->releaseSubsidies($run, $rows, $periodEnd, $actor);

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Assets',
                auditableType: DepreciationRun::class,
                auditableId: $runId,
                before: ['status' => DepreciationRunStatus::Approved->value],
                after: [
                    'status' => DepreciationRunStatus::Posted->value,
                    'journal_entry_id' => $entryId,
                    'total_charge' => $run->total_charge,
                ],
                actor: $actor,
            );

            return $run->refresh();
        });
    }

    /**
     * The single `asset.depreciated` entry: one Dr/Cr pair per asset.
     *
     * @param  list<DepreciationSchedule>  $rows
     */
    private function postCharge(
        DepreciationRun $run,
        array $rows,
        Carbon $periodEnd,
        string $reference,
        Actor $actor,
    ): int {
        $lines = [];
        $total = 0;
        $valueDate = null;

        foreach ($rows as $row) {
            /** @var Asset $asset */
            $asset = Asset::query()->findOrFail($row->asset_id);
            /** @var AssetCategory $category */
            $category = AssetCategory::query()->findOrFail($asset->asset_category_id);

            if ($category->depreciation_expense_account_id === null) {
                throw new DomainException(
                    "NEEDS VERIFICATION (V3): category '{$category->code}' lost its depreciation expense "
                    .'account between calculation and posting; configure it before posting.'
                );
            }

            $lines[] = [
                'charge' => $row->charge,
                'expense_account_id' => (int) $category->depreciation_expense_account_id,
                'accumulated_account_id' => (int) $category->accumulated_depreciation_account_id,
                'reference' => $asset->tag_number,
            ];

            $total += $row->charge;

            if ($row->is_catch_up && $asset->depreciation_start_date !== null) {
                $start = $asset->depreciation_start_date;
                $valueDate = $valueDate === null || $start < $valueDate ? $start : $valueDate;
            }
        }

        $entry = $this->post->handle(
            PostingEvent::AssetDepreciated->value,
            [
                'run' => [
                    'total_charge' => $total,
                    'reference' => $reference,
                    'lines' => $lines,
                ],
            ],
            $periodEnd->toDateString(),
            $actor,
            $reference,
            $valueDate,
        );

        return (int) $entry->getKey();
    }

    /**
     * §6.4 - release(T) = entitlement(Σ charge to date) − Σ released, per
     * subsidised asset in this run. Idempotent by UNIQUE(subsidy, asset,
     * run) and by the entitlement arithmetic itself.
     *
     * @param  list<DepreciationSchedule>  $rows
     */
    private function releaseSubsidies(
        DepreciationRun $run,
        array $rows,
        Carbon $periodEnd,
        Actor $actor,
    ): void {
        $exceptions = $run->exceptions_json ?? [];
        $changed = false;

        foreach ($rows as $row) {
            /** @var Asset $asset */
            $asset = Asset::query()->findOrFail($row->asset_id);

            if ($asset->investment_subsidy_id === null) {
                continue;
            }

            /** @var InvestmentSubsidy $subsidy */
            $subsidy = InvestmentSubsidy::query()
                ->lockForUpdate()
                ->findOrFail($asset->investment_subsidy_id);

            if ($subsidy->status !== SubsidyStatus::Active) {
                continue; // fully released or clawed back - nothing owed.
            }

            if ($subsidy->release_income_account_id === null) {
                $exceptions[] = [
                    'asset_id' => (int) $asset->getKey(),
                    'tag_number' => $asset->tag_number,
                    'reason' => "NEEDS VERIFICATION (V5): subsidy '{$subsidy->reference}' has no release "
                        .'income account (845) configured; release skipped, subsidy remains in 14.',
                ];
                $changed = true;

                continue;
            }

            $released = (int) InvestmentSubsidyRelease::query()
                ->where('investment_subsidy_id', (int) $subsidy->getKey())
                ->sum('amount');

            $amount = SubsidyReleaseCalculator::release(
                $subsidy->granted_amount,
                $row->closing_accumulated,
                $row->depreciable_base,
                $released,
            );

            if ($amount === 0) {
                continue;
            }

            $entry = $this->post->handle(
                PostingEvent::AssetSubsidyReleased->value,
                [
                    'subsidy' => [
                        'amount' => $amount,
                        'reference' => $subsidy->reference,
                        'partner' => ['type' => 'supplier', 'id' => $subsidy->donor_partner_id],
                        'subsidy_account_id' => $subsidy->subsidy_account_id,
                        'counterpart_account_id' => (int) $subsidy->release_income_account_id,
                    ],
                ],
                $periodEnd->toDateString(),
                $actor,
                $subsidy->reference,
            );

            InvestmentSubsidyRelease::query()->create([
                'investment_subsidy_id' => (int) $subsidy->getKey(),
                'asset_id' => (int) $asset->getKey(),
                'depreciation_run_id' => (int) $run->getKey(),
                'fiscal_year_id' => $run->fiscal_year_id,
                'period_month' => $run->period_month,
                'amount' => $amount,
                'journal_entry_id' => (int) $entry->getKey(),
            ]);

            if ($released + $amount >= $subsidy->granted_amount) {
                $subsidy->forceFill([
                    'status' => SubsidyStatus::FullyReleased->value,
                ])->save();
            }
        }

        if ($changed) {
            $run->forceFill(['exceptions_json' => $exceptions])->save();
        }
    }

    private function periodEnd(DepreciationRun $run): Carbon
    {
        /** @var object{starts_on: string} $fiscalYear */
        $fiscalYear = DB::table('fiscal_years')
            ->where('id', $run->fiscal_year_id)
            ->first(['starts_on']);

        return Carbon::parse($fiscalYear->starts_on)
            ->startOfMonth()
            ->addMonthsNoOverflow($run->period_month - 1)
            ->endOfMonth()
            ->startOfDay();
    }
}
