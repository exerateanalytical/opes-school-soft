<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1 (2026_08_07_200002) deliberately created the FULL 01-assessment
     * 4.1 column set - every type in the enum including `month`, the weight,
     * counts_toward_parent, both entry-window columns, is_reporting_period and
     * status - so this migration adds NO columns. Read that file: the intent
     * was that the table never needs an ALTER when Assessment lands.
     *
     * One thing it could not do, and said so in a comment: it could not
     * constrain `framework_id`, because assessment_frameworks did not exist
     * yet. That FK is the whole of this migration.
     *
     * RESTRICT, per 00-core 10.5 and 01-assessment 3.1: a framework with
     * periods hanging off it is archived, never deleted. The column stays
     * NULLABLE - Phase 1 rows legitimately carry NULL until an operator
     * attaches a framework to the year - and MySQL exempts NULL children from
     * the constraint, which is the behaviour wanted.
     */
    public function up(): void
    {
        Schema::table('assessment_periods', function (Blueprint $table): void {
            $table->foreign('framework_id', 'fk_assessment_periods_framework')
                ->references('id')
                ->on('assessment_frameworks')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assessment_periods', function (Blueprint $table): void {
            $table->dropForeign('fk_assessment_periods_framework');
        });
    }
};
