<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\Review;

use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Support\Facades\Gate;

/**
 * The docs/specs/02-accounting.md §22 verification gates, and whether each is
 * satisfied yet. docs/specs/2026-08-12-accounting-finance-architecture.md §4.4.
 *
 * Why this exists. §1.1 of the finance architecture forbids guessing a
 * statutory value: "a wrong value that looks authoritative is worse than an
 * empty field." That rule only holds if the gaps are VISIBLE. Otherwise
 * "Not configured" reads as an oversight, and a well-meaning future session
 * fills it with something plausible. This register turns each gap into a
 * named item with a named blocked feature, so it is tracked work.
 *
 * Two KINDS of gate, and the difference matters:
 *
 *   ACCOUNT gates name specific SYSCOHADA accounts. Whether they are
 *   satisfied is a live question this Action can answer by looking in the
 *   chart - so it does, rather than asserting a hardcoded false.
 *
 *   POLICY gates are questions about what the law requires (does AUDCIF fix
 *   a closure deadline? may a first exercice exceed 12 months?). No amount of
 *   database inspection answers those. They need a sourced written answer from
 *   someone qualified, and they stay open until they get one. Reporting them
 *   as "configured" because no account is missing would be exactly the false
 *   assurance this subsystem exists to prevent.
 *
 * This Action REPORTS. It never configures anything, and the architecture
 * test in tests/Architecture/AccountingReviewTest.php enforces that.
 */
