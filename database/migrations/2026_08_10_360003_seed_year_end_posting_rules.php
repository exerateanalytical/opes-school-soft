<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/specs/02-accounting.md §18.1-§18.3 - the three posting rules without
 * which `PostingEvent::YearEndClosing`, `YearEndAppropriation` and
 * `YearEndOpeningBalances` are enum cases nothing can emit: PostFromEvent
 * refuses an event with no active matching rule, by design.
 *
 * These are SEEDED CONFIGURATION, not demo data. The `AN` and `CL` journals
 * are `is_system` precisely because §3 reserves them for the year-end
 * Actions, so the rules that address them ship with the product rather than
 * being keyed by an accountant who would have to guess the payload paths.
 *
 * Shape, and why it is this shape:
 *
 *  - every line ITERATES a payload collection and is SIGNED. §18.1 closes
 *    every class 6/7/8 account that has a balance - one line each - and the
 *    side each one lands on depends on its balance, not on configuration.
 *    §18.2 carries every collective balance one line per partner. Neither
 *    is expressible as a fixed set of debit/credit lines;
 *  - there is NO `is_balancing` line. The residual on compte 13 is computed
 *    by the Action as Σcredits − Σdebits over the very set of lines it is
 *    emitting and pushed into `closing.lines` as the last element, so it is
 *    a residual by construction (00-core §7.3) AND it carries the account
 *    the Action resolved. A balancing line here would have to guess a side
 *    (profit credits 13, loss debits it) that only the data knows;
 *  - `skip_if_zero` everywhere: an account with a nil balance contributes
 *    no line, and a zero line would violate L1 anyway.
 *
 * Idempotent: each rule is inserted only if its code is absent, so a
 * re-run - or an install where an accountant has already versioned one of
 * them - leaves the existing configuration alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (self::RULES as $code => $rule) {
            if (DB::table('posting_rules')->where('code', $code)->exists()) {
                continue;
            }

            $journalId = DB::table('journals')->where('code', $rule['journal'])->value('id');

            if ($journalId === null) {
                // No journal, no rule - and no silent half-seed. The AN/CL
                // journals are seeded with the chart; an install missing
                // them has a bigger problem than this migration.
                continue;
            }

            $ruleId = DB::table('posting_rules')->insertGetId([
                'code' => $code,
                'version' => 1,
                'event' => $rule['event'],
                'journal_id' => $journalId,
                'label_expression' => $rule['label'],
                'condition_expression' => null,
                'priority' => 100,
                'is_active' => true,
                'is_locked' => false,
                'effective_from' => '2000-01-01',
                'effective_to' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($rule['lines'] as $sequence => $line) {
                DB::table('posting_rule_lines')->insert([
                    'posting_rule_id' => $ruleId,
                    'sequence' => $sequence + 1,
                    'account_source' => 'payload_path',
                    'account_code' => null,
                    'account_path' => 'item.target_account_id',
                    'sign' => 'signed',
                    'amount_expression' => 'item.amount',
                    'is_balancing' => false,
                    'partner_source' => $line['partner_source'],
                    'analytic_source' => null,
                    'tax_code_source' => null,
                    'due_date_source' => $line['due_date_source'],
                    'iterates_over' => $line['iterates_over'],
                    'label_expression' => '{item.label}',
                    'skip_if_zero' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('posting_rules')
            ->whereIn('code', array_keys(self::RULES))
            ->where('is_locked', false)
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return;
        }

        // A locked rule has posted a real entry and is excluded above: the
        // provenance stamp on that entry must keep resolving.
        DB::table('posting_rule_lines')->whereIn('posting_rule_id', $ids)->delete();
        DB::table('posting_rules')->whereIn('id', $ids)->delete();
    }

    /**
     * @var array<string, array{event: string, journal: string, label: string, lines: list<array{iterates_over: string, partner_source: string|null, due_date_source: string|null}>}>
     */
    private const RULES = [
        // §18.1 - classes 6, 7 and 8 against 13, journal CL.
        'year_end_closing' => [
            'event' => 'year_end.closing',
            'journal' => 'CL',
            'label' => 'Cloture exercice {closing.reference}',
            'lines' => [
                ['iterates_over' => 'closing.lines', 'partner_source' => null, 'due_date_source' => null],
            ],
        ],

        // §18.3 - 13 emptied into 11 / reserves / distributions, journal OD.
        'year_end_appropriation' => [
            'event' => 'year_end.appropriation',
            'journal' => 'OD',
            'label' => 'Affectation du resultat {closing.reference}',
            'lines' => [
                ['iterates_over' => 'closing.lines', 'partner_source' => null, 'due_date_source' => null],
            ],
        ],

        // §18.2 - classes 1-5 into the new exercice, journal AN. The
        // partner-bearing collection comes FIRST so the auxiliary detail
        // heads the entry an auditor opens.
        'year_end_opening_balances' => [
            'event' => 'year_end.opening_balances',
            'journal' => 'AN',
            'label' => 'A-nouveaux {closing.reference}',
            'lines' => [
                [
                    'iterates_over' => 'closing.partner_lines',
                    'partner_source' => 'item.partner',
                    'due_date_source' => 'item.due_date',
                ],
                ['iterates_over' => 'closing.lines', 'partner_source' => null, 'due_date_source' => null],
            ],
        ],
    ];
};
