<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_years', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // 00-core §4: identifier columns are accent- and case-sensitive.
            $table->string('code', 10)->collation('utf8mb4_0900_as_cs')->unique();

            $table->date('starts_on');
            $table->date('ends_on');
            $table->enum('status', ['planned', 'open', 'closing', 'closed'])->default('planned');

            // 02-accounting §0 "Exercice" row / §6: an irregular first exercice
            // is permitted (a school created mid-year closes its first exercice
            // on 31 December of that year); every other year is a strict
            // calendar year. Deliberately NOT linked to academic_year_id: 02
            // §7 (C3) and 00-core §8's "product consequence" are explicit that
            // a Cameroonian school's fiscal year and academic year do not and
            // cannot coincide. Each governing date resolves its fiscal_year_id
            // and academic_year_id independently; FiscalYear carries no FK to
            // AcademicYear at all.
            $table->boolean('is_first_exercice')->default(false);

            // 02-accounting §6: opening_entry_id / closing_entry_id / and
            // result_appropriation_id point at JournalEntry / a year-end
            // appropriation record, owned by Agent D3 and a later phase
            // respectively. journal_entries is not this migration's table to
            // depend on landing first (or at all, by this point in the build),
            // so these are plain nullable columns, not enforced FKs, until the
            // owning migration exists to constrain against. RESTRICT is the
            // intended eventual behaviour per 00-core §10.5.
            $table->unsignedBigInteger('opening_entry_id')->nullable();
            $table->unsignedBigInteger('closing_entry_id')->nullable();
            $table->unsignedBigInteger('result_appropriation_id')->nullable();

            // Owned by 03-tax-procurement; stored here because it is per-year.
            $table->integer('prorata_de_deduction_bp')->nullable();
            $table->timestamp('dsf_filed_at')->nullable();
            $table->string('dsf_reference', 80)->nullable();

            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->index('status');
        });

        // Single-row sanity check; the multi-row contiguous/non-overlapping
        // invariant across fiscal years is enforced in CreateFiscalYear under
        // lock, mirroring CreateAcademicYear (00-core §8's pattern).
        DB::statement(
            'ALTER TABLE fiscal_years ADD CONSTRAINT chk_fiscal_years_dates CHECK (starts_on < ends_on)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_years');
    }
};
