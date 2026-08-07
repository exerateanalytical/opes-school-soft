<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // Case-sensitive collation, matching AssessmentFramework.code
            // (01-assessment 3.1): 'MATH' and 'math' are different codes.
            $table->string('code', 32)->collation('utf8mb4_0900_as_cs')->unique();
            $table->string('name', 160);
            $table->string('name_fr', 160)->nullable();

            // Nullable: a subject need not belong to a department (small
            // schools have none). RESTRICT: a department in use by subjects
            // is archived, never deleted (00-core 10.5).
            $table->foreignId('department_id')
                ->nullable()
                ->constrained('departments')
                ->restrictOnDelete();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subjects');
    }
};
