<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 01-assessment 3.1. The per-SchoolSection, per-AcademicYear declaration of
     * how assessment works. Year-scoped on purpose: a rule change in September
     * must never rewrite a bulletin printed in June.
     */
    public function up(): void
    {
        Schema::create('assessment_frameworks', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // 00-core 10.5: every child of a framework is RESTRICT, and a
            // framework is archived (is_active = 0), never deleted.
            $table->foreignId('school_section_id')
                ->constrained('school_sections')
                ->restrictOnDelete();

            $table->foreignId('academic_year_id')
                ->constrained('academic_years')
                ->restrictOnDelete();

            // Identifier collation per 00-core 4: codes are compared
            // case- and accent-sensitively, e.g. MINESEC_FR_SEC1.
            $table->string('code', 32)->collation('utf8mb4_0900_as_cs');
            $table->string('name', 160);
            $table->string('name_fr', 160);

            // 01-assessment 3.2. Family F (maternelle) is mandatory scope, not
            // an extra - hence its presence here from day one.
            $table->enum('family', ['A', 'B', 'C', 'D', 'E', 'F']);

            // `competency` implies no marks, no coefficients and no rank; the
            // Action enforces that combination, the enum only records it.
            $table->enum('assessment_mode', ['numeric', 'competency', 'hybrid'])->default('numeric');

            // The framework's canonical scale. 20.000 for MINESEC. Family B's
            // basis is 00-core blocking gate 5 and is deliberately NOT seeded.
            $table->decimal('max_score', 6, 3);

            // 01-assessment 10.3: THE single source of "pass". No pass
            // threshold literal may exist anywhere else (T23).
            $table->decimal('pass_score', 6, 3);

            $table->tinyInteger('score_precision')->unsigned()->default(2);

            $table->boolean('uses_coefficients')->default(true);
            $table->boolean('uses_rank')->default(true);

            $table->enum('rank_scope', ['class_group', 'class_level', 'stream'])->default('class_group');
            $table->enum('rank_cohort_rule', ['identical_basket', 'same_stream', 'all'])->default('same_stream');

            $table->enum('annual_composition', [
                'mean_of_leaf_periods',
                'weighted_children',
                'mean_of_terms',
            ])->default('mean_of_leaf_periods');

            $table->boolean('requires_conseil')->default(false);
            $table->boolean('requires_hod_validation')->default(true);

            // 01-assessment 14: must be true for the MINESEC families. The
            // Action refuses to create an A/B/C framework without it.
            $table->boolean('requires_per_lesson_attendance')->default(false);

            // 01-assessment 6.4. `redistribute` is the default and, per T6,
            // must not turn a plain missing exam into a top grade - that is a
            // pipeline obligation, recorded here as configuration only.
            $table->enum('missing_component_policy', ['redistribute', 'zero', 'block_publication'])
                ->default('redistribute');

            // 01-assessment 10.5: below this many assessed periods a student is
            // NC (non classe), not badly ranked.
            $table->tinyInteger('min_periods_assessed')->unsigned()->default(1);

            // Deliberately a plain nullable column WITHOUT a foreign key: the
            // gpa_scales table (01-assessment 11) is not part of this chunk of
            // work, and a constraint cannot reference a table that does not
            // exist. The FK is wired by whoever lands GpaScale - the same
            // pattern Phase 1 used for assessment_periods.framework_id.
            $table->unsignedBigInteger('gpa_scale_id')->nullable();

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(
                ['school_section_id', 'academic_year_id', 'code'],
                'uq_assessment_frameworks_section_year_code'
            );
        });

        // 00-core 10.1 "one active X" pattern. MySQL 8 has no partial indexes,
        // so the default framework is made unique through a STORED generated
        // column that is NULL for every non-default row - and MySQL permits
        // unlimited duplicate NULLs in a UNIQUE index, which is exactly the
        // behaviour wanted here.
        Schema::table('assessment_frameworks', function (Blueprint $table): void {
            $table->unsignedBigInteger('is_default_key')
                ->nullable()
                ->storedAs('(CASE WHEN `is_default` = 1 THEN `academic_year_id` END)');

            $table->unique(
                ['school_section_id', 'is_default_key'],
                'uq_assessment_frameworks_default'
            );
        });

        // Blueprint has no check() helper and 01-assessment 3.1 puts these in
        // the database, not only in the Action.
        DB::statement(
            'ALTER TABLE assessment_frameworks '
            .'ADD CONSTRAINT chk_assessment_frameworks_max_score CHECK (max_score > 0)'
        );

        DB::statement(
            'ALTER TABLE assessment_frameworks '
            .'ADD CONSTRAINT chk_assessment_frameworks_pass_score '
            .'CHECK (pass_score > 0 AND pass_score <= max_score)'
        );

        DB::statement(
            'ALTER TABLE assessment_frameworks '
            .'ADD CONSTRAINT chk_assessment_frameworks_precision CHECK (score_precision <= 3)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_frameworks');
    }
};
