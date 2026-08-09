<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // docs/plans/phase-10.md §3 row 6 (series renumbered to 2800xx per
        // docs/plans/OVERNIGHT-RUN.md). Keyed on enrollment_id, not
        // student_id (07-students line 39): boarding is a fact about a
        // student's year, and history per year must survive rollover.
        Schema::create('hostel_allocations', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('enrollment_id')->constrained('enrollments')->restrictOnDelete();
            $table->foreignId('bed_id')->constrained('hostel_beds')->restrictOnDelete();

            $table->date('starts_on');
            // NULL while active; set when the allocation ends.
            $table->date('ends_on')->nullable();

            $table->enum('status', ['active', 'ended'])->default('active');

            $table->foreignId('allocated_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();

            // ---------------------------------------------------------------
            // TWO invariants, both enforced by the schema rather than
            // trusted to Action discipline - the 00-core 10.1 NULL-unique
            // generated-column stand-in for a partial index (same trick as
            // transport_allocations.active_key and the HANDOVER
            // invoice-idempotency fix). Each column carries its id only
            // while the row is active, so the UNIQUE keys permit any number
            // of ended rows and at most one live one:
            //   1. a student sleeps in at most ONE bed, and
            //   2. a bed holds at most ONE student.
            // A race loses with a duplicate-key error instead of a silent
            // double booking either way round.
            // ---------------------------------------------------------------
            $table->unsignedBigInteger('active_enrollment_key')
                ->nullable()
                ->storedAs("CASE WHEN `status` = 'active' THEN `enrollment_id` END");

            $table->unsignedBigInteger('active_bed_key')
                ->nullable()
                ->storedAs("CASE WHEN `status` = 'active' THEN `bed_id` END");

            $table->unique('active_enrollment_key', 'uq_hostel_allocations_active_enrollment');
            $table->unique('active_bed_key', 'uq_hostel_allocations_active_bed');

            $table->index(['bed_id', 'status'], 'idx_hostel_allocations_bed_status');
        });

        // A backwards period would make "who sleeps here tonight"
        // ambiguous; an ended row without an end date would be unreadable
        // history.
        DB::statement(
            'ALTER TABLE hostel_allocations ADD CONSTRAINT chk_hostel_allocations_range '
            .'CHECK (ends_on IS NULL OR ends_on >= starts_on)'
        );

        DB::statement(
            'ALTER TABLE hostel_allocations ADD CONSTRAINT chk_hostel_allocations_ended_dated '
            ."CHECK (status <> 'ended' OR ends_on IS NOT NULL)"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('hostel_allocations');
    }
};
