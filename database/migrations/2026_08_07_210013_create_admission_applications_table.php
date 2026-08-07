<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admissions, docs/specs/07-students.md 6.
 *
 * Three tables, one migration, because they are one aggregate: an
 * AdmissionApplication is meaningless without the guardians proposed at step 3
 * and the documents attached at step 5, and none of the three is written by
 * anything other than the Admissions module.
 *
 * Two deliberate schema decisions worth defending:
 *
 *  1. **Almost every step column is nullable.** 6.2 requires the draft row to
 *     exist from the first Next so a power cut loses at most one step. A row
 *     that only carries step 1 cannot satisfy NOT NULL on a step 2 column, so
 *     completeness is asserted by SubmitApplication - at the moment the
 *     operator claims the form is finished - rather than by the storage
 *     engine, which would make drafts impossible. The columns that are NOT
 *     NULL (`status`, `current_step`, `completed_step`) are the ones the
 *     wizard itself sets on insert.
 *
 *  2. **`converted_student_id` is UNIQUE, and that is the load-bearing part.**
 *     6.3 step 2 calls it "the idempotency backstop": it is what makes it
 *     physically impossible for one application to produce two students, even
 *     if two operators press Confirm at the same instant. RESTRICT on delete,
 *     like every other people-facing FK in this phase - a student who came
 *     through admissions must not be deletable out from under the record that
 *     admitted them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_applications', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // Allocated at SUBMIT from the row-locked sequence table, never at
            // draft creation (6.2): a previewed number that is never submitted
            // must not be burned. Nullable for exactly as long as the row is a
            // draft. AS_CS so 'APP' and 'app' can never collide silently.
            $table->string('application_no', 32)
                ->collation('utf8mb4_0900_as_cs')
                ->nullable()
                ->unique();

            // 00-core 6.2 rule 7. Nullable: an application created through the
            // wizard has no external caller to deduplicate against.
            $table->string('idempotency_key', 64)->nullable()->unique();

            // --- Step 2, Academic Details -------------------------------
            $table->foreignId('academic_year_id')->nullable()
                ->constrained('academic_years')->restrictOnDelete();

            // "Admission Term" on the wizard - an AssessmentPeriod.
            $table->foreignId('admission_term_id')->nullable()
                ->constrained('assessment_periods')->restrictOnDelete();

            $table->foreignId('school_section_id')->nullable()
                ->constrained('school_sections')->restrictOnDelete();

            // Applied-for LEVEL, never a class group: 6.3 step 4 is explicit
            // that the group is chosen at conversion, when capacity is known.
            $table->foreignId('class_level_id')->nullable()
                ->constrained('class_levels')->restrictOnDelete();

            $table->foreignId('stream_id')->nullable()
                ->constrained('streams')->restrictOnDelete();

            // School-defined (day/boarding intent, scholarship stream). Free
            // text until the AdmissionCategory reference table lands.
            $table->string('category', 40)->nullable();

            $table->date('admission_date')->nullable();
            $table->unsignedSmallInteger('proposed_roll_number')->nullable();

            // --- Step 1, Basic Information ------------------------------
            // Same types and same encryption as Student 3.1: an application
            // that becomes a student must not have to be re-typed or, worse,
            // silently downgrade the protection on a minor's health data.
            $table->string('first_name', 80)->nullable();
            $table->string('middle_name', 80)->nullable();
            $table->string('last_name', 80)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['male', 'female'])->nullable();
            $table->char('nationality', 2)->nullable();
            $table->string('place_of_birth', 120)->nullable();
            $table->string('state_of_origin', 80)->nullable();

            // TEXT, not VARCHAR(60): Laravel's `encrypted` cast stores a
            // base64 JSON envelope (iv + value + mac), which is an order of
            // magnitude longer than the plaintext it protects.
            $table->text('religion')->nullable();
            $table->text('blood_group')->nullable();
            $table->text('genotype')->nullable();

            // --- Step 4, Other Information ------------------------------
            $table->string('previous_school_name', 160)->nullable();
            $table->string('last_class_completed', 80)->nullable();
            $table->unsignedSmallInteger('year_completed')->nullable();
            $table->string('reason_for_leaving', 255)->nullable();

            // Encrypted: 6.1 is explicit that this is health data about a
            // minor, and the wizard's own placeholder invites exactly that.
            $table->text('special_information')->nullable();

            // --- Step 5, Documents & Review -----------------------------
            // Private disk only (08-operations); never a public URL.
            $table->string('photo_path', 255)->nullable();

            // --- Lifecycle ----------------------------------------------
            $table->enum('status', [
                'draft',
                'submitted',
                'under_review',
                'accepted',
                'rejected',
                'enrolled',
                'expired',
                'withdrawn',
            ])->default('draft');

            // Draft resume (6.2): `current_step` is the step the operator is
            // looking at, `completed_step` the high-water mark of steps that
            // have passed validation. They are normally one apart, and are
            // separate columns because walking Back moves the first and must
            // never move the second - 11.4 requires Back never to lose data,
            // and losing progress is losing data.
            $table->unsignedTinyInteger('current_step')->default(1);
            $table->unsignedTinyInteger('completed_step')->default(0);

            $table->timestamp('submitted_at')->nullable();

            $table->foreignId('decided_by')->nullable()
                ->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_reason', 255)->nullable();

            $table->foreignId('converted_student_id')->nullable()
                ->constrained('students')->restrictOnDelete();

            // 6.5: decided_at + 12 months for rejected / expired / withdrawn.
            // NULL for every other status, which is what makes the purge job's
            // "WHERE purge_due_on <= today" both correct and index-friendly.
            $table->date('purge_due_on')->nullable();

            $table->foreignId('created_by')->nullable()
                ->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()
                ->constrained('users')->restrictOnDelete();

            $table->timestamps();

            // 13. The idempotency backstop on conversion.
            $table->unique('converted_student_id', 'uq_admission_converted_student');

            // Serves the purge job (6.5) and the Drafts / Submitted tabs.
            $table->index(['status', 'purge_due_on'], 'idx_admission_status_purge');
        });

        Schema::create('admission_application_guardians', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // CASCADE, uniquely in this module: these rows are step-3 form
            // state on a pre-student draft, not a guardian record. The real
            // Guardian is match-or-created at conversion (6.3 step 5) and its
            // links are RESTRICT there. Cascading here means a discarded draft
            // does not strand orphan rows carrying a parent's ID number.
            $table->foreignId('admission_application_id')
                ->constrained('admission_applications')
                ->cascadeOnDelete();

            // Ordinal within the application, so "guardian 2" is stable across
            // a reload and the UNIQUE below can stop a double-submitted step 3
            // from duplicating the same parent.
            $table->unsignedTinyInteger('position');

            $table->string('title', 16)->nullable();
            $table->string('first_name', 80);
            $table->string('last_name', 80);

            // NOT NULL on `guardians` (7.1), so it is required here too:
            // capturing it only at conversion would mean the operator is asked
            // for it at the moment the transaction is trying to commit.
            $table->enum('gender', ['male', 'female']);
            $table->date('date_of_birth')->nullable();

            $table->enum('relationship', [
                'father', 'mother', 'stepfather', 'stepmother', 'grandparent',
                'uncle', 'aunt', 'sibling', 'legal_guardian', 'sponsor', 'other',
            ]);
            $table->string('relationship_other', 60)->nullable();

            $table->boolean('is_primary')->default(false);

            $table->enum('id_type', [
                'national_id', 'passport', 'residence_permit', 'drivers_licence', 'other',
            ])->nullable();

            // Encrypted like Guardian 7.1. No blind index here: duplicate
            // detection is the Guardians module's job at conversion, against
            // its own table, and duplicating the hashing rule in two modules
            // is how the two drift apart.
            $table->text('id_number')->nullable();

            $table->string('occupation', 120)->nullable();
            $table->string('employer', 120)->nullable();

            $table->string('phone', 24);
            $table->string('alternative_phone', 24)->nullable();
            $table->string('email', 160)->nullable();

            $table->string('address_line', 255)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('region', 80)->nullable();

            // Drives every message and document sent to them (7.1).
            $table->enum('language', ['en', 'fr'])->default('en');

            // The authorization flags PROPOSED at step 3. They are proposals,
            // not grants: nothing is authorised until conversion writes a real
            // StudentGuardian link (7.5), which is why they live here rather
            // than being applied to anything at draft time.
            $table->boolean('has_custody')->default(false);
            $table->boolean('receives_reports')->default(false);
            $table->boolean('receives_invoices')->default(false);
            $table->boolean('is_emergency_contact')->default(false);
            $table->boolean('is_authorised_for_pickup')->default(false);
            $table->boolean('is_fee_payer')->default(false);

            $table->timestamps();

            $table->unique(
                ['admission_application_id', 'position'],
                'uq_admission_guardian_position',
            );
        });

        Schema::create('admission_application_documents', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // CASCADE for the same reason as the guardian rows: discarded
            // draft, discarded attachments. The FILES are removed by the
            // purge/discard Action - a cascading delete frees no bytes on the
            // private disk, and pretending otherwise is how orphaned scans of
            // birth certificates accumulate.
            $table->foreignId('admission_application_id')
                ->constrained('admission_applications')
                ->cascadeOnDelete();

            $table->string('document_type', 60);
            $table->string('original_name', 255);
            $table->string('path', 255);
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('size_bytes')->default(0);

            $table->timestamps();

            $table->index('admission_application_id', 'idx_admission_document_application');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_application_documents');
        Schema::dropIfExists('admission_application_guardians');
        Schema::dropIfExists('admission_applications');
    }
};
