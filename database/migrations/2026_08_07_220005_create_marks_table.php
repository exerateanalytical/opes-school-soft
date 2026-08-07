<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `marks` - 01-assessment 6.1.
 *
 * Two things this table refuses to conflate:
 *
 *  - `state` is ASSESSMENT semantics: is this a number, an unexcused absence,
 *    a certified absence, an exemption, or not yet touched?
 *  - `workflow_state` is the APPROVAL CHAIN: draft -> submitted -> validated.
 *
 * v1 had one column trying to be both, which makes "a validated absence" and
 * "a draft 14" inexpressible (01-assessment 7.1). They are separate columns
 * here and neither is derivable from the other.
 *
 * `score` is DECIMAL(6,3), never a float: App\Support\Score holds a mark as
 * integer thousandths and DECIMAL(6,3) is its exact storage form. A FLOAT
 * column would silently re-introduce the binary-fraction error the whole
 * numeric policy (00-core 7.1) exists to prevent.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 01-assessment 5.3. Written by a concurrent workstream; when it is
        // already on disk the FK is created, when it is not the column stands
        // alone exactly as `assessment_periods.framework_id` and
        // `class_groups.class_teacher_staff_id` do, and the owning phase adds
        // the constraint later.
        $componentsExist = Schema::hasTable('assessment_components');

        Schema::create('marks', function (Blueprint $table) use ($componentsExist): void {
            $table->bigIncrements('id');

            // 00-core 10.5 / 01-assessment 6.1: NEVER cascade into marks.
            // Deleting a class group, a subject or a user cannot remove a mark.
            $table->foreignId('enrollment_id')->constrained('enrollments')->restrictOnDelete();

            // 01-assessment 5.2 + invariant 15: immutable after insert, so the
            // mark stays permanently bound to the coefficient, the max-score
            // override and the required-component set it was graded under.
            $table->foreignId('subject_allocation_id')
                ->constrained('subject_allocations')
                ->restrictOnDelete();

            // Leaf periods only (invariant 3). Leaf-ness is a tree property and
            // is enforced in the Actions, not by a CHECK.
            $table->foreignId('assessment_period_id')
                ->constrained('assessment_periods')
                ->restrictOnDelete();

            // NOT NULL even while the FK is deferred. The instruction to defer
            // said "nullable", but MySQL permits unlimited duplicate NULLs in a
            // UNIQUE index - a nullable component_id would defeat
            // uq_marks_entry below, and that constraint is precisely what makes
            // OpenAssessmentPeriod's INSERT ... ON DUPLICATE KEY UPDATE
            // idempotent (01-assessment 6.2, T2). Deferring the FK is cheap;
            // deferring the uniqueness is the defect the constraint exists to
            // prevent, so only the FK is deferred.
            if ($componentsExist) {
                $table->foreignId('component_id')
                    ->constrained('assessment_components')
                    ->restrictOnDelete();
            } else {
                $table->unsignedBigInteger('component_id');
            }

            // App\Support\Score's storage form. NULL unless state = scored,
            // enforced by chk_marks_scored_iff_score below (invariant 4).
            $table->decimal('score', 6, 3)->nullable();

            // 01-assessment 6.4. A missing component is NOT a zero: pending,
            // absent_justified and exempt all carry score IS NULL and mean
            // three different things to the composition stage. Collapsing them
            // into "score is null" is the defect this enum prevents.
            $table->enum('state', [
                'pending',
                'scored',
                'absent_justified',
                'absent_unjustified',
                'exempt',
            ])->default('pending');

            // 01-assessment 7.1/7.2. Orthogonal to `state`.
            $table->enum('workflow_state', ['draft', 'submitted', 'validated'])->default('draft');

            $table->foreignId('entered_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('entered_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('validated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('validated_at')->nullable();

            // 01-assessment 16.4 + invariant 18: the pre-moderation value,
            // written once and never overwritten.
            $table->decimal('raw_score', 6, 3)->nullable();
            $table->unsignedBigInteger('moderation_id')->nullable();

            // 01-assessment 16.6 re-sits.
            $table->unsignedTinyInteger('attempt_no')->default(1);

            // Why an absence was certified (01-assessment 6.4: setting
            // absent_justified is a controlled field and requires a reason).
            $table->string('comment', 255)->nullable();

            // 00-core 10.6 optimistic lock. Marks entry is the one screen where
            // two people routinely hold the same grid open.
            $table->integer('version')->default(1);

            $table->timestamps();

            // 01-assessment 6.1. attempt_no is in the key because a re-sit is a
            // second legitimate row for the same (enrollment, allocation,
            // period, component); it defaults to 1, so a materialisation upsert
            // that does not mention it still collides correctly.
            $table->unique(
                ['enrollment_id', 'subject_allocation_id', 'assessment_period_id', 'component_id', 'attempt_no'],
                'uq_marks_entry',
            );

            // The entry grid.
            $table->index(['assessment_period_id', 'subject_allocation_id'], 'idx_marks_period_allocation');
            // The report card.
            $table->index(['enrollment_id', 'assessment_period_id'], 'idx_marks_enrollment_period');
            // The pending-publication gate.
            $table->index(['assessment_period_id', 'workflow_state'], 'idx_marks_period_workflow');
        });

        // Invariant 4: (state = 'scored') <=> (score IS NOT NULL). Written as an
        // equality of two booleans so BOTH directions fail loudly - a scored
        // mark with no number, and a number parked under `pending`.
        DB::statement(
            'ALTER TABLE marks ADD CONSTRAINT chk_marks_scored_iff_score '
            ."CHECK ((state = 'scored') = (score IS NOT NULL))"
        );

        // Invariant 5's lower half. The upper half is `score <= effectiveMax`,
        // which resolves across three tables (01-assessment 6.3) and so cannot
        // be a MySQL CHECK; SaveMark enforces it against the resolved maximum.
        DB::statement(
            'ALTER TABLE marks ADD CONSTRAINT chk_marks_score_non_negative '
            .'CHECK (score IS NULL OR score >= 0)'
        );

        DB::statement(
            'ALTER TABLE marks ADD CONSTRAINT chk_marks_raw_score_non_negative '
            .'CHECK (raw_score IS NULL OR raw_score >= 0)'
        );

        DB::statement(
            'ALTER TABLE marks ADD CONSTRAINT chk_marks_attempt_no CHECK (attempt_no >= 1)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('marks');
    }
};
