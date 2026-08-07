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
        Schema::create('subject_allocations', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // Year-scoped (01-assessment 5.1): an allocation belongs to ONE
            // academic year, so editing next year's coefficient can never
            // rewrite a historical report card.
            $table->foreignId('academic_year_id')
                ->constrained('academic_years')
                ->restrictOnDelete();

            $table->foreignId('class_level_id')
                ->constrained('class_levels')
                ->restrictOnDelete();

            // SENTINEL, deliberately NOT NULL (01-assessment 5.1). 0 means
            // "no stream / whole level". MySQL permits unlimited duplicate
            // NULLs in a UNIQUE index, so a nullable stream_id would defeat
            // the UNIQUE(academic_year_id, class_level_id, stream_id,
            // subject_id) constraint entirely - two "no stream" allocations
            // of the same subject would both insert. The sentinel makes the
            // no-stream case collide like any other. No FK constraint: 0 is
            // not a streams row, and RESTRICT semantics for real streams are
            // enforced at the Action layer.
            $table->unsignedBigInteger('stream_id')->default(0);

            $table->foreignId('subject_id')
                ->constrained('subjects')
                ->restrictOnDelete();

            $table->decimal('coefficient', 5, 2);

            // No FK yet: subject groups (Family C grouped bulletins) arrive
            // with the assessment phase; the constraint is wired there.
            $table->unsignedBigInteger('subject_group_id')->nullable();

            // Array of component_id - lets an exam-only subject be declared
            // without a phantom CA (01-assessment 5.1, 6.4).
            $table->json('required_components');

            // Precedence chain 01-assessment 6.3: override > component max
            // > framework max. Read by pipeline stage 2.
            $table->decimal('max_score_override', 6, 3)->nullable();

            $table->boolean('is_optional')->default(false);
            $table->boolean('counts_toward_average')->default(true);

            // Nullable unsigned BIGINTs, deliberately WITHOUT an FK
            // constraint: assessment_periods belongs to the assessment
            // phase; the FK is wired when that table lands.
            $table->unsignedBigInteger('effective_from_period_id')->nullable();
            $table->unsignedBigInteger('effective_to_period_id')->nullable();

            $table->boolean('is_active')->default(true);

            // Optimistic lock, 00-core 10.6.
            $table->integer('version')->default(1);

            $table->timestamps();

            // The constraint v1 lacked (01-assessment 5.1).
            $table->unique(
                ['academic_year_id', 'class_level_id', 'stream_id', 'subject_id'],
                'subject_allocations_scope_unique',
            );
        });

        // Blueprint has no check() helper; the spec demands the constraint
        // live in the database, not only in the Action.
        DB::statement(
            'ALTER TABLE subject_allocations '
            .'ADD CONSTRAINT chk_subject_allocations_coefficient CHECK (coefficient >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_allocations');
    }
};
