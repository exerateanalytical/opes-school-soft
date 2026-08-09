<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/06-assets-stores.md §10.3 - membership_classes (borrowing
 * limits as data) and library_members.
 *
 * Keying decision (§10.3): student memberships key on ENROLLMENT (07-students
 * C3 - the membership is scoped to a year so it does not stay active
 * forever), but the fine receivable and the borrowing history key on
 * STUDENT, because a debt survives the year. Both columns are populated and
 * the invariant `student_id = enrollment.student_id` is Action-enforced
 * (EnrollLibraryMember derives, never accepts, the student id).
 *
 * The identity CHECK: exactly one of student_id/staff_member_id unless the
 * member is external, in which case both are NULL and the external
 * name/contact are required.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_classes', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('code', 30)->collation('utf8mb4_0900_as_cs')
                ->unique('uq_membership_classes_code');
            $table->string('name', 120);
            $table->string('name_fr', 120);

            $table->unsignedSmallInteger('max_concurrent_issues')->default(2);
            $table->unsignedSmallInteger('loan_days')->default(14);
            $table->unsignedSmallInteger('max_renewals')->default(1);
            $table->unsignedSmallInteger('renewal_days')->default(7);

            // §10.5 arithmetic inputs. FCFA / calendar day.
            $table->bigInteger('fine_per_day')->default(0);
            $table->unsignedSmallInteger('fine_grace_days')->default(0);
            $table->enum('fine_cap_policy', ['replacement_cost', 'uncapped'])
                ->default('replacement_cost');

            // A member owing more than this (unwaived, unsettled fines) is
            // blocked from issue and renewal. 0 = any unpaid fine blocks.
            $table->bigInteger('blocking_fine_threshold')->default(0);

            $table->unsignedSmallInteger('max_reservations')->default(1);
            $table->boolean('can_borrow_reference')->default(false);

            $table->boolean('is_archived')->default(false);

            $table->timestamps();
        });

        DB::statement(
            'ALTER TABLE membership_classes ADD CONSTRAINT chk_membership_classes_fine CHECK '
            .'(fine_per_day >= 0 AND blocking_fine_threshold >= 0)'
        );

        Schema::create('library_members', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // Printed on the Library Card.
            $table->string('member_no', 20)->collation('utf8mb4_0900_as_cs')
                ->unique('uq_library_members_no');

            $table->enum('member_type', ['student', 'staff', 'external']);

            $table->foreignId('student_id')->nullable()
                ->constrained('students')->restrictOnDelete();
            $table->foreignId('staff_member_id')->nullable()
                ->constrained('staff_members')->restrictOnDelete();
            $table->foreignId('enrollment_id')->nullable()
                ->constrained('enrollments')->restrictOnDelete();

            $table->foreignId('academic_year_id')
                ->constrained('academic_years')->restrictOnDelete();

            $table->foreignId('membership_class_id')
                ->constrained('membership_classes')->restrictOnDelete();

            $table->enum('status', ['active', 'suspended', 'expired', 'closed'])
                ->default('active');

            $table->date('joined_on');
            $table->date('expires_on')->nullable();
            $table->string('suspended_reason', 255)->nullable();

            $table->string('external_name', 160)->nullable();
            $table->string('external_contact', 160)->nullable();

            $table->timestamp('card_issued_at')->nullable();
            $table->unsignedSmallInteger('card_printed_count')->default(0);

            $table->foreignId('created_by')
                ->constrained('users')->restrictOnDelete();

            $table->string('idempotency_key', 100)->nullable()
                ->unique('uq_library_members_idem');

            $table->timestamps();

            // One membership per enrollment (the enrollment IS the year
            // scope); one per staff member per year.
            $table->unique('enrollment_id', 'uq_library_members_enrollment');
            $table->unique(['staff_member_id', 'academic_year_id'], 'uq_library_members_staff_year');

            $table->index(['member_type', 'status'], 'ix_library_members_type_status');
        });

        // §10.3 identity CHECK - the shape of each member_type.
        DB::statement(
            'ALTER TABLE library_members ADD CONSTRAINT chk_library_members_identity CHECK ( '
            ."(member_type = 'student' AND student_id IS NOT NULL AND enrollment_id IS NOT NULL AND staff_member_id IS NULL) "
            ."OR (member_type = 'staff' AND staff_member_id IS NOT NULL AND student_id IS NULL AND enrollment_id IS NULL) "
            ."OR (member_type = 'external' AND student_id IS NULL AND staff_member_id IS NULL AND enrollment_id IS NULL "
            .'AND external_name IS NOT NULL AND external_contact IS NOT NULL) )'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('library_members');
        Schema::dropIfExists('membership_classes');
    }
};
