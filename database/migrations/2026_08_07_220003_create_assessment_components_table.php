<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 01-assessment 5.3. A component (CA, EXAM, TP, ORAL) carries its OWN
     * maximum - that is what pipeline stage 2 divides by, and getting it from
     * the framework instead is precisely the 2.1 counterexample: a CA of 24/30
     * and an exam of 60/100 must compose to 13.20/20, not to nonsense.
     */
    public function up(): void
    {
        Schema::create('assessment_components', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('framework_id')
                ->constrained('assessment_frameworks')
                ->restrictOnDelete();

            // Identifier collation per 00-core 4.
            $table->string('code', 32)->collation('utf8mb4_0900_as_cs');
            $table->string('name', 160);
            $table->string('name_fr', 160);

            // The component's own maximum - see the class docblock.
            $table->decimal('max_score', 6, 3);

            // Column order on the entry grid and on the printed card.
            $table->smallInteger('order_index')->default(0);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['framework_id', 'code'], 'uq_assessment_components_framework_code');
        });

        DB::statement(
            'ALTER TABLE assessment_components '
            .'ADD CONSTRAINT chk_assessment_components_max_score CHECK (max_score > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_components');
    }
};
