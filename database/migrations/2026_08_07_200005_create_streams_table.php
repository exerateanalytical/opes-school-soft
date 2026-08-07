<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('streams', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('school_section_id')
                ->constrained('school_sections')
                ->restrictOnDelete();

            $table->string('code', 20);
            $table->string('name');
            $table->string('name_fr');

            $table->json('subject_basket')->comment(
                'LOAD-BEARING (00-core.md 8): defines the ranking cohort. Students are '
                .'ranked only against classmates sharing this subject basket - ranking '
                .'across different elective sets is arithmetically unfair and a conseil '
                .'de classe will reject it. See 01-assessment.'
            );

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('streams');
    }
};
