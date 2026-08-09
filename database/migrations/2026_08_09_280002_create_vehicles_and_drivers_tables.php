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
        // docs/plans/phase-10.md §3 row 2 (series renumbered to 2800xx per
        // docs/plans/OVERNIGHT-RUN.md).
        Schema::create('vehicles', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // The number plate. 00-core 4: identifier collation.
            $table->string('registration_no', 20)->collation('utf8mb4_0900_as_cs')->unique('uq_vehicles_registration_no');

            $table->string('make', 80)->nullable();
            $table->string('model', 80)->nullable();

            // Seats. The allocation screens compare active riders against it.
            $table->unsignedSmallInteger('capacity');

            // Link to the Phase 9 asset register. Deliberately a bare
            // nullable bigint with NO foreign key constraint (phase-10 plan
            // §1): the plan was cut while Phase 9 was unbuilt, and the FK is
            // added by a follow-up migration once both phases are merged, so
            // neither phase's migrations order-depend on the other's.
            $table->unsignedBigInteger('asset_id')->nullable();

            $table->enum('status', ['operational', 'under_maintenance', 'out_of_service'])
                ->default('operational');

            // Compliance dates the dashboard's "maintenance due" rail reads.
            $table->date('insurance_expires_on')->nullable();
            $table->date('inspection_expires_on')->nullable();

            $table->timestamps();

            $table->index('status', 'idx_vehicles_status');
        });

        DB::statement(
            'ALTER TABLE vehicles ADD CONSTRAINT chk_vehicles_capacity CHECK (capacity >= 1)'
        );

        Schema::create('vehicle_drivers', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('vehicle_id')->constrained('vehicles')->restrictOnDelete();

            $table->string('name', 120);

            // Encrypted at the model ('encrypted' cast, the
            // StudentMedicalRecord pattern) - a driving licence number is
            // personal identity data. TEXT because ciphertext length is not
            // ours to bound tightly.
            $table->text('licence_no')->nullable();

            $table->string('phone', 24)->nullable();

            // Optional link to a staff login. Nullable: most drivers never
            // sign in to anything.
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();

            // Employment period on this vehicle. NULL active_to = current.
            $table->date('active_from');
            $table->date('active_to')->nullable();

            $table->timestamps();

            $table->index(['vehicle_id', 'active_to'], 'idx_vehicle_drivers_vehicle_active');
        });

        DB::statement(
            'ALTER TABLE vehicle_drivers ADD CONSTRAINT chk_vehicle_drivers_period '
            .'CHECK (active_to IS NULL OR active_to >= active_from)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_drivers');
        Schema::dropIfExists('vehicles');
    }
};
