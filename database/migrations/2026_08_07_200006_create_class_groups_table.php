<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_groups', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // Deleting a class level or academic year that still has class
            // groups would orphan enrollment history - RESTRICT, never cascade.
            $table->foreignId('class_level_id')->constrained('class_levels')->restrictOnDelete();
            $table->foreignId('stream_id')->nullable()->constrained('streams')->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();

            $table->string('name', 100);

            // The staff table belongs to a later phase (HR). Plain nullable
            // column, no FK - the constraint is added when staff lands.
            $table->unsignedBigInteger('class_teacher_staff_id')->nullable();

            // rooms is created by migration 2026_08_07_200009, which sorts
            // AFTER this file, so the FK cannot be declared here. Plain
            // nullable reference column; the constraint is added when a
            // dependency-ordering-safe consolidation pass runs. Acceptable
            // for Phase 1 reference data.
            $table->unsignedBigInteger('room_id')->nullable();

            $table->unsignedSmallInteger('capacity');
            $table->string('status', 20)->default('active');
            $table->timestamps();

            // One "Form 1 A" per level per year - the same name is expected
            // to recur across years.
            $table->unique(
                ['academic_year_id', 'class_level_id', 'name'],
                'class_groups_year_level_name_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_groups');
    }
};
