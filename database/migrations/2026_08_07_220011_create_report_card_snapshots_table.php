<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/01-assessment.md 13.3 - `ReportCardSnapshot`.
 *
 * "THE SNAPSHOT IS AUTHORITATIVE. Re-render, portal display, transcript
 * assembly and the Statement of Results read the snapshot and NEVER recompute."
 *
 * Everything about the shape of this table follows from that sentence:
 *
 *  - `payload` holds every printed NUMBER already resolved, so nothing is
 *    re-derived from `marks`, `subject_allocations.coefficient` or `grade_bands`
 *    at render time. Those three are precisely what T13 mutates.
 *  - `report_card_config_version_id` is NOT NULL, so nothing is re-derived from
 *    the config head either - the fourth thing T13 mutates.
 *  - `generation` is in the unique key rather than replacing the row, because
 *    15.2 step 4 requires the superseded generation to be RETAINED. A parent
 *    holding a paper card printed from generation 1 must be able to have that
 *    exact document reproduced.
 *
 * The FK on `superseded_by_snapshot_id` is deliberately self-referencing and
 * RESTRICT: the chain from generation 1 to generation N is the recall list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_card_snapshots', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('enrollment_id')
                ->constrained('enrollments')
                ->restrictOnDelete();
            $table->foreignId('assessment_period_id')
                ->constrained('assessment_periods')
                ->restrictOnDelete();

            // 12.6 rule 1: the class group of the EnrollmentSegment covering
            // AssessmentPeriod.ends_on - the class the student finished the
            // period in. Denormalised here because rank and statistics were
            // computed against it and a later transfer must not restate them.
            $table->foreignId('class_group_id')
                ->constrained('class_groups')
                ->restrictOnDelete();

            $table->foreignId('period_publication_id')
                ->constrained('period_publications')
                ->restrictOnDelete();

            $table->unsignedInteger('generation');
            $table->char('snapshot_batch_id', 36);

            $table->foreignId('report_card_config_version_id')
                ->constrained('report_card_config_versions')
                ->restrictOnDelete();

            // 13.3: per-subject component marks and states, normalised ratios,
            // subject scores, coefficients, M x Coef, the totals row, general
            // average, rank, denominator, sigma-coef, mention, GPA, class
            // statistics, conduct, remarks, conseil award and decision,
            // attendance figures and the fee balance where enabled.
            //
            // For Family F (8.4, T19) the average / rank / mention / sigma-coef
            // keys are ABSENT from this document, not present-and-null: a NULL
            // renders as a blank Rang box on a nursery card, which invites a
            // bursar to fill it in.
            $table->json('payload');
            $table->char('payload_hash', 64);

            // 13.7: printed on the card, alongside the generation, as
            // "Version 2 - Emis le 14/03/2026" (15.2).
            $table->dateTime('issued_at');

            // SHA-256 of the issued document. Nullable in the column because a
            // snapshot may in principle exist before its document is produced;
            // PublishPeriod always fills it in the same transaction.
            $table->char('pdf_hash', 64)->nullable();

            // 6.4: every missing_component_policy application, recorded so the
            // card can explain in a footnote why a subject scored what it did.
            $table->json('applied_policy_notes')->nullable();

            $table->foreignId('superseded_by_snapshot_id')
                ->nullable()
                ->constrained('report_card_snapshots')
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique(
                ['enrollment_id', 'assessment_period_id', 'generation'],
                'uq_report_card_snapshots_enrollment_period_gen',
            );

            // The two real read paths: "reissue this batch" and "show me this
            // class group's cards for this period".
            $table->index('snapshot_batch_id', 'idx_report_card_snapshots_batch');
            $table->index(
                ['period_publication_id', 'generation'],
                'idx_report_card_snapshots_publication_gen',
            );
        });

        DB::statement(
            'ALTER TABLE report_card_snapshots ADD CONSTRAINT chk_report_card_snapshots_generation '
            .'CHECK (generation >= 1)'
        );

        // 13.3 again, stated in the database. Once a snapshot exists nothing may
        // rewrite the numbers it issued; the amendment path writes a NEW
        // generation and sets `superseded_by_snapshot_id` on this row, which is
        // the only column an amendment touches.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_report_card_snapshots_immutable
            BEFORE UPDATE ON report_card_snapshots
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.payload_hash <=> OLD.payload_hash)
                    OR NOT (NEW.pdf_hash <=> OLD.pdf_hash)
                    OR NOT (NEW.payload <=> OLD.payload)
                    OR NOT (NEW.generation <=> OLD.generation)
                    OR NOT (NEW.issued_at <=> OLD.issued_at)
                    OR NOT (NEW.snapshot_batch_id <=> OLD.snapshot_batch_id)
                    OR NOT (NEW.report_card_config_version_id <=> OLD.report_card_config_version_id)
                    OR NOT (NEW.enrollment_id <=> OLD.enrollment_id)
                    OR NOT (NEW.assessment_period_id <=> OLD.assessment_period_id)
                THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'A report card snapshot is immutable (01-assessment 13.3). Amend the publication instead: that writes a new generation and supersedes this one.';
                END IF;
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_report_card_snapshots_no_delete
            BEFORE DELETE ON report_card_snapshots
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Report card snapshots are retained, never deleted (01-assessment 15.2 step 4).';
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_report_card_snapshots_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_report_card_snapshots_immutable');
        Schema::dropIfExists('report_card_snapshots');
    }
};
