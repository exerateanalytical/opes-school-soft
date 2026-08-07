<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // docs/specs/07-students.md 8.3. Distinct from the global AuditLog
        // (00-core 14) on purpose: the audit log answers "who changed this
        // row" and is permissioned accordingly; this answers "what happened to
        // this child" and is readable by any staff member who can see the
        // profile. Append-only, and carries no PII beyond what the event
        // needs.
        Schema::create('student_activity_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();

            // enrollments is created by a later migration in this phase, so the
            // FK cannot be declared here. Plain nullable reference column; the
            // constraint lands with the enrollment schema. Nullable regardless:
            // `admitted` and `matricule_finalised` happen outside any
            // enrollment.
            $table->unsignedBigInteger('enrollment_id')->nullable();

            // A closed set. 8.3 is explicit that adding a value requires a
            // migration - which is the point: an open VARCHAR would collect
            // typo'd near-duplicates and the Activity Log tab would stop being
            // filterable.
            $table->enum('event', [
                'admitted', 'enrolled', 'class_transferred', 'stream_changed',
                'suspended', 'reinstated', 'withdrawn', 'transferred_out',
                'graduated', 'promoted', 'repeated', 'marks_published',
                'report_card_printed', 'invoice_issued', 'payment_received',
                'discipline_case_opened', 'sanction_applied', 'document_uploaded',
                'document_verified', 'guardian_linked', 'guardian_unlinked',
                'medical_record_added', 'attendance_flagged', 'matricule_finalised',
            ]);

            $table->string('summary', 255);

            // Polymorphic pointer at whatever the event was about (an invoice,
            // a report card, a discipline case). Not a Laravel morphTo
            // relation with a constraint - the targets live in modules this one
            // may not import (00-core 6.2).
            $table->string('related_type', 160)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();

            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_name_at_time', 120);

            $table->timestamp('occurred_at');

            // 8.3 / 13. The viewer is paginated on this index and is never
            // loaded unbounded (00-core 6.2 rule 8).
            $table->index(['student_id', 'occurred_at'], 'student_activity_logs_student_index');
            $table->index(['related_type', 'related_id'], 'student_activity_logs_related_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_activity_logs');
    }
};
