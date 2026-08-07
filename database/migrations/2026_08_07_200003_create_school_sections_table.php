<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_sections', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // docs/specs/00-core.md 8: a section is one (level, track, sub-system)
            // combination - e.g. anglophone general secondary_1 - and the triple
            // is unique so the same combination cannot be configured twice.
            $table->enum('education_level', [
                'nursery', 'primary', 'secondary_1', 'secondary_2', 'technical', 'teacher_training',
            ]);
            $table->enum('track', ['general', 'technical', 'normal']);
            $table->enum('sub_system', ['anglophone', 'francophone']);

            $table->string('name');
            $table->string('name_fr');
            $table->string('matricule_format', 100);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['education_level', 'track', 'sub_system'],
                'uq_section_level_track_system',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_sections');
    }
};
