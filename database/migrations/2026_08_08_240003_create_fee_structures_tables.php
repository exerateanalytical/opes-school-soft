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
        // 04-fees.md §2.5.
        Schema::create('fee_structures', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('academic_year_id')
                ->constrained('academic_years')
                ->restrictOnDelete();

            $table->foreignId('school_section_id')
                ->constrained('school_sections')
                ->restrictOnDelete();

            // SENTINELS, deliberately NOT NULL (04-fees §2.5 "Why sentinel
            // rows"). MySQL treats every NULL in a UNIQUE index as distinct,
            // so a nullable class_level_id/stream_id would let two "any
            // level, any stream" structures both insert - and then two
            // structures match one student with no resolution order. 0 means
            // "any". No FK constraint: 0 is not a class_levels/streams row
            // (both tables carry a NOT NULL school_section_id, so a global
            // id-0 row cannot exist); RESTRICT semantics for real ids are
            // enforced at the Action layer - the exact subject_allocations
            // .stream_id precedent.
            $table->unsignedBigInteger('class_level_id')->default(0);
            $table->unsignedBigInteger('stream_id')->default(0);

            $table->enum('enrollment_status_scope', ['any', 'new', 'returning', 'repeating'])->default('any');
            $table->enum('boarding_scope', ['any', 'day', 'boarding'])->default('any');

            $table->string('name', 160);
            $table->enum('status', ['draft', 'active', 'archived'])->default('draft');

            // Bumped on every published change; invoices stamp the version.
            $table->integer('version')->default(1);

            $table->date('effective_from');
            // Exclusive upper bound; NULL = open-ended.
            $table->date('effective_to')->nullable();

            $table->timestamps();

            $table->unique(
                [
                    'academic_year_id', 'school_section_id', 'class_level_id', 'stream_id',
                    'enrollment_status_scope', 'boarding_scope', 'effective_from',
                ],
                'uq_fee_structures_scope',
            );
        });

        DB::statement(
            'ALTER TABLE fee_structures ADD CONSTRAINT chk_fee_structures_effective '
            .'CHECK (effective_to IS NULL OR effective_to > effective_from)'
        );

        Schema::create('fee_structure_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // CASCADE: config child of the structure (04-fees §2.5).
            $table->foreignId('fee_structure_id')
                ->constrained('fee_structures')
                ->cascadeOnDelete();

            $table->foreignId('fee_item_id')
                ->constrained('fee_items')
                ->restrictOnDelete();

            // Whole FCFA (00-core §7): BIGINT, no decimals.
            $table->bigInteger('amount');

            // The term this line is billed in; SENTINEL 0 = annual. The spec
            // writes "NULL = annual", but term_id sits inside
            // UNIQUE(fee_structure_id, fee_item_id, term_id) and MySQL's
            // duplicate-NULL behaviour would let two annual lines of the
            // same item both insert - the same trap §2.5 flags for
            // stream_id, resolved the same way. No FK: 0 is not an
            // assessment_periods row; real ids are validated in the Action.
            $table->unsignedBigInteger('term_id')->default(0);

            // Defaults for §6 revenue recognition.
            $table->date('service_period_start')->nullable();
            $table->date('service_period_end')->nullable();

            $table->boolean('is_optional')->default(false);
            $table->smallInteger('display_order')->default(0);

            $table->timestamps();

            $table->unique(['fee_structure_id', 'fee_item_id', 'term_id'], 'uq_fee_structure_lines_item_term');
        });

        DB::statement(
            'ALTER TABLE fee_structure_lines ADD CONSTRAINT chk_fee_structure_lines_amount CHECK (amount >= 0)'
        );

        // §3.2 shape, applied to the defaults that feed it: both dates or
        // neither, and the period must not run backwards.
        DB::statement(
            'ALTER TABLE fee_structure_lines ADD CONSTRAINT chk_fee_structure_lines_service_period '
            .'CHECK ( ((service_period_start IS NULL) = (service_period_end IS NULL)) '
            .'AND (service_period_end IS NULL OR service_period_end >= service_period_start) )'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_structure_lines');
        Schema::dropIfExists('fee_structures');
    }
};
