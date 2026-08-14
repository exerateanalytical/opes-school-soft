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
        // Gap-analysis #1: one table for all four families (club, sport,
        // event, excursion), the excursion carrying its extra structure in
        // nullable columns that a CHECK keeps NULL on every other type.
        Schema::create('activities', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('name', 150);
            $table->enum('type', ['club', 'sport', 'event', 'excursion']);
            $table->string('description', 500)->nullable();
            $table->string('venue', 150)->nullable();

            // NULL = unlimited. EnrolStudent refuses past a set capacity.
            $table->unsignedSmallInteger('capacity')->nullable();

            $table->enum('status', ['active', 'closed'])->default('active');

            // ── Excursion extras (row-15 consent tie-in lives on the
            //    membership; the trip facts live here). ──────────────────
            $table->string('destination', 200)->nullable();
            $table->dateTime('departure_at')->nullable();
            $table->dateTime('return_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->index(['type', 'status'], 'idx_activities_type_status');
        });

        // A chess club with a departure time is a data-entry accident this
        // schema refuses to store; so is a return before departure.
        DB::statement(
            'ALTER TABLE activities ADD CONSTRAINT chk_activities_excursion_only '
            ."CHECK (type = 'excursion' OR (destination IS NULL AND departure_at IS NULL AND return_at IS NULL))"
        );

        DB::statement(
            'ALTER TABLE activities ADD CONSTRAINT chk_activities_return_after_departure '
            .'CHECK (return_at IS NULL OR departure_at IS NULL OR return_at >= departure_at)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
