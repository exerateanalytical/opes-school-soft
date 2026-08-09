<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // docs/plans/phase-10.md §3 row 12 (series renumbered to 2800xx per
        // docs/plans/OVERNIGHT-RUN.md). enrollment × policy (design §14):
        // keyed on enrollment_id, not student_id (07-students line 39) -
        // cover is a fact about a student's YEAR, matching the policy's
        // academic_year_id, and history per year survives rollover.
        Schema::create('student_insurances', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('enrollment_id')
                ->constrained('enrollments')
                ->restrictOnDelete();

            $table->foreignId('policy_id')
                ->constrained('insurance_policies')
                ->restrictOnDelete();

            $table->date('enrolled_on');

            // The insurer's per-head certificate reference, when issued.
            $table->string('certificate_no', 60)->nullable();

            $table->enum('status', ['active', 'lapsed', 'cancelled'])
                ->default('active');

            $table->timestamps();

            // The bulk-enrolment idempotency key (phase-10 plan §4 W5):
            // running EnrollStudentsInPolicy twice over the same cohort
            // inserts nothing new, and a race loses with a duplicate-key
            // error instead of double cover.
            $table->unique(['enrollment_id', 'policy_id'], 'uq_student_insurances_enrollment_policy');

            $table->index(['policy_id', 'status'], 'idx_student_insurances_policy_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_insurances');
    }
};
