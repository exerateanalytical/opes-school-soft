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
        // 04-fees.md §2.6.
        Schema::create('installment_plans', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('academic_year_id')
                ->constrained('academic_years')
                ->restrictOnDelete();

            $table->string('name', 160);

            // SENTINEL 0 = global plan (any structure). The spec writes
            // "FK NULL", but the one-default-per-(academic_year, structure)
            // UNIQUE below would be defeated by MySQL's duplicate-NULL
            // behaviour for the global case - same trap, same fix as
            // fee_structures.stream_id. No FK: 0 is not a fee_structures
            // row; real ids are validated in SaveInstallmentPlan.
            $table->unsignedBigInteger('fee_structure_id')->default(0);

            $table->enum('basis', ['percentage', 'fixed']);
            $table->boolean('is_default')->default(false);

            $table->timestamps();
        });

        // One default per (academic_year, structure): generated-column
        // pattern (00-core §10.1). Non-default rows generate NULL, which the
        // UNIQUE index ignores; default rows collide per scope.
        DB::statement(
            'ALTER TABLE installment_plans ADD COLUMN default_scope_key VARCHAR(50) '
            ."GENERATED ALWAYS AS (CASE WHEN is_default THEN CONCAT(academic_year_id, '-', fee_structure_id) ELSE NULL END) STORED"
        );
        DB::statement(
            'ALTER TABLE installment_plans ADD UNIQUE INDEX uq_installment_plans_default (default_scope_key)'
        );

        Schema::create('installment_plan_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // CASCADE: config child.
            $table->foreignId('installment_plan_id')
                ->constrained('installment_plans')
                ->cascadeOnDelete();

            // 1-based.
            $table->unsignedTinyInteger('sequence_no');

            $table->string('label', 120);
            $table->string('label_fr', 120);

            // Integer basis points (00-core §7.2); Σ = 1 000 000 for a
            // percentage plan, enforced in SaveInstallmentPlan (§2.6).
            $table->bigInteger('percentage_bp')->nullable();
            $table->bigInteger('fixed_amount')->nullable();

            // Either an absolute date or an offset from term start - exactly
            // one, enforced in the Action (a CHECK cannot know the basis of
            // the parent plan).
            $table->date('due_date')->nullable();
            $table->integer('due_offset_days')->nullable();

            $table->timestamps();

            $table->unique(['installment_plan_id', 'sequence_no'], 'uq_installment_plan_lines_seq');
        });

        DB::statement(
            'ALTER TABLE installment_plan_lines ADD CONSTRAINT chk_installment_plan_lines_amounts '
            .'CHECK ( (percentage_bp IS NULL OR percentage_bp > 0) AND (fixed_amount IS NULL OR fixed_amount >= 0) )'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_plan_lines');
        Schema::dropIfExists('installment_plans');
    }
};
