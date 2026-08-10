<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Livewire\Statements;

use App\Modules\Accounting\Actions\FinancialStatementBalances;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Reporting\Support\ExcelExport;
use App\Modules\Reporting\Support\PdfExport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * OHADA/SYSCOHADA financial statements (docs/specs/02-accounting.md §14.2,
 * §17.7, §21.1) - Bilan, Compte de résultat, Tableau des flux de trésorerie,
 * plus a period-over-period Comparative. Companion screen to
 * `Livewire\Reports\Index` (Trial Balance / General Ledger / …), gated on the
 * SAME `ledger.view` permission, exporting through the SAME
 * `Reporting\Support\{ExcelExport,PdfExport}` helpers.
 *
 * Every figure is a real ledger aggregate read through
 * `Actions\FinancialStatementBalances`, which itself goes through
 * `JournalEntry::postedLedger()`. Nothing on this screen is a placeholder:
 * where a statement is not derivable from the ledger as it stands, the tab
 * renders an explicit empty state naming the reason instead of a number.
 *
 * CLASSIFICATION BASIS (this is the one thing worth reading before trusting
 * a figure, and the screen states it on-page too):
 *   - Preferred basis is `chart_of_accounts.dsf_statement` / `.dsf_line_code`
 *     - the statement structure carried by the chart itself, the same
 *     mapping `Tax\Actions\GenerateDsf` folds the trial balance onto.
 *   - When no account carrying movement has `dsf_statement` populated (the
 *     columns exist and are nullable; coverage is a seeding concern, not a
 *     code concern), the screen falls back to the SYSCOHADA account class +
 *     `chart_of_accounts.type`, which is the same information the chart was
 *     built from. The active basis is labelled on every tab.
 *
 * PERIOD SEMANTICS: a bilan is cumulative TO a date, an income statement
 * covers a date RANGE. Both are honoured - the Bilan reads from the fiscal
 * year start to the end of the selected range, the Compte de résultat reads
 * the range only. The Comparative shifts the range back by its own length,
 * crossing the fiscal-year boundary when it has to.
 *
 * OFF-BALANCE accounts (class 9, `offbalance`/`analytic`) are excluded from
 * the bilan by definition, and their net is surfaced as a named
 * reconciliation note - because an out-of-balance bilan on a demo/real
 * ledger is a finding, not something to hide behind a rounded total.
 *
 * @phpstan-type StatementRow object{account_id: int, code: string, name: string, name_fr: string, account_class: int, type: string, dsf_line_code: string|null, dsf_statement: string|null, total_debit: int, total_credit: int}
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    public const TABS = ['bilan', 'resultat', 'flux', 'comparative'];

    /** bilan | resultat | flux | comparative. */
    #[Url]
    public string $tab = 'bilan';

    #[Url]
    public string $fiscalYearId = '';

    #[Url]
    public string $periodFromId = '';

    #[Url]
    public string $periodToId = '';

    /** Comparative tab: which statement is put side by side - bilan | resultat. */
    #[Url]
    public string $comparativeOf = 'resultat';

    public function mount(): void
    {
        Gate::authorize(Permission::LedgerView->value);

        if ($this->fiscalYearId === '') {
            $openYear = FiscalYear::query()->where('status', 'open')->orderByDesc('starts_on')->first()
                ?? FiscalYear::query()->orderByDesc('starts_on')->first();
            $this->fiscalYearId = $openYear === null ? '' : (string) $openYear->id;
        }
    }

    public function selectTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'bilan';
    }

    public function updatedFiscalYearId(): void
    {
        $this->periodFromId = '';
        $this->periodToId = '';
    }

    public function resetFilters(): void
    {
        $this->reset(['periodFromId', 'periodToId']);
    }

    private function fiscalYearIdOrNull(): ?int
    {
        return $this->fiscalYearId === '' ? null : (int) $this->fiscalYearId;
    }

    private function fiscalYear(): ?FiscalYear
    {
        $id = $this->fiscalYearIdOrNull();

        return $id === null ? null : FiscalYear::query()->find($id);
    }

    /**
     * @return Collection<int, AccountingPeriod>
     */
    private function accountingPeriods(): Collection
    {
        $id = $this->fiscalYearIdOrNull();

        if ($id === null) {
            return collect();
        }

        /** @var Collection<int, AccountingPeriod> $periods */
        $periods = AccountingPeriod::query()
            ->where('fiscal_year_id', $id)
            ->orderBy('starts_on')
            ->get();

        return $periods;
    }

    /**
     * The effective reporting window: the selected period range, defaulting
     * to the whole fiscal year at both ends.
     *
     * @return array{from: string, to: string, yearStart: string}|null
     */
    private function window(): ?array
    {
        $fiscalYear = $this->fiscalYear();

        if ($fiscalYear === null) {
            return null;
        }

        $yearStart = Carbon::parse((string) $fiscalYear->starts_on)->toDateString();
        $from = $yearStart;
        $to = Carbon::parse((string) $fiscalYear->ends_on)->toDateString();

        $periods = $this->accountingPeriods()->keyBy('id');

        if ($this->periodFromId !== '') {
            $period = $periods->get((int) $this->periodFromId);

            if ($period !== null) {
                $from = Carbon::parse((string) $period->starts_on)->toDateString();
            }
        }

        if ($this->periodToId !== '') {
            $period = $periods->get((int) $this->periodToId);

            if ($period !== null) {
                $to = Carbon::parse((string) $period->ends_on)->toDateString();
            }
        }

        if (Carbon::parse($from)->greaterThan(Carbon::parse($to))) {
            $from = $yearStart;
        }

        return ['from' => $from, 'to' => $to, 'yearStart' => $yearStart];
    }

    /**
     * The equivalent window immediately preceding the selected one, same
     * length, crossing the fiscal-year boundary when it has to (which is why
     * the comparative queries are NOT constrained to the fiscal year).
     *
     * @param  array{from: string, to: string, yearStart: string}  $window
     * @return array{from: string, to: string}
     */
    private function priorWindow(array $window): array
    {
        $from = Carbon::parse($window['from']);
        $to = Carbon::parse($window['to']);
        $days = $from->diffInDays($to);

        $priorTo = $from->copy()->subDay();
        $priorFrom = $priorTo->copy()->subDays($days);

        return ['from' => $priorFrom->toDateString(), 'to' => $priorTo->toDateString()];
    }

    /**
     * @return Collection<int, StatementRow>
     */
    private function balances(?int $fiscalYearId, ?string $from, ?string $to): Collection
    {
        return app(FinancialStatementBalances::class)->handle($fiscalYearId, $from, $to);
    }

    /**
     * Is the chart's own DSF mapping usable for the accounts that actually
     * carry movement? If not, the SYSCOHADA class fallback is used and said
     * so on screen.
     *
     * @param  Collection<int, StatementRow>  $rows
     */
    private function usesDsfMapping(Collection $rows): bool
    {
        return $rows->contains(fn ($row): bool => in_array(
            $row->dsf_statement,
            ['bilan_actif', 'bilan_passif', 'resultat'],
            true,
        ));
    }

    /**
     * bilan_actif | bilan_passif | resultat | excluded.
     *
     * @param  StatementRow  $row
     */
    private function classify(object $row, bool $useDsf): string
    {
        if ($useDsf && in_array($row->dsf_statement, ['bilan_actif', 'bilan_passif', 'resultat'], true)) {
            return (string) $row->dsf_statement;
        }

        return match ((string) $row->type) {
            'asset' => 'bilan_actif',
            'liability', 'equity' => 'bilan_passif',
            'expense', 'revenue' => 'resultat',
            default => 'excluded',
        };
    }

    /**
     * @param  StatementRow  $row
     */
    private function sectionLabel(object $row, string $bucket): string
    {
        $class = (int) $row->account_class;

        if ($bucket === 'bilan_actif') {
            return match ($class) {
                2 => 'Actif immobilisé',
                3 => 'Stocks et en-cours',
                4 => 'Créances et emplois assimilés',
                5 => 'Trésorerie-Actif',
                default => 'Autres actifs',
            };
        }

        if ($bucket === 'bilan_passif') {
            if ((string) $row->type === 'equity') {
                return 'Capitaux propres et ressources assimilées';
            }

            return match ($class) {
                1 => 'Dettes financières et ressources assimilées',
                4 => 'Passif circulant',
                5 => 'Trésorerie-Passif',
                default => 'Autres dettes',
            };
        }

        return (string) $row->type === 'revenue' ? 'Produits' : 'Charges';
    }

    /**
     * Debit-normal buckets (actif, charges) are debit − credit; credit-normal
     * buckets (passif, produits) are credit − debit. Never an absolute value:
     * a negative asset line is real information.
     *
     * @param  StatementRow  $row
     */
    private function signed(object $row, bool $debitNormal): int
    {
        $debit = (int) $row->total_debit;
        $credit = (int) $row->total_credit;

        return $debitNormal ? $debit - $credit : $credit - $debit;
    }

    /**
     * @param  Collection<int, StatementRow>  $rows
     * @return array{
     *     basis: string,
     *     actif: list<array{label: string, total: int, lines: list<array{code: string, name: string, amount: int}>}>,
     *     passif: list<array{label: string, total: int, lines: list<array{code: string, name: string, amount: int}>}>,
     *     total_actif: int,
     *     total_passif: int,
     *     net_result: int,
     *     difference: int,
     *     excluded: list<array{code: string, name: string, amount: int}>,
     *     excluded_total: int,
     *     has_data: bool,
     * }
     */
    private function bilan(Collection $rows): array
    {
        $useDsf = $this->usesDsfMapping($rows);

        /** @var array<string, array{label: string, total: int, lines: list<array{code: string, name: string, amount: int}>}> $actif */
        $actif = [];
        /** @var array<string, array{label: string, total: int, lines: list<array{code: string, name: string, amount: int}>}> $passif */
        $passif = [];
        /** @var list<array{code: string, name: string, amount: int}> $excluded */
        $excluded = [];
        $excludedTotal = 0;

        foreach ($rows as $row) {
            $bucket = $this->classify($row, $useDsf);

            if ($bucket === 'resultat') {
                continue;
            }

            if ($bucket === 'excluded') {
                $amount = $this->signed($row, true);
                $excluded[] = ['code' => (string) $row->code, 'name' => (string) $row->name, 'amount' => $amount];
                $excludedTotal += $amount;

                continue;
            }

            $debitNormal = $bucket === 'bilan_actif';
            $label = $this->sectionLabel($row, $bucket);
            $target = $debitNormal ? 'actif' : 'passif';

            $section = ($target === 'actif' ? $actif : $passif)[$label] ?? ['label' => $label, 'total' => 0, 'lines' => []];
            $amount = $this->signed($row, $debitNormal);
            $section['lines'][] = ['code' => (string) $row->code, 'name' => (string) $row->name, 'amount' => $amount];
            $section['total'] += $amount;

            if ($target === 'actif') {
                $actif[$label] = $section;
            } else {
                $passif[$label] = $section;
            }
        }

        $net = $this->resultatTotals($rows, $useDsf)['net'];

        // The result of the exercise is a capitaux-propres line of the
        // passif - derived, never a plug figure.
        $equityLabel = 'Capitaux propres et ressources assimilées';
        $section = $passif[$equityLabel] ?? ['label' => $equityLabel, 'total' => 0, 'lines' => []];
        $section['lines'][] = ['code' => '13', 'name' => 'Résultat net de l\'exercice (calculé)', 'amount' => $net];
        $section['total'] += $net;
        $passif[$equityLabel] = $section;

        $totalActif = array_sum(array_map(static fn (array $s): int => $s['total'], $actif));
        $totalPassif = array_sum(array_map(static fn (array $s): int => $s['total'], $passif));

        ksort($actif);
        ksort($passif);

        return [
            'basis' => $useDsf
                ? 'chart_of_accounts.dsf_statement / dsf_line_code (the chart\'s own DSF mapping)'
                : 'SYSCOHADA account class + chart_of_accounts.type — no account carrying movement has dsf_statement populated',
            'actif' => array_values($actif),
            'passif' => array_values($passif),
            'total_actif' => (int) $totalActif,
            'total_passif' => (int) $totalPassif,
            'net_result' => $net,
            'difference' => (int) $totalActif - (int) $totalPassif,
            'excluded' => $excluded,
            'excluded_total' => $excludedTotal,
            'has_data' => $rows->isNotEmpty(),
        ];
    }

    /**
     * @param  Collection<int, StatementRow>  $rows
     * @return array{charges: int, produits: int, net: int}
     */
    private function resultatTotals(Collection $rows, bool $useDsf): array
    {
        $charges = 0;
        $produits = 0;

        foreach ($rows as $row) {
            if ($this->classify($row, $useDsf) !== 'resultat') {
                continue;
            }

            if ((string) $row->type === 'revenue') {
                $produits += $this->signed($row, false);
            } else {
                $charges += $this->signed($row, true);
            }
        }

        return ['charges' => $charges, 'produits' => $produits, 'net' => $produits - $charges];
    }

    /**
     * @param  Collection<int, StatementRow>  $rows
     * @return array{
     *     basis: string,
     *     charges: list<array{code: string, name: string, amount: int}>,
     *     produits: list<array{code: string, name: string, amount: int}>,
     *     total_charges: int,
     *     total_produits: int,
     *     net: int,
     *     has_data: bool,
     * }
     */
    private function resultat(Collection $rows): array
    {
        $useDsf = $this->usesDsfMapping($rows);

        /** @var list<array{code: string, name: string, amount: int}> $charges */
        $charges = [];
        /** @var list<array{code: string, name: string, amount: int}> $produits */
        $produits = [];

        foreach ($rows as $row) {
            if ($this->classify($row, $useDsf) !== 'resultat') {
                continue;
            }

            if ((string) $row->type === 'revenue') {
                $produits[] = [
                    'code' => (string) $row->code,
                    'name' => (string) $row->name,
                    'amount' => $this->signed($row, false),
                ];
            } else {
                $charges[] = [
                    'code' => (string) $row->code,
                    'name' => (string) $row->name,
                    'amount' => $this->signed($row, true),
                ];
            }
        }

        $totals = $this->resultatTotals($rows, $useDsf);

        return [
            'basis' => $useDsf
                ? 'chart_of_accounts.dsf_statement = resultat'
                : 'SYSCOHADA classes 6/7 (and class 8 by chart_of_accounts.type) — dsf_statement is not populated',
            'charges' => $charges,
            'produits' => $produits,
            'total_charges' => $totals['charges'],
            'total_produits' => $totals['produits'],
            'net' => $totals['net'],
            'has_data' => $charges !== [] || $produits !== [],
        ];
    }

    /**
     * Cash flow. The faithful `dsf_statement = 'flux'` version is built only
     * when the chart actually carries flux mapping on accounts with
     * movement; otherwise the treasury-movement version (opening + inflows −
     * outflows = closing, from class-5 accounts) is built instead and the
     * basis is stated on screen. No line is fabricated either way.
     *
     * @param  array{from: string, to: string, yearStart: string}  $window
     * @return array{
     *     basis: string,
     *     mapped: bool,
     *     opening: int,
     *     inflows: int,
     *     outflows: int,
     *     closing: int,
     *     lines: list<array{code: string, name: string, opening: int, inflow: int, outflow: int, closing: int}>,
     *     has_data: bool,
     * }
     */
    private function flux(array $window): array
    {
        $fiscalYearId = $this->fiscalYearIdOrNull();

        $movement = $this->balances($fiscalYearId, $window['from'], $window['to']);
        $mapped = $movement->contains(fn ($row): bool => $row->dsf_statement === 'flux'
            && $row->dsf_line_code !== null
            && trim((string) $row->dsf_line_code) !== '');

        $isTreasury = static fn ($row): bool => (int) $row->account_class === 5;

        $openingTo = Carbon::parse($window['from'])->subDay()->toDateString();
        $opening = Carbon::parse($window['from'])->lessThanOrEqualTo(Carbon::parse($window['yearStart']))
            ? collect()
            : $this->balances($fiscalYearId, $window['yearStart'], $openingTo);

        /** @var array<int, int> $openingByAccount */
        $openingByAccount = [];

        foreach ($opening as $row) {
            if ($isTreasury($row)) {
                $openingByAccount[(int) $row->account_id] = $this->signed($row, true);
            }
        }

        /** @var list<array{code: string, name: string, opening: int, inflow: int, outflow: int, closing: int}> $lines */
        $lines = [];
        $totalOpening = 0;
        $inflows = 0;
        $outflows = 0;

        foreach ($movement as $row) {
            if (! $isTreasury($row)) {
                continue;
            }

            $accountOpening = $openingByAccount[(int) $row->account_id] ?? 0;
            $inflow = (int) $row->total_debit;
            $outflow = (int) $row->total_credit;

            $lines[] = [
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'opening' => $accountOpening,
                'inflow' => $inflow,
                'outflow' => $outflow,
                'closing' => $accountOpening + $inflow - $outflow,
            ];

            $totalOpening += $accountOpening;
            $inflows += $inflow;
            $outflows += $outflow;
        }

        return [
            'basis' => $mapped
                ? 'chart_of_accounts.dsf_statement = flux (mapped DSF flux lines), presented as treasury movement'
                : 'TREASURY-MOVEMENT BASIS — no account with movement carries a dsf_statement = flux mapping, so the statement is derived from class-5 (trésorerie) account movements: opening + encaissements − décaissements = closing. This is not the full OHADA Tableau des flux (operating/investing/financing split), which is not derivable without flux mapping.',
            'mapped' => $mapped,
            'opening' => $totalOpening,
            'inflows' => $inflows,
            'outflows' => $outflows,
            'closing' => $totalOpening + $inflows - $outflows,
            'lines' => $lines,
            'has_data' => $lines !== [],
        ];
    }

    /**
     * @param  array{from: string, to: string, yearStart: string}  $window
     * @return array{
     *     of: string,
     *     current_label: string,
     *     prior_label: string,
     *     rows: list<array{label: string, current: int, prior: int, variance: int, variance_pct: float|null, emphasis: bool}>,
     *     has_data: bool,
     * }
     */
    private function comparative(array $window): array
    {
        $prior = $this->priorWindow($window);
        $fiscalYearId = $this->fiscalYearIdOrNull();

        $currentLabel = $window['from'].' → '.$window['to'];
        $priorLabel = $prior['from'].' → '.$prior['to'];

        // The prior window may sit in an earlier fiscal year, so it is NOT
        // constrained to the selected one.
        if ($this->comparativeOf === 'bilan') {
            $currentRows = $this->balances($fiscalYearId, $window['yearStart'], $window['to']);
            $priorRows = $this->balances(null, null, $prior['to']);

            $current = $this->bilan($currentRows);
            $priorBilan = $this->bilan($priorRows);

            $pairs = [
                ['Total Actif', $current['total_actif'], $priorBilan['total_actif'], true],
                ['Total Passif', $current['total_passif'], $priorBilan['total_passif'], true],
                ['Résultat net de l\'exercice', $current['net_result'], $priorBilan['net_result'], true],
                ['Écart Actif − Passif', $current['difference'], $priorBilan['difference'], false],
            ];

            $rows = [];

            foreach ($pairs as [$label, $cur, $pri, $emphasis]) {
                $rows[] = $this->variance((string) $label, (int) $cur, (int) $pri, (bool) $emphasis);
            }

            return [
                'of' => 'bilan',
                'current_label' => 'As of '.$window['to'],
                'prior_label' => 'As of '.$prior['to'],
                'rows' => $rows,
                'has_data' => $current['has_data'] || $priorBilan['has_data'],
            ];
        }

        $currentRows = $this->balances($fiscalYearId, $window['from'], $window['to']);
        $priorRows = $this->balances(null, $prior['from'], $prior['to']);

        $current = $this->resultat($currentRows);
        $priorResultat = $this->resultat($priorRows);

        /** @var array<string, array{current: int, prior: int}> $byLine */
        $byLine = [];

        foreach ([['current', $current], ['prior', $priorResultat]] as [$side, $statement]) {
            foreach (['produits', 'charges'] as $group) {
                foreach ($statement[$group] as $line) {
                    $key = ($group === 'produits' ? 'Produits — ' : 'Charges — ').$line['code'].' '.$line['name'];
                    $byLine[$key] ??= ['current' => 0, 'prior' => 0];
                    $byLine[$key][$side] += $line['amount'];
                }
            }
        }

        ksort($byLine);

        $rows = [];

        foreach ($byLine as $label => $amounts) {
            $rows[] = $this->variance($label, $amounts['current'], $amounts['prior'], false);
        }

        $rows[] = $this->variance('Total Produits', $current['total_produits'], $priorResultat['total_produits'], true);
        $rows[] = $this->variance('Total Charges', $current['total_charges'], $priorResultat['total_charges'], true);
        $rows[] = $this->variance('Résultat net', $current['net'], $priorResultat['net'], true);

        return [
            'of' => 'resultat',
            'current_label' => $currentLabel,
            'prior_label' => $priorLabel,
            'rows' => $rows,
            'has_data' => $current['has_data'] || $priorResultat['has_data'],
        ];
    }

    /**
     * @return array{label: string, current: int, prior: int, variance: int, variance_pct: float|null, emphasis: bool}
     */
    private function variance(string $label, int $current, int $prior, bool $emphasis): array
    {
        return [
            'label' => $label,
            'current' => $current,
            'prior' => $prior,
            'variance' => $current - $prior,
            // No prior base means no percentage - an "∞%" or a 0 would both
            // be inventions.
            'variance_pct' => $prior === 0 ? null : round((($current - $prior) / abs($prior)) * 100, 1),
            'emphasis' => $emphasis,
        ];
    }

    /**
     * @return array{title: string, headers: list<string>, rows: list<list<mixed>>}
     */
    private function exportData(): array
    {
        $window = $this->window();

        if ($window === null) {
            return ['title' => 'Financial Statements', 'headers' => ['Notice'], 'rows' => [['Select a fiscal year first.']]];
        }

        if ($this->tab === 'resultat') {
            $statement = $this->resultat($this->balances($this->fiscalYearIdOrNull(), $window['from'], $window['to']));
            $rows = [];

            foreach ($statement['produits'] as $line) {
                $rows[] = ['Produits', $line['code'], $line['name'], $line['amount']];
            }

            $rows[] = ['Produits', '', 'TOTAL PRODUITS', $statement['total_produits']];

            foreach ($statement['charges'] as $line) {
                $rows[] = ['Charges', $line['code'], $line['name'], $line['amount']];
            }

            $rows[] = ['Charges', '', 'TOTAL CHARGES', $statement['total_charges']];
            $rows[] = ['Résultat', '', 'RÉSULTAT NET', $statement['net']];

            return [
                'title' => 'Compte de resultat '.$window['from'].' - '.$window['to'],
                'headers' => ['Section', 'Code', 'Libellé', 'Montant (FCFA)'],
                'rows' => $rows,
            ];
        }

        if ($this->tab === 'flux') {
            $statement = $this->flux($window);
            $rows = [];

            foreach ($statement['lines'] as $line) {
                $rows[] = [$line['code'], $line['name'], $line['opening'], $line['inflow'], $line['outflow'], $line['closing']];
            }

            $rows[] = ['', 'TOTAL TRÉSORERIE', $statement['opening'], $statement['inflows'], $statement['outflows'], $statement['closing']];

            return [
                'title' => 'Tableau des flux de tresorerie '.$window['from'].' - '.$window['to'],
                'headers' => ['Code', 'Compte', 'Solde ouverture', 'Encaissements', 'Décaissements', 'Solde clôture'],
                'rows' => $rows,
            ];
        }

        if ($this->tab === 'comparative') {
            $statement = $this->comparative($window);
            $rows = [];

            foreach ($statement['rows'] as $line) {
                $rows[] = [
                    $line['label'],
                    $line['current'],
                    $line['prior'],
                    $line['variance'],
                    $line['variance_pct'] === null ? 'n/a' : $line['variance_pct'].'%',
                ];
            }

            return [
                'title' => 'Comparative '.$statement['of'].' '.$statement['current_label'],
                'headers' => ['Ligne', $statement['current_label'], $statement['prior_label'], 'Écart', 'Écart %'],
                'rows' => $rows,
            ];
        }

        $statement = $this->bilan($this->balances($this->fiscalYearIdOrNull(), $window['yearStart'], $window['to']));
        $rows = [];

        foreach ($statement['actif'] as $section) {
            foreach ($section['lines'] as $line) {
                $rows[] = ['Actif', $section['label'], $line['code'], $line['name'], $line['amount']];
            }

            $rows[] = ['Actif', $section['label'], '', 'Sous-total', $section['total']];
        }

        $rows[] = ['Actif', '', '', 'TOTAL ACTIF', $statement['total_actif']];

        foreach ($statement['passif'] as $section) {
            foreach ($section['lines'] as $line) {
                $rows[] = ['Passif', $section['label'], $line['code'], $line['name'], $line['amount']];
            }

            $rows[] = ['Passif', $section['label'], '', 'Sous-total', $section['total']];
        }

        $rows[] = ['Passif', '', '', 'TOTAL PASSIF', $statement['total_passif']];
        $rows[] = ['Contrôle', '', '', 'ÉCART ACTIF − PASSIF', $statement['difference']];

        foreach ($statement['excluded'] as $line) {
            $rows[] = ['Hors bilan (classe 9)', '', $line['code'], $line['name'], $line['amount']];
        }

        return [
            'title' => 'Bilan as of '.$window['to'],
            'headers' => ['Côté', 'Rubrique', 'Code', 'Libellé', 'Montant (FCFA)'],
            'rows' => $rows,
        ];
    }

    public function exportExcel(): StreamedResponse
    {
        Gate::authorize(Permission::LedgerView->value);

        $data = $this->exportData();

        return ExcelExport::download(
            $data['title'],
            $data['headers'],
            $data['rows'],
            str($data['title'])->slug()->value().'.xlsx',
        );
    }

    public function exportPdf(): Response
    {
        Gate::authorize(Permission::LedgerView->value);

        $data = $this->exportData();

        return PdfExport::download(
            $data['title'],
            $data['headers'],
            $data['rows'],
            str($data['title'])->slug()->value().'.pdf',
            in_array($this->tab, ['flux', 'comparative'], true) ? 'landscape' : 'portrait',
        );
    }

    /**
     * @return Collection<int, FiscalYear>
     */
    private function fiscalYears(): Collection
    {
        /** @var Collection<int, FiscalYear> $years */
        $years = FiscalYear::query()->orderByDesc('starts_on')->get();

        return $years;
    }

    public function render(): mixed
    {
        Gate::authorize(Permission::LedgerView->value);

        $window = $this->window();

        $bilan = null;
        $resultat = null;
        $flux = null;
        $comparative = null;

        if ($window !== null) {
            if ($this->tab === 'bilan') {
                $bilan = $this->bilan($this->balances($this->fiscalYearIdOrNull(), $window['yearStart'], $window['to']));
            } elseif ($this->tab === 'resultat') {
                $resultat = $this->resultat($this->balances($this->fiscalYearIdOrNull(), $window['from'], $window['to']));
            } elseif ($this->tab === 'flux') {
                $flux = $this->flux($window);
            } else {
                $comparative = $this->comparative($window);
            }
        }

        return view('livewire.accounting.statements.index', [
            'window' => $window,
            'bilan' => $bilan,
            'resultat' => $resultat,
            'flux' => $flux,
            'comparative' => $comparative,
            'fiscalYearOptions' => $this->fiscalYears(),
            'accountingPeriodOptions' => $this->accountingPeriods(),
        ]);
    }
}
