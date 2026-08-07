<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // docs/specs/07-students.md 3.3. Append-only. v1 asserted "history is
        // never deleted" with no mechanism behind it; this table is the
        // mechanism, and it is what makes a lifecycle actually reconstructable.
        Schema::create('student_status_transitions', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // RESTRICT throughout - these rows outlive interest in them.
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();

            // from_status is nullable for the very first transition into
            // prospective, where there is no prior state to name.
            $table->enum('from_status', [
                'prospective', 'active', 'inactive', 'graduated',
                'transferred_out', 'withdrawn', 'deceased',
            ])->nullable();

            $table->enum('to_status', [
                'prospective', 'active', 'inactive', 'graduated',
                'transferred_out', 'withdrawn', 'deceased',
            ]);

            $table->date('effective_on');
            $table->string('reason_code', 60)->nullable();
            $table->string('reason_text', 255)->nullable();

            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();

            // Denormalised for the same reason AuditLog does it (00-core 10.5):
            // the row must stay legible after the account is renamed.
            $table->string('actor_name_at_time', 120);

            // created_at only. There is no updated_at because there is no
            // update - an append-only table with a mutation timestamp invites
            // exactly the mutation it forbids.
            $table->timestamp('created_at')->nullable();

            $table->index(['student_id', 'effective_on'], 'student_status_transitions_student_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_status_transitions');
    }
};
