<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/07-students.md §10.2 — one promotion run per (class group,
 * outgoing year), by constraint.
 *
 * 00-core is explicit that "apply atomically" prevents PARTIAL application,
 * not DOUBLE application — `uq_promotion_run` is what prevents the second
 * run, and the Enrollment UNIQUE (§4.3) is the per-student backstop when a
 * replayed apply races past the status check.
 *
 * `inputs_hash` (§10.3) is the evaluate-then-apply bridge: the principal
 * signs off on a list at T0, the Action refuses at T5 if any input of that
 * list has changed — it never silently re-evaluates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_runs', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // The year being CLOSED.
            $table->foreignId('academic_year_id')
                ->constrained('academic_years')
                ->restrictOnDelete();

            $table->foreignId('class_group_id')
                ->constrained('class_groups')
                ->restrictOnDelete();

            $table->foreignId('target_academic_year_id')
                ->constrained('academic_years')
                ->restrictOnDelete();

            $table->foreignId('criteria_set_id')
                ->constrained('promotion_criteria_sets')
                ->restrictOnDelete();

            $table->enum('status', [
                'evaluating',
                'evaluated',
                'under_review',
                'applying',
                'applied',
                'cancelled',
            ])->default('evaluating');

            // SHA-256 hex over the canonical serialisation of §10.3. NULL
            // until the first evaluation completes.
            $table->char('inputs_hash', 64)->nullable();

            $table->dateTime('evaluated_at')->nullable();
            $table->foreignId('evaluated_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->dateTime('applied_at')->nullable();
            $table->foreignId('applied_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            // C5's cousin: an enrollment whose attendance rate is NULL cannot
            // silently pass the criterion. 'block' (default) refuses apply;
            // 'manual_review' routes the student to the review queue instead.
            $table->enum('on_indeterminate', ['block', 'manual_review'])
                ->default('block');

            $table->string('idempotency_key', 64);

            $table->timestamps();

            $table->unique(['class_group_id', 'academic_year_id'], 'uq_promotion_run');
            $table->unique('idempotency_key', 'uq_promotion_runs_idem');
            $table->index(['academic_year_id', 'status'], 'ix_promotion_runs_year_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_runs');
    }
};
