<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/02-accounting.md §18.3 - `ResultAppropriation` /
 * `ResultAppropriationLine`, plus the three back-references on
 * `fiscal_years` that 2026_08_07_230005 deliberately left as bare
 * `unsignedBigInteger` columns ("point at JournalEntry / a year-end table
 * that does not exist yet").  It exists now, so the FKs go on.
 *
 * The result sits in **13** *Résultat en instance d'affectation* after the
 * §18.1 closing entry and is routed to **11** / reserves / distributions by
 * the resolution the accountant actually holds - which is why
 * `decision_body`, `decision_date` and `resolution_reference` are columns
 * and not comments. The legal-reserve percentage is `NEEDS VERIFICATION`
 * per §18.3 and is therefore NOT encoded anywhere here: the lines are keyed
 * from the minutes, one row per statutory allocation.
 *
 * Invariant AP-1 (`Σ lines = result_amount`, and the posting empties 13
 * exactly) is in-Action, under `FOR UPDATE` - it is a statement about a set
 * of child rows, which a CHECK cannot see. `ck_result_appropriations_
 * approved` is the half that CAN be structural: an approved appropriation
 * names its approver and the entry it posted.
 *
 * `result_amount` and `amount` are BIGINT **signed** FCFA: a loss is a
 * negative result and appropriating it (to 11 report à nouveau débiteur) is
 * the ordinary case for a school's second year, not an exception.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('result_appropriations', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // §18.3: UNIQUE. One appropriation per exercice.
            $table->foreignId('fiscal_year_id')
                ->constrained('fiscal_years')->restrictOnDelete()
                ->unique('uq_result_appropriations_fy');

            $table->string('decision_body', 120);
            $table->date('decision_date');
            $table->string('resolution_reference', 120)->collation('utf8mb4_0900_as_cs');

            // Read from compte 13 after the closing entry; signed.
            $table->bigInteger('result_amount');

            $table->string('status', 20)->collation('utf8mb4_0900_as_cs')->default('draft');

            $table->foreignId('approved_by')->nullable()
                ->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();

            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();

            // The minutes themselves, hashed so a later substitution shows.
            $table->string('document_path', 500)->nullable();
            $table->string('document_sha256', 64)->collation('utf8mb4_0900_as_cs')->nullable();

            $table->timestamps();

            $table->index('status', 'ix_result_appropriations_status');
        });

        Schema::create('result_appropriation_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('result_appropriation_id')
                ->constrained('result_appropriations')->restrictOnDelete();

            // Legal reserve, other reserves, 11 report à nouveau,
            // distributions, any statutory allocation.
            $table->foreignId('account_id')
                ->constrained('chart_of_accounts')->restrictOnDelete();

            $table->bigInteger('amount');
            $table->string('label', 200);

            $table->unsignedSmallInteger('sequence')->default(1);

            $table->timestamps();

            $table->index('result_appropriation_id', 'ix_ral_appropriation');
        });

        foreach (self::CHECKS as $name => $expression) {
            DB::statement("ALTER TABLE `result_appropriations` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }

        // A zero-amount allocation line is noise on a legal document.
        DB::statement(
            'ALTER TABLE `result_appropriation_lines` '
            .'ADD CONSTRAINT `ck_ral_amount_nonzero` CHECK (`amount` <> 0)'
        );

        // The three back-references §6 reserved. All rows are NULL today, so
        // the constraints apply to an empty set and cannot fail.
        Schema::table('fiscal_years', function (Blueprint $table): void {
            $table->foreign('opening_entry_id', 'fk_fiscal_years_opening_entry')
                ->references('id')->on('journal_entries')->restrictOnDelete();
            $table->foreign('closing_entry_id', 'fk_fiscal_years_closing_entry')
                ->references('id')->on('journal_entries')->restrictOnDelete();
            $table->foreign('result_appropriation_id', 'fk_fiscal_years_appropriation')
                ->references('id')->on('result_appropriations')->restrictOnDelete();
        });

        // §18.2 "One AN entry per fiscal year ... UNIQUE on the
        // `fiscal_year.opening_entry_id` back-reference": one entry can be
        // the à-nouveaux of exactly one exercice. MySQL treats each NULL in
        // a unique index as distinct, so unopened years are unaffected.
        DB::statement(
            'ALTER TABLE `fiscal_years` ADD UNIQUE INDEX `uq_fiscal_years_opening_entry` (`opening_entry_id`)'
        );
        DB::statement(
            'ALTER TABLE `fiscal_years` ADD UNIQUE INDEX `uq_fiscal_years_closing_entry` (`closing_entry_id`)'
        );
    }

    public function down(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table): void {
            $table->dropForeign('fk_fiscal_years_appropriation');
            $table->dropForeign('fk_fiscal_years_closing_entry');
            $table->dropForeign('fk_fiscal_years_opening_entry');
        });

        DB::statement('ALTER TABLE `fiscal_years` DROP INDEX `uq_fiscal_years_opening_entry`');
        DB::statement('ALTER TABLE `fiscal_years` DROP INDEX `uq_fiscal_years_closing_entry`');

        Schema::dropIfExists('result_appropriation_lines');
        Schema::dropIfExists('result_appropriations');
    }

    /**
     * @var array<string, string>
     */
    private const CHECKS = [
        'ck_result_appropriations_status' => "`status` IN ('draft','approved')",

        'ck_result_appropriations_approved' => "`status` <> 'approved' OR (`approved_by` IS NOT NULL AND `approved_at` IS NOT NULL)",

        // A hash without a document (or the reverse) is a broken record.
        'ck_result_appropriations_document' => '(`document_path` IS NULL AND `document_sha256` IS NULL) OR (`document_path` IS NOT NULL AND `document_sha256` IS NOT NULL)',
    ];
};
