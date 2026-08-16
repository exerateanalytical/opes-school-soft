<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Identity\Domain\Permission;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Cleared, non-voided receipts per calendar month across the twelve months
 * ending with the given date. Months with no receipts are present with 0 -
 * here a zero IS the fact (no money came in that month).
 *
 * Extracted from FinanceDashboard so AccountingDashboard can show the
 * identical figure without a second, drifting implementation. The geometry
 * builder (chartGeometry) was ALSO extracted, from the blade file's own
 * @php block - two screens computing the same coordinates from the same
 * series is the same duplication risk as two screens computing the series
 * itself.
 *
 * Read-only, gated on ledger.view (matches both callers' own gate).
 */
final readonly class MonthlyCollectionTrend
{
    public const PERMISSION = Permission::LedgerView->value;

    /**
     * @return list<array{label: string, amount: int}>
     */
    public function handle(string $to): array
    {
        Gate::authorize(self::PERMISSION);

        $end = Carbon::parse($to)->endOfMonth();
        $start = $end->copy()->startOfMonth()->subMonths(11);

        $rows = DB::table('payments as p')
            ->whereBetween('p.value_date', [$start->toDateString(), $end->toDateString()])
            ->where('p.clearing_state', '<>', 'bounced')
            ->whereNotExists(function (QueryBuilder $query): void {
                $query->selectRaw('1')->from('payment_voids as v')
                    ->whereColumn('v.payment_id', 'p.id')
                    ->where('v.status', 'confirmed');
            })
            ->groupBy('bucket')
            ->selectRaw("DATE_FORMAT(p.value_date, '%Y-%m') as bucket, CAST(COALESCE(SUM(p.amount), 0) AS SIGNED) as amount")
            ->pluck('amount', 'bucket');

        $out = [];
        $cursor = $start->copy();

        for ($i = 0; $i < 12; $i++) {
            $key = $cursor->format('Y-m');

            $out[] = [
                'label' => $cursor->format('M y'),
                'amount' => (int) ($rows[$key] ?? 0),
            ];

            $cursor = $cursor->addMonthNoOverflow();
        }

        return $out;
    }

    /**
     * The SVG coordinate math both dashboards render identically, moved out
     * of the finance-dashboard blade's own @php block (a 640x180 plot box
     * offset to x:30-630, y:20-170). `max` is the raw series maximum (used
     * for the axis label, which legitimately shows 0 when the series is
     * empty) - `scale` floors that to 1 only for the division, so an
     * all-zero series doesn't divide by zero.
     *
     * @param  list<array{label: string, amount: int}>  $series
     * @return array{path: string, area: string, points: list<array{x: float, y: float, label: string, amount: int}>, max: int}
     */
    public function chartGeometry(array $series): array
    {
        $max = 0;

        foreach ($series as $point) {
            $max = max($max, (int) $point['amount']);
        }

        if (count($series) < 2) {
            return ['path' => '', 'area' => '', 'points' => [], 'max' => $max];
        }

        $plotWidth = 600.0;
        $plotHeight = 150.0;
        $step = $plotWidth / (count($series) - 1);
        $scale = $max > 0 ? $max : 1;

        $path = '';
        $points = [];
        $i = 0;

        foreach ($series as $point) {
            $x = 30 + ($i * $step);
            $y = 20 + ($plotHeight - (((int) $point['amount'] / $scale) * $plotHeight));

            $points[] = ['x' => $x, 'y' => $y, 'label' => $point['label'], 'amount' => (int) $point['amount']];
            $path .= ($i === 0 ? 'M' : ' L').' '.round($x, 2).' '.round($y, 2);
            $i++;
        }

        $area = 'M 30 170 L'.substr($path, 1).' L '.round(30 + (($i - 1) * $step), 2).' 170 Z';

        return ['path' => $path, 'area' => $area, 'points' => $points, 'max' => $max];
    }
}
