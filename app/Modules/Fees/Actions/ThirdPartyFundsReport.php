<?php

declare(strict_types=1);

namespace App\Modules\Fees\Actions;

use App\Modules\Identity\Domain\Permission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/04-fees.md §2.3 / §21 - the standing "statement of third-party
 * funds held and remitted": opening held, collected, remitted, closing
 * held, per fund per period.
 *
 * C5: this money is a class-47 LIABILITY, never revenue. The report is what
 * proves to the APEE or the exam board what the school holds on their
 * behalf, and its closing figure must tie exactly to the 47xx GL balance
 * (the nightly integrity job asserts that equality; this report is the fees
 * side of it). Nothing here ever touches class 7 - keeping agent money out
 * of turnover is the entire point.
 *
 * collected = Σ receipts allocated to `agent_for_third_party` invoice lines
 * of the fund in the window (§2.3's amount_collected definition), on the
 * payment's value_date, with §5.2's exclusions (voided, bounced).
 * remitted  = Σ amount_remitted of `remitted` remittance rows in the window.
 *
 * Read-only, gated on `fee.view`.
 */
final readonly class ThirdPartyFundsReport
{
    public const PERMISSION = Permission::FeeView->value;

    /**
     * @return Collection<int, object{fund_id: int, code: string, name: string, opening_held: int, collected: int, remitted: int, closing_held: int}&\stdClass>
     */
    public function handle(string $periodStart, string $periodEnd, ?int $fundId = null): Collection
    {
        Gate::authorize(self::PERMISSION);

        $funds = DB::table('third_party_funds')
            ->when($fundId !== null, fn ($query) => $query->where('id', $fundId))
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        // Opening and movement come from the SAME predicate, split only by
        // date, so the report can never disagree with itself at a boundary.
        $collectedBefore = $this->collectedByFund(null, $periodStart, exclusiveEnd: true);
        $collectedIn = $this->collectedByFund($periodStart, $periodEnd, exclusiveEnd: false);

        $remittedBefore = $this->remittedByFund(null, $periodStart, exclusiveEnd: true);
        $remittedIn = $this->remittedByFund($periodStart, $periodEnd, exclusiveEnd: false);

        return $funds->map(
            /** @return object{fund_id: int, code: string, name: string, opening_held: int, collected: int, remitted: int, closing_held: int} */
            function (object $fund) use ($collectedBefore, $collectedIn, $remittedBefore, $remittedIn): object {
            $id = (int) $fund->id;

            $opening = ($collectedBefore[$id] ?? 0) - ($remittedBefore[$id] ?? 0);
            $collected = $collectedIn[$id] ?? 0;
            $remitted = $remittedIn[$id] ?? 0;

            return (object) [
                'fund_id' => $id,
                'code' => (string) $fund->code,
                'name' => (string) $fund->name,
                'opening_held' => $opening,
                'collected' => $collected,
                'remitted' => $remitted,
                'closing_held' => $opening + $collected - $remitted,
            ];
            }
        )->values();
    }

    /**
     * Σ live allocations to agent lines per fund, by payment value_date.
     *
     * @return array<int, int>
     */
    private function collectedByFund(?string $from, string $to, bool $exclusiveEnd): array
    {
        $rows = DB::table('payment_allocations as pa')
            ->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->join('invoice_lines as l', 'l.id', '=', 'pa.invoice_line_id')
            ->join('invoices as i', 'i.id', '=', 'l.invoice_id')
            ->where('l.collection_basis', 'agent_for_third_party')
            ->whereNotNull('l.third_party_fund_id')
            ->where('i.status', 'issued')
            ->whereNull('pa.reversed_at')
            ->where('p.clearing_state', '<>', 'bounced')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('payment_voids as v')
                    ->whereColumn('v.payment_id', 'p.id')
                    ->where('v.status', 'confirmed');
            })
            ->when($from !== null, fn ($query) => $query->whereDate('p.value_date', '>=', $from))
            ->whereDate('p.value_date', $exclusiveEnd ? '<' : '<=', $to)
            ->groupBy('l.third_party_fund_id')
            ->select([
                'l.third_party_fund_id as fund_id',
                DB::raw('CAST(SUM(pa.amount) AS SIGNED) as total'),
            ])
            ->get();

        /** @var array<int, int> $result */
        $result = [];

        foreach ($rows as $row) {
            $result[(int) $row->fund_id] = (int) $row->total;
        }

        return $result;
    }

    /**
     * Σ amount_remitted of confirmed remittances per fund, by remitted_on.
     *
     * @return array<int, int>
     */
    private function remittedByFund(?string $from, string $to, bool $exclusiveEnd): array
    {
        $rows = DB::table('third_party_fund_remittances')
            ->where('status', 'remitted')
            ->when($from !== null, fn ($query) => $query->whereDate('remitted_on', '>=', $from))
            ->whereDate('remitted_on', $exclusiveEnd ? '<' : '<=', $to)
            ->groupBy('third_party_fund_id')
            ->select([
                'third_party_fund_id as fund_id',
                DB::raw('CAST(SUM(amount_remitted) AS SIGNED) as total'),
            ])
            ->get();

        /** @var array<int, int> $result */
        $result = [];

        foreach ($rows as $row) {
            $result[(int) $row->fund_id] = (int) $row->total;
        }

        return $result;
    }
}
