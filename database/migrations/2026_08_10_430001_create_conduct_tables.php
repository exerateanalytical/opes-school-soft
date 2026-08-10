<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/01-assessment.md §12.3 - `ConductAssessment`, the MINESEC
 * conduct block: five graded dimensions per student per period.
 *
 * THE INVARIANT THAT MATTERS: conduct is NOT an input to the general average
 * and never enters §10.1. It is graded on its own scale, printed in its own
 * block on the bulletin, and deliberately has no numeric weight. Nothing in
 * the averaging path may ever read this table - there is a test asserting
 * exactly that, because the failure mode (a conduct grade quietly moving a
 * student's rank) would be invisible and wrong.
 *
 * The scale is a REFERENCE TABLE rather than an enum because it differs by
 * framework: TB/B/AB/P/M for the Francophone secondary bulletin, A/ECA/NA
 * for a competency-based primary framework. Seeding a single hardcoded set
 * would be the "wrong seeded value that looks authoritative" 00-core §16
 * warns about, so scales ship configurable and empty of assumptions.
 *
 * The five dimension columns reference scale LEVELS rather than storing a
 * letter, so a school that renames "Assez bien" to something else does not
 * silently rewrite history, and a level cannot be deleted while any
 * assessment still points at it (RESTRICT).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conduct_scales', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32);
            $table->string('name', 120);
            $table->string('name_fr', 120);
            $table->foreignId('framework_id')->nullable()
                ->constrained('assessment_frameworks')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('code', 'uq_conduct_scales_code');
        });

        Schema::create('conduct_scale_levels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conduct_scale_id')->constrained('conduct_scales')->restrictOnDelete();
            $table->string('code', 8);
            $table->string('label', 80);
            $table->string('label_fr', 80);

            // Ordering only - deliberately NOT a mark. A conduct level has no
            // numeric value because conduct never enters an average.
            $table->integer('sequence');

            $table->timestamps();

            $table->unique(['conduct_scale_id', 'code'], 'uq_conduct_levels_scale_code');
            $table->unique(['conduct_scale_id', 'sequence'], 'uq_conduct_levels_scale_seq');
        });

        Schema::create('conduct_assessments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('enrollment_id')->constrained('enrollments')->restrictOnDelete();
            $table->foreignId('assessment_period_id')->constrained('assessment_periods')->restrictOnDelete();
            $table->foreignId('conduct_scale_id')->constrained('conduct_scales')->restrictOnDelete();

            // The five MINESEC dimensions (§12.3).
            $table->foreignId('conduite_level_id')->constrained('conduct_scale_levels')->restrictOnDelete();
            $table->foreignId('travail_level_id')->constrained('conduct_scale_levels')->restrictOnDelete();
            $table->foreignId('assiduite_level_id')->constrained('conduct_scale_levels')->restrictOnDelete();
            $table->foreignId('discipline_level_id')->constrained('conduct_scale_levels')->restrictOnDelete();
            $table->foreignId('tenue_level_id')->constrained('conduct_scale_levels')->restrictOnDelete();

            $table->foreignId('assessed_by_staff_id')->constrained('staff_members')->restrictOnDelete();
            $table->dateTime('assessed_at');
            $table->string('notes', 500)->nullable();

            $table->timestamps();

            $table->unique(['enrollment_id', 'assessment_period_id'], 'uq_conduct_enrollment_period');
            $table->index('assessment_period_id', 'ix_conduct_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conduct_assessments');
        Schema::dropIfExists('conduct_scale_levels');
        Schema::dropIfExists('conduct_scales');
    }
};
