<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per (assessment period x enrollment): the output of pipeline
     * stage 5 (AGGREGATE) and stage 6 (RANK), docs/specs/01-assessment.md 2.2.
     *
     * The table exists because ranking, class statistics, the bulletin, the
     * broadsheet and the promotion engine all read the same general average,
     * and 9.4 makes a second computation of it a review-blocking defect. They
     * read it from here.
     *
     * C3 (10.2) is enforced in the SCHEMA, not only in the Action. v1 divided
     * by zero, caught it, returned 0.00, and printed a student with no assessed
     * subjects as the worst in the class - banded Fail, ranked last, and still
     * counted in the class-size denominator. Three CHECK constraints below make
     * every one of those states unrepresentable.
     */
    public function up(): void
    {
        Schema::create('period_results', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('assessment_period_id')
                ->constrained('assessment_periods')
                ->restrictOnDelete();

            // 12.6: exactly ONE Enrollment per student per year owns all marks,
            // so the result keys on the enrollment and a mid-year transfer
            // never splits a student's period in two.
            $table->foreignId('enrollment_id')
                ->constrained('enrollments')
                ->restrictOnDelete();

            // 12.6 rule 1: "rank and class statistics for a period belong to
            // the class group of the segment covering AssessmentPeriod.ends_on"
            // - the student is ranked once, in the class they FINISHED the
            // period in. Denormalised here so the ranking pass, the roster and
            // the printed card cannot disagree about which class owns a
            // transferred student, and so a later segment edit does not
            // silently re-home a published rank.
            $table->foreignId('class_group_id')
                ->constrained('class_groups')
                ->restrictOnDelete();

            // Plain nullable unsigned BIGINTs without FK constraints: the
            // assessment_frameworks and grade_bands tables belong to the
            // framework-configuration workstream of this same phase, and a
            // constraint cannot reference a table whose creation order is not
            // guaranteed relative to this migration. The Action validates them.
            $table->unsignedBigInteger('framework_id')->nullable();
            $table->unsignedBigInteger('grade_band_id')->nullable();

            // ---------------------------------------------------------------
            // 10.4 rank_cohort_rule. NOT a stream id and NOT a class group id:
            // the cohort is the set of students whose averages are ARITHMETIC-
            // ALLY COMPARABLE, which `same_stream` defines by the CONTENT of
            // Stream.subject_basket (two streams carrying the same basket are
            // one cohort) and `identical_basket` defines by the student's own
            // set of counts_toward_average allocations. A fingerprint is the
            // only shape that expresses both. `all` stores the literal 'all'.
            //
            // Case-sensitive collation per 00-core 4: these are identifiers,
            // and two fingerprints differing only in case are two cohorts.
            // ---------------------------------------------------------------
            $table->string('cohort_key', 80)->collation('utf8mb4_0900_as_cs');

            // 10.4: the cohort is drawn from this scope before the rule
            // partitions it. Recorded per row so a card can print the basis.
            $table->enum('rank_scope', ['class_group', 'class_level', 'stream'])
                ->default('class_group');

            // 10.4: "printed on every card ... the student's own Sigma-coef, so
            // the basis is visible". DECIMAL because SubjectAllocation.
            // coefficient is DECIMAL(5,2) - half coefficients exist.
            $table->decimal('coefficient_sum', 8, 2)->default(0);

            // Sigma(M x Coef), the numerator of 10.1 and the totals row of 13.6.
            $table->decimal('weighted_total', 12, 3)->nullable();

            // 10.1. NULL - never 0.00 - when coefficient_sum is 0 (10.2).
            $table->decimal('general_average', 6, 3)->nullable();

            // Invariant 9: rounding to score_precision happens ONCE, at the end
            // of aggregation, and rank and band read the ROUNDED value. Storing
            // it is what makes T11 true by construction: two students at raw
            // 13.0138 and 13.0072 both hold 13.010 here and therefore tie,
            // rather than diverging on decimals no parent can see.
            $table->decimal('general_average_rounded', 6, 3)->nullable();

            // 10.3: the output of the ONE pass expression, stamped at compute
            // time. The pass-rate statistic reads this column and never
            // re-derives a threshold - GradeBand.is_pass is display metadata.
            $table->boolean('is_pass')->nullable();

            // 11: coefficient-weighted mean of GradeBand.grade_point. NULL when
            // any banded subject in scope has a NULL point, rather than
            // silently computed over a subset.
            $table->decimal('gpa', 5, 2)->nullable();

            $table->unsignedSmallInteger('subjects_counted')->default(0);

            // Stage-4 output per subject allocation, kept so 10.6 subject rank
            // and 10.7 per-subject statistics read one table instead of
            // recomputing the pipeline per statistic.
            $table->json('subject_scores')->nullable();

            // ---- Stage 6 --------------------------------------------------
            // `rank_position`, not `rank`: RANK is a reserved word in MySQL 8.
            $table->unsignedSmallInteger('rank_position')->nullable();

            // 10.2: "Rang : 5e / 62 counts only ranked students". Stored per row
            // because it is printed per card and must match the rank beside it.
            $table->unsignedSmallInteger('rank_denominator')->nullable();

            $table->boolean('is_ranked')->default(false);

            // 10.5. Named so the card can print the footnote that says WHY,
            // rather than an unexplained `NC`.
            $table->enum('nc_reason', [
                'null_average',
                'not_yet_assessable',
                'insufficient_periods',
            ])->nullable();

            $table->dateTime('computed_at')->nullable();

            $table->timestamps();

            $table->unique(['assessment_period_id', 'enrollment_id'], 'uq_period_results_scope');

            // The ranking pass and the statistics pass both sweep exactly this.
            $table->index(
                ['assessment_period_id', 'class_group_id', 'cohort_key'],
                'idx_period_results_cohort',
            );
            $table->index(['enrollment_id', 'assessment_period_id'], 'idx_period_results_card');
        });

        // ---------------------------------------------------------------
        // C3 / invariant 7, in the database.
        // ---------------------------------------------------------------

        // Sigma-coef = 0 => NULL average. Not 0.00.
        DB::statement(
            'ALTER TABLE period_results ADD CONSTRAINT chk_period_results_zero_coef '
            .'CHECK (coefficient_sum > 0 OR general_average IS NULL)'
        );

        // The raw and the rounded value are null together or present together.
        // A rounded value without a raw one would be a number with no
        // provenance; a raw one without a rounded one would leave rank and band
        // reading the unrounded figure, which invariant 9 forbids.
        DB::statement(
            'ALTER TABLE period_results ADD CONSTRAINT chk_period_results_rounding '
            .'CHECK ((general_average IS NULL AND general_average_rounded IS NULL) '
            .'OR (general_average IS NOT NULL AND general_average_rounded IS NOT NULL))'
        );

        // A NULL average can NEVER carry a rank, a pass verdict or a band. This
        // is the constraint v1 needed: it makes "ranked last with 0.00" an
        // INSERT error rather than a printed bulletin.
        DB::statement(
            'ALTER TABLE period_results ADD CONSTRAINT chk_period_results_null_not_ranked '
            .'CHECK (general_average IS NOT NULL OR (is_ranked = 0 AND rank_position IS NULL '
            .'AND rank_denominator IS NULL AND is_pass IS NULL AND grade_band_id IS NULL))'
        );

        // Ranked and rank_position agree in both directions, so "is_ranked but
        // no number" and "a number but not ranked" are both unrepresentable.
        DB::statement(
            'ALTER TABLE period_results ADD CONSTRAINT chk_period_results_rank_pair '
            .'CHECK ((is_ranked = 1 AND rank_position IS NOT NULL AND rank_denominator IS NOT NULL) '
            .'OR (is_ranked = 0 AND rank_position IS NULL))'
        );

        // 10.5: a ranked student is not NC, and an unranked one says why.
        DB::statement(
            'ALTER TABLE period_results ADD CONSTRAINT chk_period_results_nc_reason '
            .'CHECK ((is_ranked = 1 AND nc_reason IS NULL) OR is_ranked = 0)'
        );

        // A rank is never better than 1st, and never beyond its own denominator.
        DB::statement(
            'ALTER TABLE period_results ADD CONSTRAINT chk_period_results_rank_range '
            .'CHECK (rank_position IS NULL OR (rank_position >= 1 '
            .'AND rank_position <= rank_denominator))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('period_results');
    }
};
