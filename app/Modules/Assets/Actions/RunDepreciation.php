<?php

declare(strict_types=1);

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Domain\AssetPermission;
use App\Modules\Assets\Domain\DepreciationBasis;
use App\Modules\Assets\Domain\DepreciationCalculator;
use App\Modules\Assets\Domain\DepreciationRunStatus;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\DepreciationRun;
use App\Modules\Assets\Models\DepreciationSchedule;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 06-assets-stores.md §4.1/§4.3 - creates the (fiscal year, period) run and
 * calculates every asset's signed catch-up charge. draft → calculated.
 *
 *  - UNIQUE(fiscal_year_id, period_month) is the duplicate gate: a second
 *    run of the same period refuses and creates NOTHING (acceptance 1).
 *  - V1 gate: refuses while ANY unarchived category with a depreciating
 *    method has prorata_convention NULL - two schools must not diverge by
 *    accident; the accountant must declare a policy.
 *  - Per-asset configuration gaps (V3 681x expense account) become
 *    exceptions_json entries, not run failures: the register keeps moving
 *    and the report names what to fix.
 *  - charge = 0 produces NO schedule row and NO journal line (§4.3) - the
 *    idempotency is arithmetic, not a no-op guard.
 *
 * Nothing posts here; PostDepreciationRun owns the single journal entry.
 */
