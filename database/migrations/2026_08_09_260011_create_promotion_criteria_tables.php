<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/07-students.md §10.4 — the promotion rulebook.
 *
 * `promotion_criteria_sets` is versioned and IMMUTABLE ONCE REFERENCED by an
 * evaluated run (the 00-core versioning pattern): the printed promotion list
 * explains itself by naming the set and version it was judged against, so
 * editing a referenced set would rewrite the explanation of a decision that
 * has already been signed off. The Action enforces immutability; the schema
 * records the version.
 *
 * `promotion_criteria` rows each yield pass / fail / indeterminate and
 * `is_blocking` decides — Σ of criteria is NOT a weighted score by default
 * (§10.4), because a school cannot explain a weighted score to a parent.
 * `weight` is stored for the configurable weighted mode, default 0 = unused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_criteria_sets', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('academic_year_id')
                ->constrained('academic_years')
                ->restrictOnDelete();

            $table->foreignId('school_section_id')
                ->constrained('school_sections')
                ->restrictOnDelete();

            // NULL = the set applies to every level of the section; a
            // level-specific set narrows it (§10.4).
            $table->foreignId('class_level_id')
                ->nullable()
                ->constrained('class_levels')
                ->restrictOnDelete();

            $table->string('name', 160);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            $table->index(
                ['academic_year_id', 'school_section_id', 'is_active'],
                'ix_promotion_criteria_sets_scope',
            );
        });

        Schema::create('promotion_criteria', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // CASCADE: a criterion has no life outside its set, and a set can
            // only be deleted while unreferenced (Action-guarded), so the
            // cascade can never orphan an evaluated run's explanation.
            $table->foreignId('criteria_set_id')
                ->constrained('promotion_criteria_sets')
                ->cascadeOnDelete();

            // §10.4's seven sources, verbatim.
            $table->enum('type', [
                'annual_average',
                'subject_minimum',
                'attendance_rate',
                'unjustified_absence_hours',
                'discipline',
                'fee_clearance',
                'conseil_decision',
            ]);

            $table->enum('comparator', ['gte', 'gt', 'lte', 'lt', 'eq']);

            $table->decimal('threshold', 8, 3);

            // Only `subject_minimum` names a subject (§10.4: "per-subject
            // annual average vs a floor, for named core subjects").
            $table->foreignId('subject_id')
                ->nullable()
                ->constrained('subjects')
                ->restrictOnDelete();

            $table->decimal('weight', 6, 2)->default(0);

            // fee_clearance is ADVISORY BY DEFAULT: is_blocking defaults to 0
            // for it and enabling it requires the explicit written-warning
            // setting (§10.4, enforced in CreateCriteriaSet).
            $table->boolean('is_blocking')->default(true);

            $table->unsignedSmallInteger('sequence')->default(0);

            $table->timestamps();

            $table->unique(['criteria_set_id', 'sequence'], 'uq_promotion_criteria_seq');
        });

        DB::statement(
            'ALTER TABLE promotion_criteria ADD CONSTRAINT chk_promotion_criteria_weight CHECK (weight >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_criteria');
        Schema::dropIfExists('promotion_criteria_sets');
    }
};
