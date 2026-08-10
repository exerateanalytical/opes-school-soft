<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Populates `chart_of_accounts.dsf_statement` for every account where it is
 * still NULL.
 *
 * SOURCE OF THE MAPPING
 * ---------------------
 * `docs/specs/02-accounting.md` §2.1 defines `dsf_statement` as the closed
 * enum `bilan_actif, bilan_passif, resultat, flux, note`, and §2.2 (CoA-6)
 * makes `type`/`normal_balance` consistent with `account_class` "per the seed
 * table". That is enough to DERIVE which statement an account belongs to
 * without inventing anything:
 *
 *   - SYSCOHADA classes 1-5 are balance-sheet classes. Side is taken from the
 *     account's own `type`, which the chart seed
 *     (2026_08_07_230002_seed_chart_of_accounts_table.php) already carries and
 *     cites: `asset` -> bilan_actif, `liability`/`equity` -> bilan_passif.
 *     This deliberately keeps contra-accounts on the side they present on:
 *     28 Amortissements and 29 Depreciations are `asset` with a CREDIT normal
 *     balance and belong on the ACTIF (as a deduction), not on the passif.
 *     Class 5 tresorerie lands on bilan_actif / bilan_passif for the same
 *     reason - see the note on `flux` below.
 *   - Classes 6, 7 and 8 are result classes -> `resultat`. Class 8 is included
 *     deliberately: §11 (HAO) requires 81 Valeurs comptables des cessions and
 *     82 Produits des cessions to appear GROSS as two distinct lines of the
 *     Compte de resultat, and 89x (impots sur le resultat) is likewise a
 *     result line.
 *   - Class 9 is hors bilan / analytic (`offbalance`) -> `note`. It is
 *     excluded from the bilan by definition; CoA-7 only requires mapping for
 *     class 1-8.
 *
 * WHY `dsf_line_code` IS NOT POPULATED HERE
 * -----------------------------------------
 * No specification in this repository defines the DSF line/box code set.
 * §2.1 only types the column; CoA-7 and §17.9 only require it to be non-null
 * before a year may be closed; and `03-tax-procurement.md` §7.2 (and §11 gate 7) states plainly
 * that form box codes "ship empty" and are NEEDS VERIFICATION against the
 * official DGI form. Per `00-core` §16 - a wrong seeded value is more
 * dangerous than an empty field - `dsf_line_code` is left NULL rather than
 * filled with a plausible-looking invention that would make
 * `Tax\Actions\GenerateDsf` emit statutory figures under fabricated line
 * references. It must be sourced from the official DSF form with the
 * accountant.
 *
 * For the same reason no account is mapped to `flux`: the OHADA Tableau des
 * flux is a rubrique-coded statement, and `Accounting\Livewire\Statements`
 * only treats flux as mapped when BOTH `dsf_statement = 'flux'` AND a non-null
 * `dsf_line_code` are present. Marking class 5 as `flux` without codes would
 * remove trésorerie from the bilan and buy nothing.
 *
 * IDEMPOTENT: writes only where the column IS NULL, so a later correction by
 * an accountant is never overwritten by a re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        /** @var array<string, array{classes: list<int>, types: list<string>}> $rules */
        $rules = [
            'bilan_actif' => ['classes' => [1, 2, 3, 4, 5], 'types' => ['asset']],
            'bilan_passif' => ['classes' => [1, 2, 3, 4, 5], 'types' => ['liability', 'equity']],
            'resultat' => ['classes' => [6, 7, 8], 'types' => ['expense', 'revenue']],
            'note' => ['classes' => [9], 'types' => ['offbalance', 'analytic']],
        ];

        foreach ($rules as $statement => $rule) {
            DB::table('chart_of_accounts')
                ->whereNull('dsf_statement')
                ->whereIn('account_class', $rule['classes'])
                ->whereIn('type', $rule['types'])
                ->update(['dsf_statement' => $statement]);
        }
    }

    public function down(): void
    {
        // Deliberately irreversible in data terms: the mapping is statutory
        // reference data. Nulling it back out would silently degrade both the
        // DSF generator and the Statements screen to the class fallback.
    }
};
