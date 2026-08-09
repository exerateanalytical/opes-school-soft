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
        // docs/plans/phase-10.md §3 row 1 (series renumbered to 2800xx per
        // docs/plans/OVERNIGHT-RUN.md). A route is the named journey a bus
        // makes ("Route A - Downtown"); stops are its ordered waypoints.
        Schema::create('transport_routes', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // 00-core 4: identifier collation - accent/case sensitive.
            $table->string('code', 30)->collation('utf8mb4_0900_as_cs')->unique('uq_transport_routes_code');
            $table->string('name', 120);
            $table->string('description', 255)->nullable();

            // Archive flag, not SoftDeletes (00-core 10.5): an inactive route
            // keeps its history but accepts no new allocations.
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });

        Schema::create('transport_stops', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('route_id')->constrained('transport_routes')->restrictOnDelete();

            $table->string('name', 120);

            // Position along the route. UNIQUE(route_id, sequence) is what
            // makes "the third stop on Route A" a well-defined thing the
            // roster can sort by.
            $table->unsignedSmallInteger('sequence');

            $table->time('pickup_time')->nullable();
            $table->time('dropoff_time')->nullable();

            $table->timestamps();

            $table->unique(['route_id', 'sequence'], 'uq_transport_stops_route_sequence');
        });

        // A zero or negative sequence would break the roster ordering
        // contract without failing anywhere visible.
        DB::statement(
            'ALTER TABLE transport_stops ADD CONSTRAINT chk_transport_stops_sequence CHECK (sequence >= 1)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_stops');
        Schema::dropIfExists('transport_routes');
    }
};
