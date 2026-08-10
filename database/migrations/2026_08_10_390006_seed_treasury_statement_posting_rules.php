<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/specs/02-accounting.md §13.3: the reconciliation session "offers a
 * one-click *post this statement line* using the `bank.charge.recorded` /
 * `bank.interest.received` posting rules", and §1.3/§11.3 add the third
 * case - the MoMo operator commission, without which a float can never tie
 * to the operator statement.
 *
 * `PostingEvent` has declared all three cases since §11.2 was implemented,
 * but no rule addressed them, and PostFromEvent refuses - by design - an
 * event with no active matching rule. Without these three rows the fourth
 * line of the état ("opérations au relevé non encore comptabilisées") could
 * be shown but never cleared, and since §13.2 BR-3 forbids completing a
 * session while that line is non-zero, no session touching a bank charge
 * could ever close. These are SEEDED CONFIGURATION, exactly as the year-end
 * rules in 360003 are.
 *
 * Shape: both accounts come from the PAYLOAD, never from a literal code.
 * The treasury account is whichever float the session is reconciling - 521,
 * 5521 or 5522 - and the charge account is the 631x subdivision the school's
 * accountant chose (§1.3 is explicit that 6317 is the school's decision, not
 * a seeded constant). A literal code here would hard-code one school's
 * chart into the product.
 *
 * Journals: BQ for the two bank events, MM for the mobile-money commission,
 * matching §11.3's worked example.
 *
 * Idempotent: a rule whose code is already present is left exactly as it is,
 * so an accountant who has versioned one of these keeps their version.
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
                    'account_path' => $line['account_path'],
                    'sign' => $line['sign'],
                    'amount_expression' => 'statement_item.amount',
                    'is_balancing' => false,
                    'partner_source' => null,
                    'analytic_source' => null,
                    'tax_code_source' => null,
                    'due_date_source' => null,
                    'iterates_over' => null,
                    'label_expression' => $line['label'],
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

        // A locked rule has produced a real entry whose provenance stamp
        // must keep resolving; it is excluded above and stays.
        DB::table('posting_rule_lines')->whereIn('posting_rule_id', $ids)->delete();
        DB::table('posting_rules')->whereIn('id', $ids)->delete();
    }

    /**
     * @var array<string, array{event: string, journal: string, label: string, lines: list<array{account_path: string, sign: string, label: string}>}>
     */
    private const RULES = [
        // Money the bank took: charge is an expense, the float goes down.
        'bank_charge_recorded' => [
            'event' => 'bank.charge.recorded',
            'journal' => 'BQ',
            'label' => 'Frais bancaires {statement_item.reference}',
            'lines' => [
                [
                    'account_path' => 'statement_item.charge_account_id',
                    'sign' => 'debit',
                    'label' => '{statement_item.label}',
                ],
                [
                    'account_path' => 'statement_item.treasury_account_id',
                    'sign' => 'credit',
                    'label' => '{statement_item.label}',
                ],
            ],
        ],

        // Money the bank added: the float goes up against financial income.
        'bank_interest_received' => [
            'event' => 'bank.interest.received',
            'journal' => 'BQ',
            'label' => 'Interets crediteurs {statement_item.reference}',
            'lines' => [
                [
                    'account_path' => 'statement_item.treasury_account_id',
                    'sign' => 'debit',
                    'label' => '{statement_item.label}',
                ],
                [
                    'account_path' => 'statement_item.income_account_id',
                    'sign' => 'credit',
                    'label' => '{statement_item.label}',
                ],
            ],
        ],

        // §11.3: the commission the operator deducted. Booking it is what
        // lets the 552x float equal the operator's own figure.
        'mobile_money_commission_charged' => [
            'event' => 'mobile_money.commission.charged',
            'journal' => 'MM',
            'label' => 'Commission operateur {statement_item.reference}',
            'lines' => [
                [
                    'account_path' => 'statement_item.charge_account_id',
                    'sign' => 'debit',
                    'label' => '{statement_item.label}',
                ],
                [
                    'account_path' => 'statement_item.treasury_account_id',
                    'sign' => 'credit',
                    'label' => '{statement_item.label}',
                ],
            ],
        ],
    ];
};
