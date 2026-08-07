<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_levels', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // RESTRICT: a section with class levels cannot be deleted out from
            // under them - class levels are the academic ladder students climb.
            $table->foreignId('school_section_id')
                ->constrained('school_sections')
                ->restrictOnDelete();

            $table->string('code', 20);
            $table->string('name');
            $table->string('name_fr');
            $table->unsignedSmallInteger('order_index')->default(0);
            $table->boolean('is_exam_class')->default(false);
            $table->timestamps();

            // Codes repeat ACROSS sections (Form 1 anglophone, 6e francophone
            // may both use 'F1'-style shorthand) but never within one.
            $table->unique(['school_section_id', 'code'], 'uq_class_level_section_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_levels');
    }
};
