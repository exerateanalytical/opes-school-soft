<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/06-assets-stores.md §4.1 - depreciation_runs. One row per
 * (fiscal year, period month): UNIQUE(fiscal_year_id, period_month) is the
 * duplicate-run mechanism v1 asserted and never built. Status transitions
 * use conditional UPDATE ... WHERE status = ... with an affected-rows
 * check; segregation (approved_by <> run_by) is Action-enforced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depreciation_runs', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('fiscal_year_id')
                ->constrained('fiscal_years')->restrictOnDelete();

            // 00-core §5 naming: 1-12 within the fiscal year.
            $table->unsignedTinyInteger('period_month');

            $table->enum('status', ['draft', 'calculated', 'approved', 'posted', 'cancelled'])
                ->default('draft');

            $table->foreignId('run_by')
                ->constrained('users')->restrictOnDelete();
            $table->timestamp('run_at')->nullable();
            $table->foreignId('approved_by')->nullable()
                ->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();

            // The single accounting-basis charge entry (one JE per run).
            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();

            // Denormalised for the run report.
            $table->unsignedInteger('assets_processed')->default(0);
            $table->bigInteger('total_charge')->default(0);

            // Assets skipped and why (V1/V2/V3/V5 gates land here).
            $table->json('exceptions_json')->nullable();

            $table->string('idempotency_key', 100)->nullable()->unique('uq_dep_runs_idem');

            $table->timestamps();

            // The mechanism: one run per period, enforced by the database.
            $table->unique(['fiscal_year_id', 'period_month'], 'uq_dep_runs_period');
            $table->index(['status'], 'ix_dep_runs_status');
        });

        DB::statement(
            'ALTER TABLE depreciation_runs ADD CONSTRAINT chk_dep_runs_month CHECK ( '
            .'period_month BETWEEN 1 AND 12 )'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('depreciation_runs');
    }
};
