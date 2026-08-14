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
        // Gap #2: the Unit/Topic hierarchy. A unit is an ordered chapter of
        // the curriculum ("Unit 3 - Electricity"); topics are its ordered
        // lessons, each carrying the intended learning outcome the teacher
        // plans against.
        Schema::create('curriculum_units', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('curriculum_id')->constrained('curricula')->restrictOnDelete();

            $table->string('title', 160);
            $table->string('description', 500)->nullable();

            // Position within the curriculum. UNIQUE(curriculum_id, sequence)
            // is what makes "Unit 3" a well-defined thing (same pattern as
            // transport_stops).
            $table->unsignedSmallInteger('sequence');

            $table->timestamps();

            $table->unique(['curriculum_id', 'sequence'], 'uq_curriculum_units_sequence');
        });

        DB::statement(
            'ALTER TABLE curriculum_units ADD CONSTRAINT chk_curriculum_units_sequence CHECK (sequence >= 1)'
        );

        Schema::create('curriculum_topics', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('curriculum_unit_id')->constrained('curriculum_units')->restrictOnDelete();

            $table->string('title', 160);

            // The intended learning outcome: what the learner should be able
            // to DO after the topic ("solve simple circuits using Ohm's law").
            $table->string('learning_outcome', 500)->nullable();

            $table->unsignedSmallInteger('sequence');

            $table->timestamps();

            $table->unique(['curriculum_unit_id', 'sequence'], 'uq_curriculum_topics_sequence');
        });

        DB::statement(
            'ALTER TABLE curriculum_topics ADD CONSTRAINT chk_curriculum_topics_sequence CHECK (sequence >= 1)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('curriculum_topics');
        Schema::dropIfExists('curriculum_units');
    }
};
