<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/08-operations.md §6.3 - the resumability record for the year
 * rollover wizard. One row per (from, to) year pair, enforced by constraint:
 * "Idempotent. UNIQUE(academic_year_from_id, academic_year_to_id)".
 *
 * `academic_year_to_id` is NULLABLE because the run row is created at step 0
 * (pre-flight) while the new year only comes into existence at step 1; the
 * step-1 Action fills it in. MySQL permits several NULLs under a UNIQUE key,
 * so the "one running preflight per outgoing year" guard belongs to
 * StartRolloverRun, not the schema - once step 1 completes the pair is unique
 * by constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rollover_runs', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('academic_year_from_id')->constrained('academic_years')->restrictOnDelete();
            $table->foreignId('academic_year_to_id')->nullable()->constrained('academic_years')->restrictOnDelete();

            // 0-10, mirrors App\Modules\Operations\Domain\RolloverStep.
            $table->unsignedTinyInteger('current_step')->default(0);

            // Per-step state map keyed by step number: idempotency keys,
            // completion timestamps, preview counts (§6.3 "resumable after a
            // power cut ... plus per-step idempotency keys").
            $table->json('step_states')->nullable();

            // SHA-256 over the wizard inputs; restarting re-validates earlier
            // steps against it so a resumed run is byte-identical to an
            // uninterrupted one (§6.3 acceptance criterion).
            $table->char('inputs_hash', 64)->nullable();

            $table->enum('status', ['running', 'completed', 'undone', 'failed'])->default('running');

            $table->foreignId('operator_id')->constrained('users')->restrictOnDelete();

            // The mandatory verified pre-rollover backup (§6.2 step 0).
            // Nullable only because the run row exists before the backup
            // finishes; StartRolloverRun refuses to advance past step 0
            // without a verified backup id recorded here.
            $table->foreignId('backup_id')->nullable()->constrained('backups')->restrictOnDelete();

            $table->timestamps();

            $table->unique(['academic_year_from_id', 'academic_year_to_id'], 'uq_rollover_runs_pair');
            $table->index(['status', 'current_step'], 'ix_rollover_runs_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rollover_runs');
    }
};
