<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/02-accounting.md §10.2 - Lettering groups (C10).
 *
 * A group nets lines on the same collective account and partner to zero (a
 * payment matched against an invoice). LT-2 ("a full group satisfies
 * Σdebit = Σcredit") is enforced primarily IN-ACTION under
 * `SELECT ... FOR UPDATE` on this table's row (LetterEntries,
 * §10.3) - the CHECK below is the backstop the spec names explicitly, not
 * the primary mechanism. LT-1 (every line shares account/partner) and LT-4
 * (auto-promotion to full) are in-Action only per the invariant table: the
 * only two writers of this table are LetterEntries and UnletterGroup, both
 * owned by this same agent, so - unlike journal_entry_lines, which posting
 * rules/imports/manual entry can all reach independently - a trigger would
 * not be proving anything an Action-level check does not already prove.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letterings', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete();

            // Polymorphic partner, matching journal_entry_lines
            // (02-accounting §4.2/§8.3) - deliberately NOT an FK on
            // partner_id. Integrity is Action-level (LT-1) plus the nightly
            // orphan job that column already relies on.
            $table->enum('partner_type', ['student', 'guardian', 'supplier', 'staff', 'organisation']);
            $table->unsignedBigInteger('partner_id');

            // 00-core §4: identifier columns are accent-/case-sensitive.
            $table->string('code', 10)->collation('utf8mb4_0900_as_cs');

            $table->enum('status', ['partial', 'full'])->default('partial');

            $table->bigInteger('total_debit')->default(0);
            $table->bigInteger('total_credit')->default(0);

            $table->foreignId('lettered_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('lettered_at');

            $table->foreignId('unlettered_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('unlettered_at')->nullable();
            $table->string('unletter_reason', 500)->nullable();

            $table->boolean('is_auto')->default(false);

            $table->timestamps();

            $table->unique(['account_id', 'partner_type', 'partner_id', 'code'], 'uq_lettering_code');
        });

        DB::statement(
            'ALTER TABLE letterings ADD CONSTRAINT ck_lettering_full '
            ."CHECK (status = 'partial' OR total_debit = total_credit)"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('letterings');
    }
};
