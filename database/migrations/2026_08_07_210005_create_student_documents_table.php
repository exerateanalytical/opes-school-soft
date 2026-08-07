<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // docs/specs/07-students.md 8.1, keyed on student_id per the 3.4
        // matrix: a birth certificate is not an annual document.
        Schema::create('student_documents', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();

            // DocumentType is managed reference data owned outside this
            // workstream and its table does not exist yet. Plain nullable
            // reference column now; the RESTRICT foreign key is added with
            // that table. Same pattern class_groups.room_id already uses.
            $table->unsignedBigInteger('document_type_id')->nullable();

            $table->string('title', 160);

            // Private disk only, served through a policy-checked controller,
            // never storage:link (8.1 / 08-operations).
            $table->string('file_path', 255);

            // SHA-256, UNIQUE per student: the same scan uploaded twice is
            // caught rather than silently duplicated (8.1).
            $table->char('file_hash', 64);

            $table->string('mime', 120);
            $table->unsignedBigInteger('size_bytes');

            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();

            // A guardian upload lands as `unverified` and is never
            // auto-verified (7.5 row 24).
            $table->enum('verification_status', ['unverified', 'verified', 'rejected'])
                ->default('unverified');

            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();

            $table->string('notes', 500)->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->restrictOnDelete();

            // Deletion sets this and moves the file to a quarantine prefix
            // (8.1); nothing hard-deletes a document row.
            $table->boolean('is_archived')->default(false);

            $table->timestamps();

            $table->unique(['student_id', 'file_hash'], 'student_documents_student_hash_unique');
            $table->index(['student_id', 'verification_status'], 'student_documents_student_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_documents');
    }
};
