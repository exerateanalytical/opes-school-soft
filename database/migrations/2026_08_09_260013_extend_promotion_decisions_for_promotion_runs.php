<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/07-students.md §10.5, CONVERGING on the ONE promotion_decisions
 * table Phase 7's 255003 created (phase-07 plan decision 4: "Phase 8 builds
 * on this table, not around it"). This is an ALTER, not a second create.
 *
 * What changes:
 *  - `promotion_run_id` NULL FK: a row written by the Phase 7 rollover grid
 *    has no run; a row written by EvaluatePromotionRun names the run whose
 *    criteria produced it. UNIQUE(promotion_run_id, enrollment_id) per §10.5
 *    — trivially satisfied while uq_promotion_decisions_enrollment stands,
 *    kept anyway because the engine's queries key on the pair.
 *  - `decision` becomes NULLABLE: the legacy four-value verdict the rollover
 *    wizard's step 6 consumes. An engine row whose outcome is `indeterminate`
 *    or `manual_review` is genuinely UNDECIDED, and NULL here is exactly what
 *    makes the rollover's "refuses while any enrollment is undecided" guard
 *    fire for it. Decided outcomes map onto the legacy vocabulary
 *    (promote/conditional_promote→promoted, graduate→graduated,
 *    exclude→withdrawn) so both consumers read one answer.
 *  - §10.5's audit columns: `computed_outcome` beside `outcome` so a manual
 *    change is never invisible; `criteria_results` JSON so the printed list
 *    explains itself; `applied_enrollment_id` names the next-year enrollment
 *    the apply created from this decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotion_decisions', function (Blueprint $table): void {
            $table->foreignId('promotion_run_id')
                ->nullable()
                ->after('id')
                ->constrained('promotion_runs')
                ->restrictOnDelete();

            $table->enum('outcome', [
                'promote',
                'repeat',
                'conditional_promote',
                'graduate',
                'exclude',
                'manual_review',
                'indeterminate',
            ])->nullable()->after('enrollment_id');

            // Retained beside `outcome` so an override is visibly an override.
            $table->string('computed_outcome', 30)->nullable()->after('outcome');

            $table->foreignId('target_class_level_id')
                ->nullable()
                ->after('target_class_group_key')
                ->constrained('class_levels')
                ->restrictOnDelete();

            $table->foreignId('target_class_group_id')
                ->nullable()
                ->after('target_class_level_id')
                ->constrained('class_groups')
                ->restrictOnDelete();

            $table->boolean('overridden')->default(false)->after('target_class_group_id');
            $table->string('override_reason', 500)->nullable()->after('overridden');
            $table->foreignId('overridden_by')
                ->nullable()
                ->after('override_reason')
                ->constrained('users')
                ->restrictOnDelete();

            // Per criterion: value, threshold, comparator, verdict — the
            // printed promotion list explains itself (§10.5).
            $table->json('criteria_results')->nullable()->after('overridden_by');

            $table->decimal('annual_average', 6, 3)->nullable()->after('criteria_results');
            $table->decimal('attendance_rate', 6, 3)->nullable()->after('annual_average');

            $table->foreignId('applied_enrollment_id')
                ->nullable()
                ->after('attendance_rate')
                ->constrained('enrollments')
                ->restrictOnDelete();

            $table->unique(['promotion_run_id', 'enrollment_id'], 'uq_promotion_decisions_run_enrollment');
        });

        // Phase 7's enum column relaxed to nullable, values preserved. Raw
        // DDL because change() on an enum requires doctrine/dbal gymnastics.
        DB::statement(
            "ALTER TABLE promotion_decisions MODIFY decision "
            ."ENUM('promoted', 'repeat', 'graduated', 'withdrawn') NULL"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE promotion_decisions MODIFY decision "
            ."ENUM('promoted', 'repeat', 'graduated', 'withdrawn') NOT NULL"
        );

        Schema::table('promotion_decisions', function (Blueprint $table): void {
            $table->dropUnique('uq_promotion_decisions_run_enrollment');

            $table->dropConstrainedForeignId('applied_enrollment_id');
            $table->dropColumn(['annual_average', 'attendance_rate', 'criteria_results']);
            $table->dropConstrainedForeignId('overridden_by');
            $table->dropColumn(['override_reason', 'overridden']);
            $table->dropConstrainedForeignId('target_class_group_id');
            $table->dropConstrainedForeignId('target_class_level_id');
            $table->dropColumn(['computed_outcome', 'outcome']);
            $table->dropConstrainedForeignId('promotion_run_id');
        });
    }
};
