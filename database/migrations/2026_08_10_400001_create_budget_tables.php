<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/02-accounting.md §16 - `Budget`, `BudgetLine`, `BudgetPhasing`.
 *
 * These three tables are what makes `chart_of_accounts.budget_control` mean
 * something. That column has existed and been seeded (`none|warn|block`)
 * since the chart was built, but with no budget to compare an entry against
 * it was unreadable by anything: §16's over-budget control is "YTD actual +
 * this entry vs YTD PHASED budget", and the phased budget lived nowhere.
 *
 * Note this is NOT `Procurement\Domain\BudgetEnforcement`, which caps a
 * REQUISITION against a configured threshold before anything is committed to
 * the ledger. This is the accounting budget: an annual figure per account,
 * spread across the twelve accounting periods, compared against the posted
 * ledger. The two are independent and deliberately do not consult each other.
 *
 * Where the §16 invariants land:
 *
 *  - B-1 (Σ phasing = annual_amount per line) is in-Action
 *    (`ApplyBudgetPhasing`, using `Money::allocate` for the ratio case). It
 *    is a statement about a SET of child rows, which a row-level CHECK
 *    cannot see; the Action rewrites the whole phasing set for a line in one
 *    transaction so the set is never observed half-written.
 *  - B-2 (an approved budget is immutable; edits produce version + 1) is the
 *    conditional-UPDATE pattern of 00-core §10.4, in `ApproveBudget` /
 *    `SaveBudget`. The `uq_budgets_fy_code_version` key here is what makes a
 *    new version a legal row rather than a duplicate.
 *  - B-3 (only one `approved` budget per fiscal year is current) is the
 *    generated-column UNIQUE of 00-core §10.1: `current_fiscal_year_key` is
 *    the fiscal year id when `is_current`, and NULL otherwise, so MySQL's
 *    "UNIQUE ignores NULLs" behaviour is doing exactly the work wanted.
 *
 * `analytic_key` exists for the same reason `grade_bands.class_level_key`
 * does: `UNIQUE(budget_id, account_id, analytic_value_id)` silently permits
 * unlimited duplicate rows for the NULL-analytic case, which is precisely the
 * common case here. `COALESCE(analytic_value_id, 0)` is the sentinel §16
 * calls for, and the real FK column stays nullable so RESTRICT still means
 * what it says.
 *
 * Money is BIGINT SIGNED FCFA (00-core §5). Signed, not unsigned: a budget
 * line may legitimately be negative (`6033` stock variation is a credit-normal
 * expense account in this very chart's demo data), and an unsigned column
 * would turn that into a write error rather than a number.
 *
 * Deletes are RESTRICT throughout (00-core §9): a budget that was approved is
 * a record of a decision, and neither the fiscal year nor the account it
 * targets may be pulled out from under it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('fiscal_year_id')
                ->constrained('fiscal_years')->restrictOnDelete();

            // §16: both axes, exactly like every other financial entity (§7 /
            // C3). The fiscal year governs the phasing periods; the academic
            // year is what a head teacher actually budgets in.
            $table->foreignId('academic_year_id')
                ->constrained('academic_years')->restrictOnDelete();

            // 00-core §4: identifiers are accent- and case-sensitive.
            $table->string('code', 60)->collation('utf8mb4_0900_as_cs');

            $table->string('name', 200);

            $table->string('status', 20)->collation('utf8mb4_0900_as_cs')->default('draft');

            $table->unsignedSmallInteger('version')->default(1);

            $table->boolean('is_current')->default(false);

            // B-3, as a key rather than as a hope.
            $table->unsignedBigInteger('current_fiscal_year_key')->nullable()
                ->storedAs('(CASE WHEN `is_current` = 1 THEN `fiscal_year_id` END)');

            $table->foreignId('approved_by')->nullable()
                ->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();

            $table->string('notes', 500)->nullable();

            $table->timestamps();

            $table->unique(['fiscal_year_id', 'code', 'version'], 'uq_budgets_fy_code_version');
            $table->unique('current_fiscal_year_key', 'uq_budgets_current_per_fy');
            $table->index('status', 'ix_budgets_status');
            $table->index('academic_year_id', 'ix_budgets_academic_year');
        });

        Schema::create('budget_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('budget_id')
                ->constrained('budgets')->restrictOnDelete();

            // §16: must be postable, and class 6/7 (charges/produits) or
            // class 2 (capex). `is_postable` and `account_class` live on the
            // referenced row, so the rule is asserted in SaveBudgetLine where
            // it can name the offending account; the FK is the part a CHECK
            // can carry.
            $table->foreignId('account_id')
                ->constrained('chart_of_accounts')->restrictOnDelete();

            $table->foreignId('analytic_value_id')->nullable()
                ->constrained('analytic_values')->restrictOnDelete();

            // The §16 sentinel. See the class docblock.
            $table->unsignedBigInteger('analytic_key')
                ->storedAs('(COALESCE(`analytic_value_id`, 0))');

            $table->bigInteger('annual_amount')->default(0);

            $table->string('notes', 500)->nullable();

            $table->timestamps();

            $table->unique(['budget_id', 'account_id', 'analytic_key'], 'uq_budget_lines_target');
            $table->index('account_id', 'ix_budget_lines_account');
        });

        Schema::create('budget_phasings', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('budget_line_id')
                ->constrained('budget_lines')->restrictOnDelete();

            // The first day of the month, matching
            // `accounting_periods.period_month` exactly - the phasing grid IS
            // the period grid, and Budget-vs-Actual joins on this equality.
            $table->date('period_month');

            $table->bigInteger('amount')->default(0);

            $table->timestamps();

            $table->unique(['budget_line_id', 'period_month'], 'uq_budget_phasings_month');
            $table->index('period_month', 'ix_budget_phasings_month');
        });

        foreach (self::BUDGET_CHECKS as $name => $expression) {
            DB::statement("ALTER TABLE `budgets` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }

        foreach (self::PHASING_CHECKS as $name => $expression) {
            DB::statement("ALTER TABLE `budget_phasings` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_phasings');
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('budgets');
    }

    /**
     * @var array<string, string>
     */
    private const BUDGET_CHECKS = [
        'ck_budgets_status' => "`status` IN ('draft','approved','closed')",

        'ck_budgets_version' => '`version` >= 1',

        // An approved budget names who approved it and when. A draft names
        // neither - nothing that reads "signed off" may survive a revert.
        'ck_budgets_approval' => "(`status` IN ('approved','closed') AND `approved_at` IS NOT NULL AND `approved_by` IS NOT NULL)"
            ." OR (`status` = 'draft' AND `approved_at` IS NULL AND `approved_by` IS NULL)",

        // B-3's other half: a DRAFT is never the current budget. Without
        // this, the generated-column UNIQUE would happily let a draft be the
        // one budget the over-budget check reads.
        'ck_budgets_current_is_approved' => "`is_current` = 0 OR `status` = 'approved'",
    ];

    /**
     * @var array<string, string>
     */
    private const PHASING_CHECKS = [
        // The phasing grid is monthly and aligns with `accounting_periods`;
        // a mid-month date would join to nothing and read as a zero budget.
        'ck_budget_phasings_month_start' => 'DAYOFMONTH(`period_month`) = 1',
    ];
};
