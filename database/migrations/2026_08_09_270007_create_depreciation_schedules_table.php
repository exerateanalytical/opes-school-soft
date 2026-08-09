<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/06-assets-stores.md §4.2 - one row per asset per period per
 * basis. `charge` is SIGNED (a catch-up correction after a change in
 * estimate can be negative, §5.5). Only the `accounting` basis ever posts;
 * `fiscal` feeds the DSF réintégrations working paper and stays unposted.
 *
 * depreciation_run_id is NULLABLE for the §4.5 disposal-time catch-up row,
 * which is written inside the disposal transaction outside any run. MySQL
 * permits many NULLs under uq_dep_sched_run, so disposal rows never
 * collide there; uq_dep_sched_period still guards one row per period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depreciation_schedules', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('asset_id')
                ->constrained('assets')->restrictOnDelete();
            $table->foreignId('depreciation_run_id')->nullable()
                ->constrained('depreciation_runs')->restrictOnDelete();

            $table->foreignId('fiscal_year_id')
                ->constrained('fiscal_years')->restrictOnDelete();
            $table->unsignedTinyInteger('period_month');

            $table->enum('basis', ['accounting', 'fiscal']);

            $table->bigInteger('opening_accumulated');
            $table->bigInteger('charge'); // SIGNED by design (§5.5)
            $table->bigInteger('closing_accumulated');

            // Derived and stored for reporting.
            $table->bigInteger('net_book_value');

            // cost − residual as at this period.
            $table->bigInteger('depreciable_base');

            $table->unsignedSmallInteger('months_elapsed');
            $table->boolean('is_catch_up')->default(false);

            // NULL for the fiscal basis (never posted); stamped by
            // PostDepreciationRun / DisposeAsset for the accounting basis.
            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();

            $table->timestamps();

            // 00-core §10.4 extended by basis.
            $table->unique(
                ['asset_id', 'fiscal_year_id', 'period_month', 'basis'],
                'uq_dep_sched_period'
            );
            $table->unique(['asset_id', 'depreciation_run_id', 'basis'], 'uq_dep_sched_run');
        });

        DB::statement(
            'ALTER TABLE depreciation_schedules ADD CONSTRAINT chk_dep_sched_sum CHECK ( '
            .'closing_accumulated = opening_accumulated + charge )'
        );

        // The cap that stops over-depreciation (§4.3's min()).
        DB::statement(
            'ALTER TABLE depreciation_schedules ADD CONSTRAINT chk_dep_sched_cap CHECK ( '
            .'closing_accumulated <= depreciable_base )'
        );

        DB::statement(
            'ALTER TABLE depreciation_schedules ADD CONSTRAINT chk_dep_sched_month CHECK ( '
            .'period_month BETWEEN 1 AND 12 )'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('depreciation_schedules');
    }
};
