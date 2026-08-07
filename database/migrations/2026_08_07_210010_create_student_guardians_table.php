<?php

declare(strict_types=1);

use App\Modules\Guardians\Domain\GuardianRelationship;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/07-students.md 7.2 - the link, and the carrier of the entire 7.5
 * authorization matrix.
 *
 * Keyed on `student_id`, not `enrollment_id` (3.4): the relationship survives
 * the year. It is also DATE-RANGED rather than mutable, which is what makes
 * "a custody change neither deletes history nor leaves stale access" true
 * rather than aspirational - a scope change closes the current row and inserts
 * a successor, so the question "who could see this child's marks last March?"
 * has an answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_guardians', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('guardian_id');

            $table->enum('relationship', GuardianRelationship::values());
            // Mandatory when `relationship = other`; enforced in LinkGuardian,
            // because MySQL 8 CHECK cannot reference an enum comparison and a
            // NULL in the same predicate portably enough to be worth it.
            $table->string('relationship_other', 60)->nullable();

            // Selects the default recipient for a single-recipient message and
            // the default addressee on printed documents. 7.5 is emphatic that
            // it grants NOTHING on its own, and GuardianAuthorizationFlags
            // omits it entirely so that it cannot become an input by accident.
            $table->boolean('is_primary')->default(false);

            // The five authorization flags. Each is a PER-CHILD grant; the
            // identically-named columns on `guardians` are per-person delivery
            // preferences and answer a different question (7.4).
            $table->boolean('has_custody')->default(false);
            $table->boolean('receives_reports')->default(false);
            $table->boolean('receives_invoices')->default(false);
            $table->boolean('is_emergency_contact')->default(false);
            $table->boolean('is_authorised_for_pickup')->default(false);
            $table->boolean('is_fee_payer')->default(false);

            // 7.3's window. A link with `valid_from` in the future grants
            // nothing - which is why SetGuardianAuthorization can safely date
            // its successor row to tomorrow.
            $table->date('valid_from');
            $table->date('valid_to')->nullable();

            // Mandatory when valid_to is set to a past/current date (7.2).
            // "Why did this person lose access to this child" is the first
            // question asked in a custody dispute.
            $table->string('revocation_reason', 255)->nullable();

            // 00-core 10.1's generated-column pattern, because MySQL 8 has no
            // partial indexes. UNIQUE over this column means: at most one
            // OPEN primary link per student. Closed links (valid_to set) and
            // non-primary links all produce NULL, and MySQL treats every NULL
            // in a unique index as distinct, so history accumulates freely
            // while the live invariant holds at the storage layer - not merely
            // in an Action that a future import script might bypass.
            $table->unsignedBigInteger('primary_key_col')->nullable()
                ->storedAs('(CASE WHEN `is_primary` = 1 AND `valid_to` IS NULL THEN `student_id` END)');

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();

            // 13. `uq_link` also gives the "one open link per guardian per
            // student" rule its storage-level teeth for the common case:
            // re-linking after revocation must use a LATER valid_from.
            $table->unique(['student_id', 'guardian_id', 'valid_from'], 'uq_link');
            $table->unique('primary_key_col', 'uq_primary_guardian');
            $table->index(['guardian_id', 'valid_to'], 'idx_sg_guardian_valid');
            $table->index(['student_id', 'valid_to'], 'idx_sg_student_valid');

            // 3.4: every FK in the satellite table is RESTRICT. Students and
            // guardians are never deleted; pseudonymisation is the erasure
            // path (08-operations).
            $table->foreign('student_id', 'fk_sg_student')
                ->references('id')->on('students')
                ->restrictOnDelete()->restrictOnUpdate();

            $table->foreign('guardian_id', 'fk_sg_guardian')
                ->references('id')->on('guardians')
                ->restrictOnDelete()->restrictOnUpdate();

            $table->foreign('created_by', 'fk_sg_created_by')
                ->references('id')->on('users')
                ->restrictOnDelete()->restrictOnUpdate();

            $table->foreign('updated_by', 'fk_sg_updated_by')
                ->references('id')->on('users')
                ->restrictOnDelete()->restrictOnUpdate();
        });

        // 7.2. Expressed as a real CHECK rather than an Action-level guard
        // because an inverted date range silently inverts the 7.3 predicate:
        // the link would be valid on no day at all, and the failure would show
        // up as "the portal is empty", weeks later, with no error anywhere.
        DB::statement(
            'ALTER TABLE `student_guardians` '.
            'ADD CONSTRAINT `ck_sg_valid_range` CHECK (`valid_to` IS NULL OR `valid_to` >= `valid_from`)'
        );

        // 7.2: "is_primary = 1 implies has_custody = 1. Rejected otherwise."
        // The Action rejects it too, with a legible message; this is the
        // backstop for imports and for anyone reaching the table directly.
        DB::statement(
            'ALTER TABLE `student_guardians` '.
            'ADD CONSTRAINT `ck_sg_primary_implies_custody` CHECK (`is_primary` = 0 OR `has_custody` = 1)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('student_guardians');
    }
};
