<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gap #2: the competency model, deliberately SIMPLE - a flat list per
        // curriculum (code + descriptor), linked to topics via a pivot. No
        // hierarchy, no weighting, no assessment linkage yet: those belong to
        // the assessment side once curriculum-linked assessment lands.
        Schema::create('competencies', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('curriculum_id')->constrained('curricula')->restrictOnDelete();

            // 00-core 4: identifier collation - accent/case sensitive.
            // Unique WITHIN the curriculum, not globally: each version of a
            // curriculum carries its own cloned competency rows, so 'COMP-1'
            // legitimately recurs across versions.
            $table->string('code', 32)->collation('utf8mb4_0900_as_cs');
            $table->string('descriptor', 255);

            $table->timestamps();

            $table->unique(['curriculum_id', 'code'], 'uq_competencies_curriculum_code');
        });

        // The topic <-> competency link. RESTRICT both ways: a link row is
        // curriculum content and disappears only with its curriculum version.
        Schema::create('competency_curriculum_topic', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('competency_id')->constrained('competencies')->restrictOnDelete();
            $table->foreignId('curriculum_topic_id')->constrained('curriculum_topics')->restrictOnDelete();

            $table->timestamps();

            $table->unique(['competency_id', 'curriculum_topic_id'], 'uq_competency_topic_link');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competency_curriculum_topic');
        Schema::dropIfExists('competencies');
    }
};
