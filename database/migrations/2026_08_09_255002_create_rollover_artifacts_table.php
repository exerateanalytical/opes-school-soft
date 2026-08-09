<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/plans/phase-07.md decision 2 - the undo ledger. Instead of adding a
 * `rollover_run_id` column to seven tables across five modules, every row a
 * rollover step creates is recorded here as (entity_type, entity_id). Undo
 * walks the ledger in reverse-FK order; it refuses once the new year records
 * its first payment, mark or journal entry (docs/specs/08-operations.md §6.3
 * "reversible within a window").
 *
 * `entity_type` is the owning TABLE name (not a class name): undo deletes via
 * DB::table(), which crosses no module boundary and needs no model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rollover_artifacts', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('rollover_run_id')->constrained('rollover_runs')->restrictOnDelete();
            $table->string('entity_type', 120);
            $table->unsignedBigInteger('entity_id');

            // Which wizard step created the row - undo replays steps in
            // reverse order, and a resumed step skips already-recorded
            // artifacts by this natural key.
            $table->unsignedTinyInteger('step');

            $table->timestamps();

            $table->unique(['rollover_run_id', 'entity_type', 'entity_id'], 'uq_rollover_artifacts_entity');
            $table->index(['entity_type', 'entity_id'], 'ix_rollover_artifacts_lookup');
            $table->index(['rollover_run_id', 'step'], 'ix_rollover_artifacts_step');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rollover_artifacts');
    }
};
