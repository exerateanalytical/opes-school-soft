<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Livewire;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Reporting\Support\PdfExport;
use App\Support\Money\Money;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;

/**
 * Finance Dashboard, docs/specs/02-accounting.md §21.3.
 *
 * The one screen a bursar opens first: KPI row with period-over-period
 * deltas, an income/expense overview chart, the fee-collection donut, income
 * by category, recent transactions, top outstanding invoices, quick actions,
 * notifications - plus the Treasury Position panel that keeps cash, bank and
 * each mobile-money float visibly APART (§11.3: the v1 defect was "cash in
 * hand" silently absorbing bank and e-money).
 *
 * Three rules this screen is built around:
 *
 * 1. §21.3 rule 1 - every KPI states its AXIS. `fiscal_year` and
 *    `academic_year` differ by a full term here (FY 2026 is Jan-Dec, AY
 *    2026/2027 is Sep-Aug), so the screen carries an explicit axis selector
 *    and prints the resolved window in its header.
 *
 * 2. §21.3 rule 2 - every ledger figure goes through
 *    `JournalEntry::postedLedger()` (posted AND reversed, never a bare
 *    `where('status','posted')`), exactly as `Actions\TrialBalance` and
 *    `Livewire\Reports\Index` do. This screen is the place that rule was
 *    written for.
 *
 * 3. 04-fees A1 / §5 - invoice balance is COMPUTED, never stored, and there
 *    is exactly one formula for it. `outstandingSql()` below is the same
 *    gross − allocations − approved adjustments − issued credit notes
 *    expression `Fees\Livewire\Invoices\Index::outstandingSql()` uses, with
 *    an as-of date bound added on each term so a period-end balance is a
 *    balance AS AT that date rather than today's. Changing one without the
 *    other is a bug; they are deliberately identical line for line.
 *
 * No panel invents a number. Where a source genuinely does not exist yet the
 * panel renders its own empty state (09-ui §3.3: a tile reading 0 because its
 * module is unbuilt is a lie the operator cannot detect).
 *
 * Charts are inline SVG computed here and drawn in the Blade view - the app
 * ships no JS charting library and deliberately adds no build step.
 */
#[Layout('layouts.app')]
final class FinanceDashboard extends Component
{
    /** `fiscal_year` | `academic_year` - §21.3 rule 1. */
    #[Url]
    public string $axis = 'fiscal_year';

    /** `month` | `term` | `year` | `custom`. */
    #[Url]
    public string $period = 'year';

    /** assessment_periods.id when $period === 'term'. */
    #[Url]
    public string $termId = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    /** `income-category` | `expense-category` | `monthly-trend`. */
    #[Url]
    public string $chartTab = 'income-category';

    public function mount(): void
    {
        Gate::authorize(Permission::LedgerView->value);
    }

    public function selectAxis(string $axis): void
    {
        $this->axis = $axis === 'academic_year' ? 'academic_year' : 'fiscal_year';
    }

    public function selectPeriod(string $period): void
    {
        $this->period = in_array($period, ['month', 'term', 'year', 'custom'], true) ? $period : 'year';
    }

    public function selectChartTab(string $tab): void
    {
        $this->chartTab = in_array($tab, ['income-category', 'expense-category', 'monthly-trend'], true)
            ? $tab
            : 'income-category';
    }

    // -----------------------------------------------------------------
    // Period resolution
    // -----------------------------------------------------------------

