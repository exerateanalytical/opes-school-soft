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
        // docs/plans/phase-10.md §3 row 5 (series renumbered to 2800xx per
        // docs/plans/OVERNIGHT-RUN.md). The boarding hierarchy: a hostel is
        // the building ("Heritage Boys Hostel A"), a room is a door within
        // it, a bed is the unit a student actually holds.
        Schema::create('hostels', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // 00-core 4: identifier collation - accent/case sensitive.
            $table->string('code', 30)->collation('utf8mb4_0900_as_cs')->unique('uq_hostels_code');
            $table->string('name', 120);

            // Who may sleep here. 'mixed' exists for annex/family-style
            // blocks; the per-student gender gate lives in AllocateBed.
            $table->enum('gender', ['boys', 'girls', 'mixed'])->default('mixed');

            $table->foreignId('warden_user_id')->nullable()->constrained('users')->restrictOnDelete();

            // Archive flag, not SoftDeletes (00-core 10.5): a closed hostel
            // keeps its allocation history but accepts no new residents.
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });

        Schema::create('hostel_rooms', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('hostel_id')->constrained('hostels')->restrictOnDelete();

            $table->string('name', 60);

            // The physical ceiling on beds in this room - SaveBeds refuses
            // to create more bed rows than this.
            $table->unsignedSmallInteger('capacity');

            $table->timestamps();

            $table->unique(['hostel_id', 'name'], 'uq_hostel_rooms_hostel_name');
        });

        Schema::create('hostel_beds', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('room_id')->constrained('hostel_rooms')->restrictOnDelete();

            $table->string('label', 60);

            // A broken/withdrawn bed is deactivated, never deleted - the
            // allocation history hanging off it must survive.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['room_id', 'label'], 'uq_hostel_beds_room_label');
        });

        // A room that can hold nobody is a data-entry error, not a room.
        DB::statement(
            'ALTER TABLE hostel_rooms ADD CONSTRAINT chk_hostel_rooms_capacity CHECK (capacity >= 1)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('hostel_beds');
        Schema::dropIfExists('hostel_rooms');
        Schema::dropIfExists('hostels');
    }
};
