<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `mark_approvals` - the batch header of 01-assessment 7.3 (`MarkSubmission`),
 * which gives submission and validation an auditable identity instead of
 * leaving 62 rows to flip state with nobody's name on the act.
 *
 * One row per (subject allocation, assessment period, class group). Status
 * moves open -> submitted -> validated | returned, and every transition is a
 * CONDITIONAL UPDATE with an affected-rows check (00-core 10.4), never a
 * read-then-write: two heads of department clicking Validate at the same
 * instant must produce one validation and one refusal, not two.
 *
 * Who decided what, and when, is recorded per decision - submitted_*,
 * validated_*, returned_* - rather than in one overwritten pair, so a batch
 * that was returned and later validated still names both actors.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mark_approvals', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('subject_allocation_id')
                ->constrained('subject_allocations')
                ->restrictOnDelete();

            $table->foreignId('assessment_period_id')
                ->constrained('assessment_periods')
                ->restrictOnDelete();

            $table->foreignId('class_group_id')
                ->constrained('class_groups')
                ->restrictOnDelete();

            $table->enum('status', ['open', 'submitted', 'validated', 'returned'])->default('open');

            // The decision that produced the CURRENT status. Mirrors
            // App\Modules\Assessment\Domain\ApprovalDecision.
            $table->enum('last_decision', ['submit', 'validate', 'reject'])->nullable();

            $table->foreignId('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('submitted_at')->nullable();

            $table->foreignId('validated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('validated_at')->nullable();

            $table->foreignId('returned_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('returned_at')->nullable();

            // 01-assessment 7.3: required when the batch is returned. A
            // rejection with no reason is how a teacher loses an afternoon
            // with nothing to act on.
            $table->string('return_reason', 500)->nullable();

            // How many Mark rows the last decision moved. Printed in the
            // period's publication dossier.
            $table->unsignedInteger('mark_count')->default(0);

            // 00-core 10.6.
            $table->integer('version')->default(1);

            $table->timestamps();

            $table->unique(
                ['subject_allocation_id', 'assessment_period_id', 'class_group_id'],
                'uq_mark_approvals_scope',
            );

            $table->index(['assessment_period_id', 'status'], 'idx_mark_approvals_period_status');
        });

        DB::statement(
            'ALTER TABLE mark_approvals ADD CONSTRAINT chk_mark_approvals_return_reason '
            ."CHECK (status <> 'returned' OR (return_reason IS NOT NULL AND return_reason <> ''))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('mark_approvals');
    }
};