final readonly class ConfigurationGates
{
    public const PERMISSION = Permission::LedgerView->value;

    public const KIND_ACCOUNT = 'account';

    public const KIND_POLICY = 'policy';

    /**
     * Transcribed verbatim from docs/specs/02-accounting.md §22 on 2026-08-13.
     * `accounts` lists the account codes whose existence settles the gate;
     * an empty list means the gate is a policy question (see the class note).
     *
     * @var list<array{number: int, item: string, blocks: string, kind: string, accounts: list<string>}>
     */
    private const GATES = [
        ['number' => 1, 'item' => '707x subdivision for boarding / transport / canteen / misc', 'blocks' => 'Fee item to revenue account mapping', 'kind' => self::KIND_ACCOUNT, 'accounts' => ['707']],
        ['number' => 2, 'item' => '5-digit tuition extensions under 706', 'blocks' => 'Fee item to revenue account mapping', 'kind' => self::KIND_ACCOUNT, 'accounts' => ['706']],
        ['number' => 3, 'item' => '631x subdivision for mobile-money commission', 'blocks' => 'Mobile-money payment method', 'kind' => self::KIND_ACCOUNT, 'accounts' => ['631']],
        ['number' => 4, 'item' => 'Cash shortage / overage accounts (brief proposes 658 / 758, unverified)', 'blocks' => 'Cash-desk variance posting', 'kind' => self::KIND_ACCOUNT, 'accounts' => ['658', '758']],
        ['number' => 5, 'item' => '491 provision account and its 65x dotation counterpart', 'blocks' => 'Doubtful-debt provisioning', 'kind' => self::KIND_ACCOUNT, 'accounts' => ['491']],
        ['number' => 6, 'item' => '845 subsidy-release account (865 is wrong - never seed it)', 'blocks' => 'Donated-asset subsidy release', 'kind' => self::KIND_ACCOUNT, 'accounts' => ['845']],
        ['number' => 7, 'item' => '151 amortissements derogatoires', 'blocks' => 'Fiscal-vs-accounting divergence', 'kind' => self::KIND_ACCOUNT, 'accounts' => ['151']],
        ['number' => 8, 'item' => '106 ecart de reevaluation', 'blocks' => 'Asset revaluation', 'kind' => self::KIND_ACCOUNT, 'accounts' => ['106']],
        ['number' => 9, 'item' => '428x leave provision', 'blocks' => 'Payroll leave provision', 'kind' => self::KIND_ACCOUNT, 'accounts' => ['428']],
        ['number' => 10, 'item' => '43x / 44x subdivisions per statutory branch (CNPS, IRPP, CAC, CFC, FNE, TDL)', 'blocks' => 'Payroll posting', 'kind' => self::KIND_ACCOUNT, 'accounts' => ['43', '44']],
        ['number' => 11, 'item' => '44x corporate income-tax liability subdivision; acompte rate; minimum-tax mechanism', 'blocks' => 'Tax provision', 'kind' => self::KIND_POLICY, 'accounts' => []],
        ['number' => 12, 'item' => 'Full DSF line mapping for every postable account', 'blocks' => 'Fiscal-year closure (CoA-7)', 'kind' => self::KIND_POLICY, 'accounts' => []],
        ['number' => 13, 'item' => 'Notes annexes - exact count, numbering, content per note', 'blocks' => 'Year-end close', 'kind' => self::KIND_POLICY, 'accounts' => []],
        ['number' => 14, 'item' => 'Whether AUDCIF Art. 22 fixes a deadline for the cloture informatique beyond "at least quarterly"', 'blocks' => 'Forced-closure default (operational default shipped, labelled)', 'kind' => self::KIND_POLICY, 'accounts' => []],
        ['number' => 15, 'item' => 'Whether AUDCIF permits a first exercice exceeding 12 months', 'blocks' => 'First-exercice validation (product refuses > 12 months)', 'kind' => self::KIND_POLICY, 'accounts' => []],
        ['number' => 16, 'item' => 'Cote-et-paraphe authority and whether it is mandatory for a private school', 'blocks' => 'Statutory book cover page', 'kind' => self::KIND_POLICY, 'accounts' => []],
        ['number' => 17, 'item' => 'Systeme Normal / SMT turnover thresholds', 'blocks' => 'Setup wizard regime check', 'kind' => self::KIND_POLICY, 'accounts' => []],
        ['number' => 18, 'item' => 'Legal-reserve percentage by legal form', 'blocks' => 'Result appropriation', 'kind' => self::KIND_POLICY, 'accounts' => []],
        ['number' => 19, 'item' => "Whether Cameroon's LPF imposes an FEC-style dematerialised accounting-file submission during audit", 'blocks' => 'A potential additional statutory export', 'kind' => self::KIND_POLICY, 'accounts' => []],
    ];

    /**
     * @return list<array{number: int, item: string, blocks: string, kind: string, accounts: list<string>, configured: bool, missing: list<string>}>
     */
    public function handle(): array
    {
        Gate::authorize(self::PERMISSION);

        $present = $this->presentAccountPrefixes();

        return array_map(
            function (array $gate) use ($present): array {
                $missing = array_values(array_filter(
                    $gate['accounts'],
                    static fn (string $code): bool => ! in_array($code, $present, true),
                ));

                return [
                    ...$gate,
                    // A policy gate is never "configured" by inspection - only a
                    // sourced written answer closes it.
                    'configured' => $gate['kind'] === self::KIND_ACCOUNT && $missing === [],
                    'missing' => $missing,
                ];
            },
            self::GATES,
        );
    }

    /**
     * Which of the codes any gate names actually exist as a postable account,
     * or have a postable descendant. One query, not one per gate.
     *
     * @return list<string>
     */
    private function presentAccountPrefixes(): array
    {
        $wanted = [];
        foreach (self::GATES as $gate) {
            foreach ($gate['accounts'] as $code) {
                $wanted[$code] = true;
            }
        }

        if ($wanted === []) {
            return [];
        }

        $codes = ChartOfAccount::query()
            ->where('is_archived', false)
            ->where('is_postable', true)
            ->pluck('code')
            ->all();

        $present = [];

        // array_keys() hands back ints here: PHP silently casts a numeric
        // string array key like '707' to 707. Cast it back, or every
        // str_starts_with() below raises.
        foreach (array_map(strval(...), array_keys($wanted)) as $prefix) {
            foreach ($codes as $code) {
                // A gate asking for "707x subdivisions" is satisfied by a
                // postable account BELOW 707, never by 707 itself.
                if (str_starts_with((string) $code, $prefix) && strlen((string) $code) > strlen($prefix)) {
                    $present[] = $prefix;
                    break;
                }
            }
        }

        return $present;
    }
}
