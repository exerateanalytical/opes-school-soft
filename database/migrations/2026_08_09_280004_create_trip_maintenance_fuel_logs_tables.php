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
        // docs/plans/phase-10.md §3 row 4 (series renumbered to 2800xx per
        // docs/plans/OVERNIGHT-RUN.md). Operational records ONLY: none of
        // these tables posts to the ledger. Fuel and maintenance COSTS reach
        // the books through Phase 5 supplier invoices; a second posting path
        // here would be a review-blocking defect (phase-10 plan §1).
        Schema::create('vehicle_trip_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('vehicle_id')->constrained('vehicles')->restrictOnDelete();
            // Nullable: excursions and charters happen off the named routes.
            $table->foreignId('route_id')->nullable()->constrained('transport_routes')->restrictOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('vehicle_drivers')->restrictOnDelete();

            $table->date('date');

            $table->unsignedInteger('odometer_start');
            $table->unsignedInteger('odometer_end');

            $table->string('notes', 255)->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->index(['vehicle_id', 'date'], 'idx_vehicle_trip_logs_vehicle_date');
        });

        // The odometer only turns one way.
        DB::statement(
            'ALTER TABLE vehicle_trip_logs ADD CONSTRAINT chk_vehicle_trip_logs_odometer '
            .'CHECK (odometer_end >= odometer_start)'
        );

        Schema::create('vehicle_maintenance_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('vehicle_id')->constrained('vehicles')->restrictOnDelete();

            $table->date('date');

            $table->enum('type', ['service', 'repair', 'inspection', 'other']);
            $table->string('description', 255);

            // XAF integer, the house money convention. Informational: the
            // payable posts through the supplier invoice, never from here.
            $table->bigInteger('cost_amount')->nullable();

            // Reference to the Phase 5 supplier who did the work, when known.
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->index(['vehicle_id', 'date'], 'idx_vehicle_maintenance_logs_vehicle_date');
        });

        DB::statement(
            'ALTER TABLE vehicle_maintenance_logs ADD CONSTRAINT chk_vehicle_maintenance_logs_cost '
            .'CHECK (cost_amount IS NULL OR cost_amount >= 0)'
        );

        Schema::create('vehicle_fuel_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('vehicle_id')->constrained('vehicles')->restrictOnDelete();

            $table->date('date');

            $table->decimal('litres', 8, 2);

            // XAF integer, informational only - see the header comment.
            $table->bigInteger('cost_amount');

            $table->unsignedInteger('odometer')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->index(['vehicle_id', 'date'], 'idx_vehicle_fuel_logs_vehicle_date');
        });

        DB::statement(
            'ALTER TABLE vehicle_fuel_logs ADD CONSTRAINT chk_vehicle_fuel_logs_positive '
            .'CHECK (litres > 0 AND cost_amount >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_fuel_logs');
        Schema::dropIfExists('vehicle_maintenance_logs');
        Schema::dropIfExists('vehicle_trip_logs');
    }
};