final class RunDepreciation
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(
        int $fiscalYearId,
        int $periodMonth,
        Actor $actor,
        ?string $idempotencyKey = null,
    ): DepreciationRun {
        Gate::authorize(AssetPermission::DEPRECIATE);

        return DB::transaction(function () use ($fiscalYearId, $periodMonth, $actor, $idempotencyKey): DepreciationRun {
            if ($idempotencyKey !== null) {
                $existing = DepreciationRun::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            /** @var DepreciationRun|null $duplicate */
            $duplicate = DepreciationRun::query()
                ->where('fiscal_year_id', $fiscalYearId)
                ->where('period_month', $periodMonth)
                ->lockForUpdate()
                ->first();

            if ($duplicate !== null) {
                throw new DomainException(sprintf(
                    'A depreciation run for this fiscal year and period %d already exists (run #%d, %s); one run per period (§4.1).',
                    $periodMonth,
                    (int) $duplicate->getKey(),
                    $duplicate->status->value,
                ));
            }

            // Sequential discipline: an unfinished run elsewhere would make
            // "Σ charges already posted" ambiguous. Finish or cancel first.
            /** @var DepreciationRun|null $open */
            $open = DepreciationRun::query()
                ->whereIn('status', [
                    DepreciationRunStatus::Draft->value,
                    DepreciationRunStatus::Calculated->value,
                    DepreciationRunStatus::Approved->value,
                ])
                ->lockForUpdate()
                ->first();

            if ($open !== null) {
                throw new DomainException(sprintf(
                    'Depreciation run #%d is still %s; post or cancel it before calculating another period.',
                    (int) $open->getKey(),
                    $open->status->value,
                ));
            }

            // V1 gate - blocking for ALL depreciation (§5.2).
            /** @var list<string> $unconfigured */
            $unconfigured = AssetCategory::query()
                ->where('depreciation_method', '<>', 'none')
                ->whereNull('prorata_convention')
                ->where('is_archived', false)
                ->orderBy('code')
                ->pluck('code')
                ->all();

            if ($unconfigured !== []) {
                throw new DomainException(
                    'NEEDS VERIFICATION (V1): prorata_convention is not declared for depreciating '
                    .'asset categories ['.implode(', ', $unconfigured).']. The accountant must choose '
                    .'a convention (daily / monthly / full_month / half_year) before any depreciation runs.'
                );
            }

            $periodEnd = $this->periodEnd($fiscalYearId, $periodMonth);

            $run = DepreciationRun::query()->create([
                'fiscal_year_id' => $fiscalYearId,
                'period_month' => $periodMonth,
                'status' => DepreciationRunStatus::Draft->value,
                'run_by' => $actor->id,
                'run_at' => now(),
                'idempotency_key' => $idempotencyKey,
            ]);

            $exceptions = [];
            $processed = 0;
            $totalCharge = 0;

            /** @var list<Asset> $assets */
            $assets = Asset::query()
                ->whereIn('depreciation_method', ['straight_line', 'declining_balance'])
                ->whereNotIn('status', ['draft', 'in_progress', 'disposed', 'written_off', 'lost'])
                ->whereNotNull('depreciation_start_date')
                ->whereDate('depreciation_start_date', '<=', $periodEnd->toDateString())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->all();

            foreach ($assets as $asset) {
                /** @var AssetCategory $category */
                $category = AssetCategory::query()->findOrFail($asset->asset_category_id);

                if ($category->depreciation_expense_account_id === null) {
                    $exceptions[] = [
                        'asset_id' => (int) $asset->getKey(),
                        'tag_number' => $asset->tag_number,
                        'reason' => "NEEDS VERIFICATION (V3): category '{$category->code}' has no depreciation "
                            .'expense account (681x) configured; asset skipped.',
                    ];

                    continue;
                }

                if ($asset->prorata_convention === null || $asset->useful_life_months === null
                    || $asset->depreciation_method === null || $asset->depreciation_start_date === null) {
                    $exceptions[] = [
                        'asset_id' => (int) $asset->getKey(),
                        'tag_number' => $asset->tag_number,
                        'reason' => 'Asset carries no complete depreciation policy snapshot; re-capitalise before running.',
                    ];

                    continue;
                }

                $posted = $this->postedToDate((int) $asset->getKey());

                $charge = DepreciationCalculator::charge(
                    $asset->depreciation_method,
                    $asset->prorata_convention,
                    $asset->acquisition_cost,
                    $asset->residual_value,
                    $asset->useful_life_months,
                    $category->declining_rate_bp,
                    $asset->depreciation_start_date,
                    $periodEnd->toDateString(),
                    $posted,
                );

                if ($charge === 0) {
                    continue; // §4.3 - no row, no line, idempotent.
                }

                $singlePeriod = DepreciationCalculator::entitlement(
                    $asset->depreciation_method,
                    $asset->prorata_convention,
                    $asset->acquisition_cost,
                    $asset->residual_value,
                    $asset->useful_life_months,
                    $category->declining_rate_bp,
                    $asset->depreciation_start_date,
                    $periodEnd->toDateString(),
                ) - DepreciationCalculator::entitlement(
                    $asset->depreciation_method,
                    $asset->prorata_convention,
                    $asset->acquisition_cost,
                    $asset->residual_value,
                    $asset->useful_life_months,
                    $category->declining_rate_bp,
                    $asset->depreciation_start_date,
                    $periodEnd->copy()->subMonthNoOverflow()->endOfMonth()->toDateString(),
                );

                DepreciationSchedule::query()->create([
                    'asset_id' => (int) $asset->getKey(),
                    'depreciation_run_id' => (int) $run->getKey(),
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
                        $periodEnd->copy(),
                        $asset->useful_life_months,
                    ),
                    'is_catch_up' => $charge !== $singlePeriod,
                ]);

                $processed++;
                $totalCharge += $charge;
            }

            // §4.7 / V10: the fiscal basis is NOT generated - tax_rate_bp
            // ships empty and unverified. Rows appear only for `accounting`.

            $run->forceFill([
                'status' => DepreciationRunStatus::Calculated->value,
                'assets_processed' => $processed,
                'total_charge' => $totalCharge,
                'exceptions_json' => $exceptions === [] ? null : $exceptions,
            ])->save();

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Assets',
                auditableType: DepreciationRun::class,
                auditableId: (int) $run->getKey(),
                after: [
                    'event' => 'calculated',
                    'fiscal_year_id' => $fiscalYearId,
                    'period_month' => $periodMonth,
                    'assets_processed' => $processed,
                    'total_charge' => $totalCharge,
                    'exceptions' => count($exceptions),
                ],
                actor: $actor,
            );

            return $run->refresh();
        });
    }

    /**
     * Last day of the run's period. period_month is 1-12 counted from the
     * fiscal year's start month (00-core §5).
     */
    private function periodEnd(int $fiscalYearId, int $periodMonth): Carbon
    {
        if ($periodMonth < 1 || $periodMonth > 12) {
            throw new DomainException('period_month must be between 1 and 12.');
        }

        /** @var object{starts_on: string}|null $fiscalYear */
        $fiscalYear = DB::table('fiscal_years')
            ->where('id', $fiscalYearId)
            ->first(['starts_on']);

        if ($fiscalYear === null) {
            throw new DomainException("Fiscal year {$fiscalYearId} does not exist.");
        }

        return Carbon::parse($fiscalYear->starts_on)
            ->startOfMonth()
            ->addMonthsNoOverflow($periodMonth - 1)
            ->endOfMonth()
            ->startOfDay();
    }

    /** Σ signed charges already recorded for the asset, accounting basis. */
    private function postedToDate(int $assetId): int
    {
        return (int) DepreciationSchedule::query()
            ->where('asset_id', $assetId)
            ->where('basis', DepreciationBasis::Accounting->value)
            ->sum('charge');
    }
}
