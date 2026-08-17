<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\Books;

use App\Modules\Accounting\Actions\Statements\BuildFinancialStatements;
use App\Modules\Inventory\Domain\StockTakeStatus;
use Illuminate\Support\Facades\DB;

/**
 * docs/specs/02-accounting.md §14.2 - the livre d'inventaire's content: the
 * Bilan, the Compte de résultat, the Tableau des flux de trésorerie
 * (transcribed unchanged from `Actions\Statements\BuildFinancialStatements`,
 * the SAME figures the Statements screen shows), plus the summary of the
 * physical inventory this book alone carries - stock counts with quantities
 * and valuations, and the fixed-asset inventory.
 *
 * Read-only, no audit entry of its own; `GenerateStatutoryBook` is what
 * turns this into a hashed, immutable book row.
 */
final readonly class BuildLivreInventaireContent
{
    public function __construct(private BuildFinancialStatements $statements) {}

    /**
     * @return array{
     *     bilan: array<string, mixed>,
     *     resultat: array<string, mixed>,
     *     flux: array<string, mixed>,
     *     stock: array{
     *         basis: string,
     *         takes: list<array{reference: string, count_date: string, store_location: string, is_full_count: bool}>,
     *         lines: list<array{item_code: string, item_name: string, system_quantity: string, counted_quantity: string|null, variance_quantity: string|null, system_value: int, variance_value: int|null}>,
     *         total_system_value: int,
     *         total_variance_value: int,
     *         has_data: bool,
     *     },
     *     assets: array{
     *         basis: string,
     *         lines: list<array{tag_number: string, name: string, acquisition_date: string, acquisition_cost: int, accumulated_depreciation: int, net_book_value: int}>,
     *         total_acquisition_cost: int,
     *         total_accumulated_depreciation: int,
     *         total_net_book_value: int,
     *         has_data: bool,
     *     },
     * }
     */
    public function handle(int $fiscalYearId, string $yearStart, string $yearEnd): array
    {
        return [
            'bilan' => $this->statements->bilan($fiscalYearId, $yearStart, $yearEnd),
            'resultat' => $this->statements->resultat($fiscalYearId, $yearStart, $yearEnd),
            'flux' => $this->statements->flux($fiscalYearId, ['from' => $yearStart, 'to' => $yearEnd, 'yearStart' => $yearStart]),
            'stock' => $this->stockSummary($fiscalYearId),
            'assets' => $this->assetSummary($fiscalYearId, $yearEnd),
        ];
    }

    /**
     * Only APPROVED stock takes count as the physical inventory of record -
     * a draft or in-progress count is not yet a fact the books can transcribe.
     *
     * @return array{
     *     basis: string,
     *     takes: list<array{reference: string, count_date: string, store_location: string, is_full_count: bool}>,
     *     lines: list<array{item_code: string, item_name: string, system_quantity: string, counted_quantity: string|null, variance_quantity: string|null, system_value: int, variance_value: int|null}>,
     *     total_system_value: int,
     *     total_variance_value: int,
     *     has_data: bool,
     * }
     */
    private function stockSummary(int $fiscalYearId): array
    {
        $takeRows = DB::table('stock_takes as st')
            ->join('store_locations as sl', 'sl.id', '=', 'st.store_location_id')
            ->where('st.fiscal_year_id', $fiscalYearId)
            ->whereIn('st.status', [StockTakeStatus::Approved->value, StockTakeStatus::Posted->value])
            ->orderBy('st.count_date')
            ->get(['st.id', 'st.reference', 'st.count_date', 'st.is_full_count', 'sl.name as store_location']);

        $takeIds = $takeRows->pluck('id')->all();

        $takes = $takeRows->map(static fn ($t): array => [
            'reference' => (string) $t->reference,
            'count_date' => (string) $t->count_date,
            'store_location' => (string) $t->store_location,
            'is_full_count' => (bool) $t->is_full_count,
        ])->all();

        if ($takeIds === []) {
            return [
                'basis' => 'No APPROVED or POSTED stock take exists for this fiscal year - nothing to transcribe.',
                'takes' => [],
                'lines' => [],
                'total_system_value' => 0,
                'total_variance_value' => 0,
                'has_data' => false,
            ];
        }

        $lineRows = DB::table('stock_take_lines as l')
            ->join('items as i', 'i.id', '=', 'l.item_id')
            ->whereIn('l.stock_take_id', $takeIds)
            ->orderBy('i.item_code')
            ->get([
                'i.item_code', 'i.name as item_name',
                'l.system_quantity', 'l.counted_quantity', 'l.variance_quantity',
                'l.system_value', 'l.variance_value',
            ]);

        $lines = $lineRows->map(static fn ($l): array => [
            'item_code' => (string) $l->item_code,
            'item_name' => (string) $l->item_name,
            'system_quantity' => (string) $l->system_quantity,
            'counted_quantity' => $l->counted_quantity === null ? null : (string) $l->counted_quantity,
            'variance_quantity' => $l->variance_quantity === null ? null : (string) $l->variance_quantity,
            'system_value' => (int) $l->system_value,
            'variance_value' => $l->variance_value === null ? null : (int) $l->variance_value,
        ])->all();

        return [
            'basis' => 'Approved and posted stock takes for this fiscal year, priced at their frozen counted cost (docs/specs/06-assets-stores.md §7.10).',
            'takes' => $takes,
            'lines' => $lines,
            'total_system_value' => (int) $lineRows->sum('system_value'),
            'total_variance_value' => (int) $lineRows->sum('variance_value'),
            'has_data' => $lines !== [],
        ];
    }

    /**
     * Every asset capitalised in or carried into this fiscal year, with its
     * accumulated depreciation and net book value AS OF the fiscal year end -
     * the latest `DepreciationSchedule` row at or before that date, not a
     * recomputation (§4.2's rows are the depreciation engine's output; this
     * Action does not run a second one).
     *
     * @return array{
     *     basis: string,
     *     lines: list<array{tag_number: string, name: string, acquisition_date: string, acquisition_cost: int, accumulated_depreciation: int, net_book_value: int}>,
     *     total_acquisition_cost: int,
     *     total_accumulated_depreciation: int,
     *     total_net_book_value: int,
     *     has_data: bool,
     * }
     */
    private function assetSummary(int $fiscalYearId, string $yearEnd): array
    {
        $assets = DB::table('assets as a')
            ->where('a.fiscal_year_id', $fiscalYearId)
            ->where('a.acquisition_date', '<=', $yearEnd)
            ->orderBy('a.tag_number')
            ->get(['a.id', 'a.tag_number', 'a.name', 'a.acquisition_date', 'a.acquisition_cost']);

        if ($assets->isEmpty()) {
            return [
                'basis' => 'No asset in this fiscal year\'s register has an acquisition date on or before the period end.',
                'lines' => [],
                'total_acquisition_cost' => 0,
                'total_accumulated_depreciation' => 0,
                'total_net_book_value' => 0,
                'has_data' => false,
            ];
        }

        $assetIds = $assets->pluck('id')->all();

        // `period_month` is the fiscal year's own 1-12 month index (§4.2),
        // not a calendar month, so "as of year end" is simply the latest
        // period_month a DepreciationSchedule row exists for WITHIN this
        // fiscal year - one MySQL 8 window-function round trip for every
        // asset at once.
        $latestSchedules = DB::table(DB::raw(
            '(SELECT asset_id, closing_accumulated, net_book_value,
                ROW_NUMBER() OVER (PARTITION BY asset_id ORDER BY period_month DESC) AS rn
              FROM depreciation_schedules
              WHERE asset_id IN ('.implode(',', array_fill(0, count($assetIds), '?')).')
                AND fiscal_year_id = ?) ranked'
        ))
            ->addBinding($assetIds, 'select')
            ->addBinding($fiscalYearId, 'select')
            ->where('rn', 1)
            ->get(['asset_id', 'closing_accumulated', 'net_book_value'])
            ->keyBy('asset_id');

        $lines = [];
        $totalCost = 0;
        $totalAccumulated = 0;
        $totalNbv = 0;

        foreach ($assets as $asset) {
            $schedule = $latestSchedules->get($asset->id);
            $accumulated = $schedule === null ? 0 : (int) $schedule->closing_accumulated;
            $nbv = $schedule === null ? (int) $asset->acquisition_cost : (int) $schedule->net_book_value;

            $lines[] = [
                'tag_number' => (string) $asset->tag_number,
                'name' => (string) $asset->name,
                'acquisition_date' => (string) $asset->acquisition_date,
                'acquisition_cost' => (int) $asset->acquisition_cost,
                'accumulated_depreciation' => $accumulated,
                'net_book_value' => $nbv,
            ];

            $totalCost += (int) $asset->acquisition_cost;
            $totalAccumulated += $accumulated;
            $totalNbv += $nbv;
        }

        return [
            'basis' => 'Every asset in this fiscal year\'s register acquired on or before the period end, net book value from the latest DepreciationSchedule row at or before the period end (acquisition cost when no run has covered it yet).',
            'lines' => $lines,
            'total_acquisition_cost' => $totalCost,
            'total_accumulated_depreciation' => $totalAccumulated,
            'total_net_book_value' => $totalNbv,
            'has_data' => true,
        ];
    }
}
