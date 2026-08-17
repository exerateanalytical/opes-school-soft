<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\Statements;

use App\Modules\Accounting\Actions\FinancialStatementBalances;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * docs/specs/02-accounting.md §14.2, §17.7, §21.1 - Bilan, Compte de
 * résultat and Tableau des flux de trésorerie, built from the same
 * `FinancialStatementBalances` ledger read `Livewire\Statements\Index` uses.
 *
 * Extracted from that Livewire component so the livre d'inventaire (§14.2's
 * fourth book, which TRANSCRIBES these three statements) can produce the
 * exact same figures the Statements screen shows, instead of a second copy
 * of this classification logic drifting from the first.
 *
 * @phpstan-type StatementRow object{account_id: int, code: string, name: string, name_fr: string, account_class: int, type: string, dsf_line_code: string|null, dsf_statement: string|null, total_debit: int, total_credit: int}
 */
final readonly class BuildFinancialStatements
{
    public function __construct(private FinancialStatementBalances $balances) {}

    /**
     * @return Collection<int, StatementRow>
     */
    private function movements(?int $fiscalYearId, ?string $from, ?string $to): Collection
    {
        return $this->balances->handle($fiscalYearId, $from, $to);
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
    public function bilan(?int $fiscalYearId, ?string $from, string $to): array
    {
        $rows = $this->movements($fiscalYearId, $from, $to);
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
    public function resultat(?int $fiscalYearId, string $from, string $to): array
    {
        $rows = $this->movements($fiscalYearId, $from, $to);
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
    public function flux(?int $fiscalYearId, array $window): array
    {
        $movement = $this->movements($fiscalYearId, $window['from'], $window['to']);
        $mapped = $movement->contains(fn ($row): bool => $row->dsf_statement === 'flux'
            && $row->dsf_line_code !== null
            && trim((string) $row->dsf_line_code) !== '');

        $isTreasury = static fn ($row): bool => (int) $row->account_class === 5;

        $openingTo = Carbon::parse($window['from'])->subDay()->toDateString();
        $opening = Carbon::parse($window['from'])->lessThanOrEqualTo(Carbon::parse($window['yearStart']))
            ? collect()
            : $this->movements($fiscalYearId, $window['yearStart'], $openingTo);

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
}
