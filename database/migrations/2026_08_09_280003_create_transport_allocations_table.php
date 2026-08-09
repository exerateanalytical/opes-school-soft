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
        // docs/plans/phase-10.md §3 row 3 (series renumbered to 2800xx per
        // docs/plans/OVERNIGHT-RUN.md). Keyed on enrollment_id, not
        // student_id (07-students line 39): riding the bus is a fact about a
        // student's year, and history per year must survive rollover.
        Schema::create('transport_allocations', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('enrollment_id')->constrained('enrollments')->restrictOnDelete();
            $table->foreignId('route_id')->constrained('transport_routes')->restrictOnDelete();
            $table->foreignId('stop_id')->constrained('transport_stops')->restrictOnDelete();

            $table->enum('direction', ['both', 'pickup', 'dropoff'])->default('both');

            $table->date('starts_on');
            // NULL while active; set when the allocation ends.
            $table->date('ends_on')->nullable();

            $table->enum('status', ['active', 'ended'])->default('active');

            $table->foreignId('allocated_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();

            // ---------------------------------------------------------------
            // One ACTIVE allocation per enrollment, enforced by the schema
            // rather than trusted to Action discipline - the 00-core 10.1
            // NULL-unique generated-column stand-in for a partial index
            // (same trick as enrollment_segments.open_key and the HANDOVER
            // invoice-idempotency fix). The column carries enrollment_id
            // only while the row is active, so UNIQUE(active_key) permits
            // any number of ended rows and at most one live one.
            // ---------------------------------------------------------------
            $table->unsignedBigInteger('active_key')
                ->nullable()
                ->storedAs("CASE WHEN `status` = 'active' THEN `enrollment_id` END");

            $table->unique('active_key', 'uq_transport_allocations_active');

            $table->index(['route_id', 'status'], 'idx_transport_allocations_route_status');
        });

        // A backwards period would make "who rides today" ambiguous; an
        // ended row without an end date would be unreadable history.
        DB::statement(
            'ALTER TABLE transport_allocations ADD CONSTRAINT chk_transport_allocations_range '
            .'CHECK (ends_on IS NULL OR ends_on >= starts_on)'
        );

        DB::statement(
            'ALTER TABLE transport_allocations ADD CONSTRAINT chk_transport_allocations_ended_dated '
            ."CHECK (status <> 'ended' OR ends_on IS NOT NULL)"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_allocations');
    }
};
