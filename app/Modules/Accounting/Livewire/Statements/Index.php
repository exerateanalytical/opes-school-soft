<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Livewire\Statements;

use App\Modules\Accounting\Actions\Statements\BuildFinancialStatements;
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

    private function statements(): BuildFinancialStatements
    {
        return app(BuildFinancialStatements::class);
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
            $current = $this->statements()->bilan($fiscalYearId, $window['yearStart'], $window['to']);
            $priorBilan = $this->statements()->bilan(null, null, $prior['to']);

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

        $current = $this->statements()->resultat($fiscalYearId, $window['from'], $window['to']);
        $priorResultat = $this->statements()->resultat(null, $prior['from'], $prior['to']);

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
            $statement = $this->statements()->resultat($this->fiscalYearIdOrNull(), $window['from'], $window['to']);
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
            $statement = $this->statements()->flux($this->fiscalYearIdOrNull(), $window);
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

        $statement = $this->statements()->bilan($this->fiscalYearIdOrNull(), $window['yearStart'], $window['to']);
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
                $bilan = $this->statements()->bilan($this->fiscalYearIdOrNull(), $window['yearStart'], $window['to']);
            } elseif ($this->tab === 'resultat') {
                $resultat = $this->statements()->resultat($this->fiscalYearIdOrNull(), $window['from'], $window['to']);
            } elseif ($this->tab === 'flux') {
                $flux = $this->statements()->flux($this->fiscalYearIdOrNull(), $window);
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
