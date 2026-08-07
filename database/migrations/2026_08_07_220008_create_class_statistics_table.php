<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * docs/specs/01-assessment.md 10.7. Computed per (period, class group, and
     * separately per subject allocation), over RANKED, NON-NULL students only,
     * inside the publication transaction and snapshotted - so a bulletin
     * reprinted a month later shows the class mean as it was at publication,
     * not as it is today.
     */
    public function up(): void
    {
        Schema::create('class_statistics', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('assessment_period_id')
                ->constrained('assessment_periods')
                ->restrictOnDelete();

            // 12.6 rule 1 again: the class group of the segment covering
            // AssessmentPeriod.ends_on. A transferred student is counted in
            // exactly one class's statistics, the one they finished in.
            $table->foreignId('class_group_id')
                ->constrained('class_groups')
                ->restrictOnDelete();

            // SENTINEL, deliberately NOT NULL, following the precedent set by
            // subject_allocations.stream_id: 0 means "the general average"
            // rather than a subject. MySQL permits unlimited duplicate NULLs in
            // a UNIQUE index, so a nullable column here would let two general
            // -average rows insert for one class group and the bulletin would
            // print whichever the query happened to return first. No FK
            // constraint, because 0 is not a subject_allocations row.
            $table->unsignedBigInteger('subject_allocation_id')->default(0);

            // The same 10.4 fingerprint as period_results.cohort_key. A class
            // group holding two elective baskets holds two cohorts, and a mean
            // taken across both is the mean of two non-comparable populations.
            $table->string('cohort_key', 80)->collation('utf8mb4_0900_as_cs');

            // 10.7 `n`: "count of non-NULL averages in the cohort". This is the
            // same denominator printed as `Rang : 5e / 62`.
            $table->unsignedSmallInteger('n')->default(0);

            // Computed on ROUNDED values (10.7), consistent with invariant 9.
            $table->decimal('mean', 6, 3)->nullable();

            // 10.7 "Cote" - printed as [Min-Max] beside the subject line.
            $table->decimal('min_score', 6, 3)->nullable();
            $table->decimal('max_score', 6, 3)->nullable();

            // LOWER median for even n, stated rather than left to the reader.
            $table->decimal('median', 6, 3)->nullable();

            // 10.7, named in full on purpose: POPULATION standard deviation,
            // divisor n. v1 said "stdev" and half a team would have written the
            // sample form; at n = 62 the two differ by ~0.8 %, which is enough
            // to make two schools' published figures irreconcilable. The column
            // name is the contract.
            $table->decimal('stdev_population', 7, 4)->nullable();

            // 10.7 + 10.3: count(avg >= framework.pass_score) / n, read from
            // period_results.is_pass, which is the single pass expression's
            // stamped output. GradeBand.is_pass is never consulted here.
            $table->unsignedSmallInteger('pass_count')->default(0);

            // Percentage with two decimals, e.g. 66.67.
            $table->decimal('pass_rate', 5, 2)->nullable();

            $table->dateTime('computed_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['assessment_period_id', 'class_group_id', 'subject_allocation_id', 'cohort_key'],
                'uq_class_statistics_scope',
            );

            $table->index(
                ['assessment_period_id', 'subject_allocation_id'],
                'idx_class_statistics_subject',
            );
        });

        // 10.2's exclusion rule, in the database: a cohort with no non-NULL
        // average has NO statistics - not a mean of zero, not a 0 % pass rate.
        // v1's zeroes were indistinguishable from a genuinely failing class.
        DB::statement(
            'ALTER TABLE class_statistics ADD CONSTRAINT chk_class_statistics_empty '
            .'CHECK (n > 0 OR (mean IS NULL AND min_score IS NULL AND max_score IS NULL '
            .'AND median IS NULL AND stdev_population IS NULL AND pass_count = 0 '
            .'AND pass_rate IS NULL))'
        );

        // More passes than students is arithmetically impossible, and would
        // mean an excluded student had been counted in the numerator only.
        DB::statement(
            'ALTER TABLE class_statistics ADD CONSTRAINT chk_class_statistics_pass_count '
            .'CHECK (pass_count <= n)'
        );

        DB::statement(
            'ALTER TABLE class_statistics ADD CONSTRAINT chk_class_statistics_cote '
            .'CHECK (min_score IS NULL OR max_score IS NULL OR min_score <= max_score)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('class_statistics');
    }
};
