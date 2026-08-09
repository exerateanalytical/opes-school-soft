<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // docs/plans/phase-10.md §3 row 7 (series renumbered to 2800xx per
        // docs/plans/OVERNIGHT-RUN.md). A dated welfare walk-through of a
        // hostel (or one room in it). Findings are operational text; any
        // resulting damage charge is the operator-driven fees flow
        // (StudentObligationSource is a tracked debt), never automated here.
        Schema::create('hostel_inspections', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('hostel_id')->constrained('hostels')->restrictOnDelete();

            // NULL = a whole-building inspection; RecordInspection verifies
            // a given room actually belongs to the hostel.
            $table->foreignId('room_id')->nullable()->constrained('hostel_rooms')->restrictOnDelete();

            $table->date('inspected_on');
            $table->foreignId('inspected_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->enum('rating', ['good', 'fair', 'poor', 'critical']);

            $table->text('findings')->nullable();

            // Set when the issues raised are confirmed fixed.
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['hostel_id', 'inspected_on'], 'idx_hostel_inspections_hostel_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hostel_inspections');
    }
};
