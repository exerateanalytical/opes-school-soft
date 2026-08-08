<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/01-assessment.md 15.1 - `ReportCardAmendment`, C10.
 *
 * The table is named for the entity the spec names, not for the file: a
 * post-publication mark correction is an AMENDMENT TO A PUBLICATION, which is
 * why `period_publication_id` is the parent and there is no `enrollment_id`
 * column anywhere on this row.
 *
 * That absence is the correction. v1 treated a correction as a single-student
 * edit. It is not: changing one mark changes that student's average, which
 * changes the class mean, min, max, pass rate, standard deviation and every
 * other student's rank - all of which are already printed on 61 other cards. So
 * the amendment records the class-wide consequence (`affected_enrollment_ids`)
 * rather than the student, and T15 asserts that set contains more than the
 * corrected child.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_card_amendments', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('period_publication_id')
                ->constrained('period_publications')
                ->restrictOnDelete();

            $table->unsignedInteger('from_generation');
            $table->unsignedInteger('to_generation');

            // NOT NULL and no default. An amendment with no stated reason is an
            // unexplained change to an issued document; the column refuses it
            // rather than the form doing so, because the form is not the only
            // writer a system acquires.
            $table->string('reason', 1000);

            $table->foreignId('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            // 15.1: "approval is Principal-level".
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();

            // 15.2. `reissue_class` recomputes ranks and statistics and reissues
            // every affected card - correct, and expensive. `freeze_at_publication`
            // updates only the corrected student's own numbers, leaves ranks and
            // class statistics at their generation-1 values, and prints
            // "Classement fige au JJ/MM/AAAA". The second option exists because a
            // school will not recall 62 cards for a 0.25-point correction, and
            // pretending otherwise produces off-ledger manual edits.
            $table->enum('rank_freeze_policy', ['reissue_class', 'freeze_at_publication'])
                ->default('reissue_class');

            // Computed by AmendMarks and returned to the caller: the students
            // whose PRINTED VALUES changed, so the school knows exactly which
            // cards to recall.
            $table->json('affected_enrollment_ids')->nullable();

            // Before/after per mark, so the amendment is self-describing without
            // joining the audit log.
            $table->json('mark_changes');

            $table->enum('status', ['draft', 'applied'])->default('draft');
            $table->dateTime('applied_at')->nullable();

            $table->timestamps();

            $table->index(
                ['period_publication_id', 'to_generation'],
                'idx_report_card_amendments_publication_gen',
            );
        });

        DB::statement(
            'ALTER TABLE report_card_amendments ADD CONSTRAINT chk_report_card_amendments_generations '
            .'CHECK (to_generation = from_generation + 1 AND from_generation >= 1)'
        );

        DB::statement(
            'ALTER TABLE report_card_amendments ADD CONSTRAINT chk_report_card_amendments_applied '
            ."CHECK (status <> 'applied' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL AND applied_at IS NOT NULL))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('report_card_amendments');
    }
};