    /**
     * The axis's own year row, whichever axis is selected. Both tables carry
     * `starts_on`/`ends_on`; only the semantics differ, which is the whole
     * point of the axis switch.
     *
     * @return array{id: int, code: string, starts_on: string, ends_on: string}|null
     */
    private function axisYearRow(): ?array
    {
        $table = $this->axis === 'academic_year' ? 'academic_years' : 'fiscal_years';

        $row = DB::table($table)
            ->select('id', 'code', 'starts_on', 'ends_on')
            ->where('starts_on', '<=', Carbon::today()->toDateString())
            ->where('ends_on', '>=', Carbon::today()->toDateString())
            ->first();

        if ($row === null) {
            $row = DB::table($table)->select('id', 'code', 'starts_on', 'ends_on')
                ->orderByDesc('starts_on')->first();
        }

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'code' => (string) $row->code,
            'starts_on' => (string) $row->starts_on,
            'ends_on' => (string) $row->ends_on,
        ];
    }

    /**
     * Terms/sequences available for the term preset, newest first.
     *
     * @return list<array{id: int, name: string, starts_on: string, ends_on: string}>
     */
    private function termOptions(): array
    {
        $rows = DB::table('assessment_periods')
            ->whereIn('type', ['term', 'trimestre', 'sequence'])
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get(['id', 'name', 'starts_on', 'ends_on']);

        $options = [];

        foreach ($rows as $row) {
            $options[] = [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'starts_on' => (string) $row->starts_on,
                'ends_on' => (string) $row->ends_on,
            ];
        }

        return $options;
    }

    /**
     * The window this dashboard reports on, plus the immediately preceding
     * equivalent window every delta is measured against.
     *
     * @return array{start: string, end: string, label: string, prev_start: string, prev_end: string, prev_label: string}
     */
    private function window(): array
    {
        $today = Carbon::today();
        $axisRow = $this->axisYearRow();
        $axisLabel = $this->axis === 'academic_year' ? 'Academic year' : 'Fiscal year';

        if ($this->period === 'custom' && $this->from !== '' && $this->to !== '') {
            $start = Carbon::parse($this->from)->startOfDay();
            $end = Carbon::parse($this->to)->startOfDay();

            if ($end->lessThan($start)) {
                [$start, $end] = [$end, $start];
            }

            return $this->withShiftedPrevious($start, $end, 'Custom range');
        }

        if ($this->period === 'month') {
            $start = $today->copy()->startOfMonth();
            $end = $today->copy()->endOfMonth()->startOfDay();
            $prevStart = $start->copy()->subMonthNoOverflow();

            return [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'label' => $start->format('F Y'),
                'prev_start' => $prevStart->toDateString(),
                'prev_end' => $prevStart->copy()->endOfMonth()->toDateString(),
                'prev_label' => $prevStart->format('F Y'),
            ];
        }

        if ($this->period === 'term') {
            $terms = $this->termOptions();

            if ($terms === []) {
                // No term calendar configured: fall through to the year window
                // rather than inventing one.
                return $this->yearWindow($axisRow, $axisLabel);
            }

            $selected = null;
            $index = 0;

            foreach ($terms as $i => $term) {
                if ($this->termId !== '' && $term['id'] === (int) $this->termId) {
                    $selected = $term;
                    $index = $i;
                    break;
                }
            }

            if ($selected === null) {
                foreach ($terms as $i => $term) {
                    if ($term['starts_on'] <= $today->toDateString() && $term['ends_on'] >= $today->toDateString()) {
                        $selected = $term;
                        $index = $i;
                        break;
                    }
                }
            }

            if ($selected === null) {
                $selected = $terms[0];
                $index = 0;
            }

            // termOptions() is newest-first, so the NEXT element is the
            // immediately preceding term.
            $previous = $terms[$index + 1] ?? null;

            if ($previous !== null) {
                return [
                    'start' => $selected['starts_on'],
                    'end' => $selected['ends_on'],
                    'label' => $selected['name'],
                    'prev_start' => $previous['starts_on'],
                    'prev_end' => $previous['ends_on'],
                    'prev_label' => $previous['name'],
                ];
            }

            return $this->withShiftedPrevious(
                Carbon::parse($selected['starts_on']),
                Carbon::parse($selected['ends_on']),
                $selected['name'],
            );
        }

        return $this->yearWindow($axisRow, $axisLabel);
    }

    /**
     * @param  array{id: int, code: string, starts_on: string, ends_on: string}|null  $axisRow
     * @return array{start: string, end: string, label: string, prev_start: string, prev_end: string, prev_label: string}
     */
    private function yearWindow(?array $axisRow, string $axisLabel): array
    {
        if ($axisRow === null) {
            $start = Carbon::today()->startOfYear();

            return $this->withShiftedPrevious($start, $start->copy()->endOfYear(), $axisLabel);
        }

        $table = $this->axis === 'academic_year' ? 'academic_years' : 'fiscal_years';

        $previous = DB::table($table)
            ->select('code', 'starts_on', 'ends_on')
            ->where('ends_on', '<', $axisRow['starts_on'])
            ->orderByDesc('starts_on')
            ->first();

        if ($previous !== null) {
            return [
                'start' => $axisRow['starts_on'],
                'end' => $axisRow['ends_on'],
                'label' => $axisLabel.' '.$axisRow['code'],
                'prev_start' => (string) $previous->starts_on,
                'prev_end' => (string) $previous->ends_on,
                'prev_label' => $axisLabel.' '.((string) $previous->code),
            ];
        }

        return $this->withShiftedPrevious(
            Carbon::parse($axisRow['starts_on']),
            Carbon::parse($axisRow['ends_on']),
            $axisLabel.' '.$axisRow['code'],
        );
    }

    /**
     * Fallback comparison window: the same number of days, immediately
     * before the selected one. Used whenever there is no named predecessor
     * row (first fiscal year on file, first term, a custom range).
     *
     * @return array{start: string, end: string, label: string, prev_start: string, prev_end: string, prev_label: string}
     */
    private function withShiftedPrevious(Carbon $start, Carbon $end, string $label): array
    {
        $days = $start->diffInDays($end) + 1;
        $prevEnd = $start->copy()->subDay();
        $prevStart = $prevEnd->copy()->subDays($days - 1);

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'label' => $label,
            'prev_start' => $prevStart->toDateString(),
            'prev_end' => $prevEnd->toDateString(),
            'prev_label' => $prevStart->format('d/m/Y').' – '.$prevEnd->format('d/m/Y'),
        ];
    }

    // -----------------------------------------------------------------
    // The one invoice-balance formula (04-fees §5)
    // -----------------------------------------------------------------

    /**
     * Gross − allocations − approved adjustments − issued credit notes, as at
     * an as-of date, correlated on the outer `i` alias.
     *
     * Identical term-for-term to `Fees\Livewire\Invoices\Index::outstandingSql()`;
     * the only addition is the as-of date bound on each deduction, because a
     * dashboard KPI for a closed period must not be reduced by money that
     * arrived after that period ended.
     *
     * The date is a `?` PLACEHOLDER, not interpolated - the window ultimately
     * traces back to `#[Url]` properties, and a literal-string fragment that
     * can never carry request data into SQL is worth more than the small
     * bookkeeping of passing `asOfBindings()` alongside it. Three
     * placeholders, in this order: allocations, adjustments, credit notes.
     *
     * @return literal-string
     */
    private function outstandingSql(): string
    {
        $gross = '(SELECT COALESCE(SUM(l.amount + l.tax_amount), 0) FROM invoice_lines l WHERE l.invoice_id = i.id)';

        $allocated = '(SELECT COALESCE(SUM(pa.amount), 0)
            FROM payment_allocations pa
            JOIN payments p ON p.id = pa.payment_id
            WHERE pa.invoice_id = i.id
              AND pa.reversed_at IS NULL
              AND p.value_date <= ?
              AND p.clearing_state <> \'bounced\'
              AND NOT EXISTS (SELECT 1 FROM payment_voids v WHERE v.payment_id = p.id AND v.status = \'confirmed\'))';

        $adjusted = '(SELECT COALESCE(SUM(fa.amount), 0)
            FROM fee_adjustments fa
            JOIN invoice_lines al ON al.id = fa.invoice_line_id
            WHERE al.invoice_id = i.id AND fa.status = \'approved\' AND fa.effective_date <= ?)';

        $credited = '(SELECT COALESCE(SUM(cnl.amount + cnl.tax_amount), 0)
            FROM credit_note_lines cnl
            JOIN credit_notes cn ON cn.id = cnl.credit_note_id
            JOIN invoice_lines cl ON cl.id = cnl.invoice_line_id
            WHERE cl.invoice_id = i.id AND cn.status = \'issued\' AND cn.issue_date <= ?)';

        return "CASE WHEN i.status = 'issued' THEN ".$gross.' - '.$allocated.' - '.$adjusted.' - '.$credited.' ELSE 0 END';
    }

    /**
     * The three bindings `outstandingSql()` expects, in its own order.
     *
     * @return list<string>
     */
    private function asOfBindings(string $asOf): array
    {
        return [$asOf, $asOf, $asOf];
    }

    /**
     * @return literal-string
     */
    private function grossSql(): string
    {
        return '(SELECT COALESCE(SUM(l.amount + l.tax_amount), 0) FROM invoice_lines l WHERE l.invoice_id = i.id)';
    }

    // -----------------------------------------------------------------
    // Ledger reads - always through postedLedger()
    // -----------------------------------------------------------------

    /**
     * Posted-ledger lines joined to their account, bounded by entry date.
     * `$from` may be null for an as-at-date balance (treasury).
     */
    private function ledgerLines(?string $from, string $to): QueryBuilder
    {
        $entries = JournalEntry::query()
            ->postedLedger()
            ->when($from !== null, function ($query) use ($from): void {
                $query->whereDate('date', '>=', $from);
            })
            ->whereDate('date', '<=', $to)
            ->select('id', 'date');

        return DB::table('journal_entry_lines as l')
            ->joinSub($entries, 'e', 'e.id', '=', 'l.journal_entry_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id');
    }

    /**
     * Revenue recognised in the window: class-7 accounts, credit − debit
     * (revenue is a credit-normal class, so a debit there is a reversal).
     */
    private function revenue(string $from, string $to): int
    {
        return (int) $this->ledgerLines($from, $to)
            ->where('a.account_class', 7)
            ->selectRaw('CAST(COALESCE(SUM(l.credit) - SUM(l.debit), 0) AS SIGNED) as agg')
            ->value('agg');
    }

    /**
     * Expenses booked in the window: class-6 accounts, debit − credit.
     */
    private function expenses(string $from, string $to): int
    {
        return (int) $this->ledgerLines($from, $to)
            ->where('a.account_class', 6)
            ->selectRaw('CAST(COALESCE(SUM(l.debit) - SUM(l.credit), 0) AS SIGNED) as agg')
            ->value('agg');
    }

    // -----------------------------------------------------------------
    // KPI row
    // -----------------------------------------------------------------

    /**
     * Every KPI number for one window. Called twice - current and previous -
     * so the deltas are like for like by construction.
     *
     * @return array{revenue: int, invoice_count: int, invoiced: int, payments: int, payment_count: int, outstanding: int, collection_rate: float|null}
     */
    private function metrics(string $from, string $to): array
    {
        $revenue = $this->revenue($from, $to);

        $invoiceAgg = DB::table('invoices as i')
            ->where('i.status', 'issued')
            ->whereBetween('i.issue_date', [$from, $to])
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM('.$this->grossSql().'), 0) as gross')
            ->first();

        $invoiceCount = (int) ($invoiceAgg->cnt ?? 0);
        $invoiced = (int) ($invoiceAgg->gross ?? 0);

        $paymentAgg = DB::table('payments as p')
            ->whereBetween('p.value_date', [$from, $to])
            ->where('p.clearing_state', '<>', 'bounced')
            ->whereNotExists(function (QueryBuilder $query): void {
                $query->selectRaw('1')->from('payment_voids as v')
                    ->whereColumn('v.payment_id', 'p.id')
                    ->where('v.status', 'confirmed');
            })
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(p.amount), 0) as total')
            ->first();

        $paymentCount = (int) ($paymentAgg->cnt ?? 0);
        $payments = (int) ($paymentAgg->total ?? 0);

        // Outstanding is a BALANCE, not a flow: every issued invoice raised on
        // or before the window's end, net of everything settled by that date.
        $outstanding = (int) DB::table('invoices as i')
            ->where('i.status', 'issued')
            ->whereDate('i.issue_date', '<=', $to)
            ->selectRaw('COALESCE(SUM('.$this->outstandingSql().'), 0) as agg', $this->asOfBindings($to))
            ->value('agg');

        // Collection rate: of what this window BILLED, how much has been
        // settled as at its end. Null (not 0%) when nothing was billed -
        // "nothing to collect" and "collected nothing" are different facts.
        $billedOutstanding = (int) DB::table('invoices as i')
            ->where('i.status', 'issued')
            ->whereBetween('i.issue_date', [$from, $to])
            ->selectRaw('COALESCE(SUM('.$this->outstandingSql().'), 0) as agg', $this->asOfBindings($to))
            ->value('agg');

        $rate = $invoiced > 0 ? (($invoiced - $billedOutstanding) / $invoiced) * 100 : null;

        return [
            'revenue' => $revenue,
            'invoice_count' => $invoiceCount,
            'invoiced' => $invoiced,
            'payments' => $payments,
            'payment_count' => $paymentCount,
            'outstanding' => $outstanding,
            'collection_rate' => $rate,
        ];
    }

    /**
     * Percentage change, or null when the base period had nothing to compare
     * against - "up 100%" from zero is not information.
     */
    private function delta(int|float|null $current, int|float|null $previous): ?float
    {
        if ($current === null || $previous === null) {
            return null;
        }

        if ((float) $previous === 0.0) {
            return null;
        }

        return (((float) $current - (float) $previous) / abs((float) $previous)) * 100;
    }

    /**
     * @param  array{revenue: int, invoice_count: int, invoiced: int, payments: int, payment_count: int, outstanding: int, collection_rate: float|null}  $now
     * @param  array{revenue: int, invoice_count: int, invoiced: int, payments: int, payment_count: int, outstanding: int, collection_rate: float|null}  $before
     * @return list<array{key: string, label: string, value: string, delta: float|null, trend: string|null, good_when: string}>
     */
    private function kpiCards(array $now, array $before): array
    {
        $cards = [
            [
                'key' => 'revenue',
                'label' => 'Total Revenue',
                'value' => Money::of($now['revenue'])->format(false),
                'delta' => $this->delta($now['revenue'], $before['revenue']),
                'good_when' => 'up',
            ],
            [
                'key' => 'invoices',
                'label' => 'Total Invoices',
                'value' => number_format((float) $now['invoice_count']).' · '.Money::of($now['invoiced'])->format(false),
                'delta' => $this->delta($now['invoiced'], $before['invoiced']),
                'good_when' => 'up',
            ],
            [
                'key' => 'payments',
                'label' => 'Total Payments',
                'value' => number_format((float) $now['payment_count']).' · '.Money::of($now['payments'])->format(false),
                'delta' => $this->delta($now['payments'], $before['payments']),
                'good_when' => 'up',
            ],
            [
                'key' => 'outstanding',
                'label' => 'Outstanding Amount',
                'value' => Money::of($now['outstanding'])->format(false),
                'delta' => $this->delta($now['outstanding'], $before['outstanding']),
                'good_when' => 'down',
            ],
            [
                'key' => 'collection_rate',
                'label' => 'Collection Rate',
                'value' => $now['collection_rate'] === null ? '' : number_format($now['collection_rate'], 1).'%',
                'delta' => $this->delta($now['collection_rate'], $before['collection_rate']),
                'good_when' => 'up',
            ],
        ];

        $out = [];

        foreach ($cards as $card) {
            $delta = $card['delta'];
            $trend = null;

            if ($delta !== null && abs($delta) >= 0.05) {
                $rising = $delta > 0;
                // The arrow's COLOUR means "is this good", not "did the number
                // grow" - outstanding debt climbing is not a green arrow.
                $trend = ($rising === ($card['good_when'] === 'up')) ? 'up' : 'down';
            }

            $out[] = [
                'key' => $card['key'],
                'label' => $card['label'],
                'value' => $card['value'],
                'delta' => $delta,
                'trend' => $trend,
                'good_when' => $card['good_when'],
            ];
        }

        return $out;
    }

    // -----------------------------------------------------------------
    // Treasury Position (§11.3) - money by WHERE IT SITS
    // -----------------------------------------------------------------

    /**
     * Every postable, non-archived class-5 account with its balance as at the
     * window's end. Grouped by code prefix at render time, never by a
     * hardcoded account id: a school that opens 5521/5522 for MTN and Orange
     * gets those rows the moment they exist, with no code change here.
     *
     * @return list<array{code: string, name: string, group: string, balance: int}>
     */
    private function treasury(string $asOf): array
    {
        // Balances first, then the account list - an account with no postings
        // at all must still appear, reading zero, because "this float exists
        // and holds nothing" is itself the answer the panel is asked for.
        $rows = $this->ledgerLines(null, $asOf)
            ->where('a.account_class', 5)
            ->groupBy('a.id')
            ->selectRaw('a.id as account_id, CAST(COALESCE(SUM(l.debit) - SUM(l.credit), 0) AS SIGNED) as balance')
            ->pluck('balance', 'account_id');

        $accounts = DB::table('chart_of_accounts')
            ->where('account_class', 5)
            ->where('is_postable', true)
            ->where('is_archived', false)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $out = [];

        foreach ($accounts as $account) {
            $code = (string) $account->code;

            $out[] = [
                'code' => $code,
                'name' => (string) $account->name,
                'group' => $this->treasuryGroup($code, (string) $account->name),
                'balance' => (int) ($rows[(int) $account->id] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * SYSCOHADA class-5 code prefixes. The label is what a bursar calls the
     * float, not what the chart calls the account - and the two mobile-money
     * operators are named separately because "mobile money" as one number is
     * exactly the lump this panel exists to break apart.
     */
    private function treasuryGroup(string $code, string $name): string
    {
        return match (true) {
            str_starts_with($code, '5521') => 'MTN Mobile Money',
            str_starts_with($code, '5522') => 'Orange Money',
            str_starts_with($code, '55') => 'Mobile money',
            str_starts_with($code, '57') => 'Cash in hand',
            str_starts_with($code, '52') => 'Bank',
            str_starts_with($code, '53') => 'Cash advances & letters of credit',
            str_starts_with($code, '58') => 'Internal transfers',
            str_starts_with($code, '59') => 'Treasury provisions',
            default => $name,
        };
    }

    // -----------------------------------------------------------------
    // Charts
    // -----------------------------------------------------------------

    /**
     * Income by fee category for invoices issued in the window. Read off
     * `invoice_lines.fee_category_code` (the code is denormalised onto the
     * line precisely so a category rename never re-writes history).
     *
     * @return list<array{label: string, amount: int}>
     */
    private function incomeByCategory(string $from, string $to): array
    {
        $rows = DB::table('invoice_lines as il')
            ->join('invoices as i', 'i.id', '=', 'il.invoice_id')
            // `fee_categories.code` is an IDENTIFIER column (00-core §4:
            // utf8mb4_0900_as_cs, accent- and case-sensitive) while the
            // denormalised `invoice_lines.fee_category_code` carries the
            // schema default, so a plain ON clause is an illegal mix of
            // collations. Collate the line's copy up to the identifier
            // collation rather than down - matching "TUITION" to "tuition"
            // here would merge two categories the chart deliberately keeps
            // apart.
            ->leftJoin('fee_categories as fc', function (\Illuminate\Database\Query\JoinClause $join): void {
                $join->whereRaw('fc.code = il.fee_category_code COLLATE utf8mb4_0900_as_cs');
            })
            ->where('i.status', 'issued')
            ->whereBetween('i.issue_date', [$from, $to])
            ->groupBy('il.fee_category_code', 'fc.name')
            ->orderByDesc('amount')
            ->selectRaw('il.fee_category_code as code, fc.name as name, CAST(COALESCE(SUM(il.amount + il.tax_amount), 0) AS SIGNED) as amount')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $name = $row->name === null ? null : (string) $row->name;
            $code = $row->code === null ? null : (string) $row->code;

            $out[] = [
                'label' => $name ?? $code ?? 'Uncategorised',
                'amount' => (int) $row->amount,
            ];
        }

        return $out;
    }

    /**
     * Expense by account for the window: class-6, debit − credit, positive
     * balances only. Empty until the expense-capture module (§21.3) starts
     * writing - and an empty state is what it renders then, never a zero bar.
     *
     * @return list<array{label: string, amount: int}>
     */
    private function expenseByCategory(string $from, string $to): array
    {
        $rows = $this->ledgerLines($from, $to)
            ->where('a.account_class', 6)
            ->groupBy('a.id', 'a.code', 'a.name')
            ->havingRaw('CAST(COALESCE(SUM(l.debit) - SUM(l.credit), 0) AS SIGNED) > 0')
            ->orderByDesc('amount')
            ->selectRaw('a.code as code, a.name as name, CAST(COALESCE(SUM(l.debit) - SUM(l.credit), 0) AS SIGNED) as amount')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'label' => ((string) $row->code).' — '.((string) $row->name),
                'amount' => (int) $row->amount,
            ];
        }

        return $out;
    }

    /**
     * Cleared, non-voided receipts per calendar month across the twelve
     * months ending with the window's end month. Months with no receipts are
     * present with 0 - here a zero IS the fact (no money came in that month),
     * unlike an unbuilt module's zero.
     *
     * @return list<array{label: string, amount: int}>
     */
    private function monthlyCollectionTrend(string $to): array
    {
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
     * Fee Collection Summary donut. One pass over issued invoices raised on
     * or before the window's end, split three ways by the SAME balance
     * formula: what has been settled, what is still owed and not yet due,
     * and what is owed past its due date.
     *
     * @return array{collected: int, outstanding: int, overdue: int, total: int}
     */
    private function collectionSummary(string $to): array
    {
        $outstanding = $this->outstandingSql();

        $row = DB::table('invoices as i')
            ->where('i.status', 'issued')
            ->whereDate('i.issue_date', '<=', $to)
            ->selectRaw('COALESCE(SUM('.$this->grossSql().'), 0) as gross')
            ->selectRaw('COALESCE(SUM('.$outstanding.'), 0) as owed', $this->asOfBindings($to))
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN i.due_date < ? THEN ('.$outstanding.') ELSE 0 END), 0) as overdue',
                array_merge([$to], $this->asOfBindings($to)),
            )
            ->first();

        $gross = (int) ($row->gross ?? 0);
        $owed = (int) ($row->owed ?? 0);
        $overdue = max(0, (int) ($row->overdue ?? 0));

        return [
            'collected' => $gross - $owed,
            'outstanding' => max(0, $owed - $overdue),
            'overdue' => $overdue,
            'total' => $gross,
        ];
    }

    // -----------------------------------------------------------------
    // Tables
    // -----------------------------------------------------------------

    /**
     * Recent Transactions - receipts, newest first, with the columns §21.3
     * names. `type` is the collection direction (all fee receipts are money
     * IN); `status` is the clearing/void state, which is the only status a
     * payment actually has (04-fees: a payment is append-only, voids are
     * separate records).
     *
     * @return list<array{id: int, date: string, receipt_no: string, description: string, client: string, amount: int, type: string, method: string, status: string, tone: string}>
     */
    private function recentTransactions(string $from, string $to): array
    {
        $rows = DB::table('payments as p')
            ->join('students as s', 's.id', '=', 'p.student_id')
            ->whereBetween('p.value_date', [$from, $to])
            ->orderByDesc('p.value_date')
            ->orderByDesc('p.id')
            ->limit(12)
            ->selectRaw("p.id, p.receipt_no, p.value_date, p.amount, p.payment_method, p.clearing_state, p.payer_name, p.reference, CONCAT(s.first_name, ' ', s.last_name) as student_name, s.matricule")
            ->selectRaw('EXISTS (SELECT 1 FROM payment_voids v WHERE v.payment_id = p.id AND v.status = \'confirmed\') as is_voided')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $voided = (bool) $row->is_voided;
            $state = (string) $row->clearing_state;

            $status = $voided ? 'Voided' : ucfirst($state);
            $tone = $voided || $state === 'bounced' ? 'red' : ($state === 'cleared' ? 'ok' : 'amber');

            $reference = $row->reference === null ? '' : (string) $row->reference;

            $out[] = [
                'id' => (int) $row->id,
                'date' => (string) $row->value_date,
                'receipt_no' => (string) $row->receipt_no,
                'description' => 'Fee receipt from '.((string) $row->payer_name).($reference === '' ? '' : ' ('.$reference.')'),
                'client' => ((string) $row->student_name).' · '.((string) $row->matricule),
                'amount' => (int) $row->amount,
                'type' => 'Income',
                'method' => ucfirst(str_replace('_', ' ', (string) $row->payment_method)),
                'status' => $status,
                'tone' => $tone,
            ];
        }

        return $out;
    }

    /**
     * Top Outstanding Invoices, by the §5 balance as at the window's end.
     *
     * @return list<array{id: int, invoice_no: string, student: string, due_date: string, gross: int, outstanding: int, days_overdue: int}>
     */
    private function topOutstanding(string $to): array
    {
        $outstanding = $this->outstandingSql();

        $rows = DB::table('invoices as i')
            ->join('students as s', 's.id', '=', 'i.student_id')
            ->where('i.status', 'issued')
            ->whereDate('i.issue_date', '<=', $to)
            ->whereRaw('('.$outstanding.') > 0', $this->asOfBindings($to))
            ->orderByDesc('outstanding')
            ->limit(10)
            ->selectRaw("i.id, i.invoice_no, i.due_date, CONCAT(s.first_name, ' ', s.last_name) as student_name, s.matricule")
            ->selectRaw($this->grossSql().' as gross')
            ->selectRaw($outstanding.' as outstanding', $this->asOfBindings($to))
            ->get();

        $reference = Carbon::parse($to);
        $out = [];

        foreach ($rows as $row) {
            $due = Carbon::parse((string) $row->due_date);

            $out[] = [
                'id' => (int) $row->id,
                'invoice_no' => $row->invoice_no === null ? '—' : (string) $row->invoice_no,
                'student' => ((string) $row->student_name).' · '.((string) $row->matricule),
                'due_date' => (string) $row->due_date,
                'gross' => (int) $row->gross,
                'outstanding' => (int) $row->outstanding,
                'days_overdue' => $due->lessThan($reference) ? (int) $due->diffInDays($reference) : 0,
            ];
        }

        return $out;
    }

    // -----------------------------------------------------------------
    // Quick actions & notifications
    // -----------------------------------------------------------------

    /**
     * Only actions the reader may actually perform, and only routes that
     * exist - the same Route::has() discipline the Reports hub uses.
     *
     * @return list<array{label: string, url: string}>
     */
    private function quickActions(): array
    {
        $candidates = [
            ['label' => 'Collect a payment', 'route' => 'fees.cashier', 'permission' => Permission::FeeCollect],
            ['label' => 'Invoices', 'route' => 'fees.invoices.index', 'permission' => Permission::FeeView],
            ['label' => 'New journal entry', 'route' => 'ledger.journal-entries.create', 'permission' => Permission::LedgerPost],
            ['label' => 'Financial reports', 'route' => 'reports.financial', 'permission' => Permission::LedgerView],
            ['label' => 'Trial balance', 'route' => 'ledger.trial-balance', 'permission' => Permission::LedgerView],
            ['label' => 'Chart of accounts', 'route' => 'ledger.chart-of-accounts', 'permission' => Permission::LedgerView],
        ];

        $out = [];

        foreach ($candidates as $candidate) {
            if (! \Illuminate\Support\Facades\Route::has($candidate['route'])) {
                continue;
            }

            if (! Gate::allows($candidate['permission']->value)) {
                continue;
            }

            $out[] = ['label' => $candidate['label'], 'url' => route($candidate['route'])];
        }

        return $out;
    }

    /**
     * Facts worth acting on, each one a real count from a real table. An
     * empty list is a legitimate, and good, result.
     *
     * @return list<array{tone: string, message: string}>
     */
    private function notifications(string $to): array
    {
        $out = [];

        $overdueCount = (int) DB::table('invoices as i')
            ->where('i.status', 'issued')
            ->whereDate('i.due_date', '<', $to)
            ->whereRaw('('.$this->outstandingSql().') > 0', $this->asOfBindings($to))
            ->count();

        if ($overdueCount > 0) {
            $out[] = ['tone' => 'red', 'message' => $overdueCount.' invoice(s) are past their due date with a balance outstanding.'];
        }

        $unallocated = (int) DB::table('payments')
            ->where('unallocated_amount', '>', 0)
            ->where('clearing_state', '<>', 'bounced')
            ->count();

        if ($unallocated > 0) {
            $out[] = ['tone' => 'amber', 'message' => $unallocated.' receipt(s) still carry an unallocated balance.'];
        }

        $drafts = (int) DB::table('journal_entries')->where('status', 'draft')->count();

        if ($drafts > 0) {
            $out[] = ['tone' => 'amber', 'message' => $drafts.' journal entry/entries are still in draft and are excluded from every figure on this screen.'];
        }

        // §11.3: a receipt with no treasury account cannot be attributed to a
        // float, so the Treasury Position panel cannot see it. Say so rather
        // than let the panel quietly under-report.
        $untagged = (int) DB::table('payments')->whereNull('treasury_account_id')->count();

        if ($untagged > 0) {
            $out[] = ['tone' => 'amber', 'message' => $untagged.' receipt(s) predate the treasury-account column; their float is inferred from the ledger posting only.'];
        }

        $bounced = (int) DB::table('payments')->where('clearing_state', 'bounced')->count();

        if ($bounced > 0) {
            $out[] = ['tone' => 'red', 'message' => $bounced.' receipt(s) have bounced and are excluded from collections.'];
        }

        return $out;
    }

    // -----------------------------------------------------------------
    // Export
    // -----------------------------------------------------------------

    public function exportPdf(): Response
    {
        Gate::authorize(Permission::LedgerView->value);

        $window = $this->window();
        $now = $this->metrics($window['start'], $window['end']);
        $before = $this->metrics($window['prev_start'], $window['prev_end']);
        $summary = $this->collectionSummary($window['end']);

        $rows = [];

        foreach ($this->kpiCards($now, $before) as $card) {
            $rows[] = [
                'KPI',
                $card['label'],
                $card['value'] === '' ? '—' : $card['value'],
                $card['delta'] === null ? '—' : number_format($card['delta'], 1).'% vs '.$window['prev_label'],
            ];
        }

        foreach ($this->treasury($window['end']) as $account) {
            $rows[] = ['Treasury', $account['group'], $account['code'].' — '.$account['name'], Money::of($account['balance'])->format(false)];
        }

        $rows[] = ['Fee collection', 'Collected', '', Money::of($summary['collected'])->format(false)];
        $rows[] = ['Fee collection', 'Outstanding (not yet due)', '', Money::of($summary['outstanding'])->format(false)];
        $rows[] = ['Fee collection', 'Overdue', '', Money::of($summary['overdue'])->format(false)];

        foreach ($this->incomeByCategory($window['start'], $window['end']) as $category) {
            $rows[] = ['Income by category', $category['label'], '', Money::of($category['amount'])->format(false)];
        }

        foreach ($this->expenseByCategory($window['start'], $window['end']) as $category) {
            $rows[] = ['Expense by category', $category['label'], '', Money::of($category['amount'])->format(false)];
        }

        $title = 'Finance Dashboard — '.$window['label'].' ('.$window['start'].' to '.$window['end'].')';

        return PdfExport::download(
            $title,
            ['Section', 'Item', 'Detail', 'Amount'],
            $rows,
            'finance-dashboard.pdf',
        );
    }

    public function render(): mixed
    {
        $window = $this->window();
        $now = $this->metrics($window['start'], $window['end']);
        $before = $this->metrics($window['prev_start'], $window['prev_end']);

        $chartSeries = match ($this->chartTab) {
            'expense-category' => $this->expenseByCategory($window['start'], $window['end']),
            'monthly-trend' => $this->monthlyCollectionTrend($window['end']),
            default => $this->incomeByCategory($window['start'], $window['end']),
        };

        return view('livewire.accounting.finance-dashboard', [
            'window' => $window,
            'axisLabel' => $this->axis === 'academic_year' ? 'Academic year' : 'Fiscal year',
            'kpis' => $this->kpiCards($now, $before),
            'treasury' => $this->treasury($window['end']),
            'expenseTotal' => $this->expenses($window['start'], $window['end']),
            'chartSeries' => $chartSeries,
            'collection' => $this->collectionSummary($window['end']),
            'incomeByCategory' => $this->incomeByCategory($window['start'], $window['end']),
            'transactions' => $this->recentTransactions($window['start'], $window['end']),
            'topOutstanding' => $this->topOutstanding($window['end']),
            'quickActions' => $this->quickActions(),
            'notifications' => $this->notifications($window['end']),
            'termOptions' => $this->termOptions(),
        ]);
    }
}
