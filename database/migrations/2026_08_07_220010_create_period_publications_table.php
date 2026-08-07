<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/01-assessment.md 13.2 - `PeriodPublication`, C8, PER CLASS GROUP.
 *
 * The correction this table exists for: v1 published per PERIOD and globally,
 * so one teacher late with one subject in one class blocked report cards for
 * the entire school. In a 30-class secondary school that is a guaranteed weekly
 * deadlock. The class group in the unique key is the whole fix (T14).
 *
 * The row is also the CONCURRENCY TOKEN (00-core 11): `PublishPeriod` takes
 * SELECT ... FOR UPDATE here, flips to `publishing` through a conditional
 * UPDATE with an affected-rows check (00-core 10.4), snapshots, then commits.
 * Marks entry takes a shared lock on the same row. T17 - two concurrent
 * publishes produce exactly one snapshot batch - is a property of this row and
 * of nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('period_publications', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // 00-core 10.5: AssessmentPeriod and ClassGroup are RESTRICT.
            // Deleting either out from under an issued bulletin is exactly the
            // history rewrite the deletion matrix exists to prevent.
            $table->foreignId('assessment_period_id')
                ->constrained('assessment_periods')
                ->restrictOnDelete();
            $table->foreignId('class_group_id')
                ->constrained('class_groups')
                ->restrictOnDelete();

            $table->enum('status', [
                'draft',
                'marks_open',
                'marks_closed',
                'publishing',
                'published',
                'unpublished',
            ])->default('draft');

            // UUID grouping every snapshot written by ONE publication. This is
            // the observable T17 asserts on: two racing publishes must leave one
            // distinct batch id across the snapshots of this publication, not
            // two.
            $table->char('snapshot_batch_id', 36)->nullable();

            // Incremented by each applied amendment (15.2 step 3). Snapshots
            // carry the value they were written at, so generation 1 stays
            // readable after generation 2 exists.
            $table->unsignedInteger('generation')->default(1);

            // 13.1: the snapshot pins the VERSION. Nullable in the column
            // because a draft publication has not chosen one yet; the CHECK
            // below makes it NOT NULL from `publishing` onwards, which is the
            // "NOT NULL once published" the spec asks for.
            $table->foreignId('report_card_config_version_id')
                ->nullable()
                ->constrained('report_card_config_versions')
                ->restrictOnDelete();

            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('published_at')->nullable();

            $table->foreignId('unpublished_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('unpublished_at')->nullable();
            $table->string('unpublish_reason', 500)->nullable();

            // The LAST computed gate failures, all of them together. 13.2 is
            // explicit that gates are "all blocking, all reported together, not
            // one at a time" - a registrar who fixes one gate and is then told
            // about the next one will publish late every single term.
            $table->json('blocking_report')->nullable();

            // 00-core 10.6 optimistic lock.
            $table->unsignedInteger('version')->default(1);

            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(
                ['assessment_period_id', 'class_group_id'],
                'uq_period_publications_period_group',
            );

            // The publication board query: "which of my 30 class groups are
            // still blocked for Sequence 3".
            $table->index(['assessment_period_id', 'status'], 'idx_period_publications_period_status');
        });

        DB::statement(
            'ALTER TABLE period_publications ADD CONSTRAINT chk_period_publications_version_pinned '
            ."CHECK (status NOT IN ('publishing','published','unpublished') "
            .'OR report_card_config_version_id IS NOT NULL)'
        );

        // 13.2: un-publication "is explicit, permission-gated, requires a
        // reason". A NULL reason on an unpublished row is a card withdrawn from
        // 62 families with no recorded justification.
        DB::statement(
            'ALTER TABLE period_publications ADD CONSTRAINT chk_period_publications_unpublish_reason '
            ."CHECK (status <> 'unpublished' OR (unpublish_reason IS NOT NULL AND unpublished_by IS NOT NULL))"
        );

        DB::statement(
            'ALTER TABLE period_publications ADD CONSTRAINT chk_period_publications_generation '
            .'CHECK (generation >= 1)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('period_publications');
    }
};
