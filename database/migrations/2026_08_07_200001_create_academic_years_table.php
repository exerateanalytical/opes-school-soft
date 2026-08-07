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
        Schema::create('academic_years', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // 00-core 4: identifier columns use the accent- and case-SENSITIVE
            // collation, so '2026-2027' and '2026-2027 ' style near-duplicates
            // are not falsely merged and real codes are not falsely rejected.
            $table->string('code', 32)->collation('utf8mb4_0900_as_cs')->unique();
            $table->string('name', 160);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('is_current')->default(false);
            $table->enum('status', ['planned', 'active', 'closed'])->default('planned');
            $table->timestamps();

            // "At most one current year", enforced by the database (00-core
            // 10.1 pattern). MySQL 8 has no partial indexes, so a stored
            // generated column stands in for one: the column is 1 when
            // is_current = 1 and NULL otherwise, and MySQL permits unlimited
            // duplicate NULLs in a UNIQUE index - so any number of non-current
            // rows coexist, but a second row with is_current = 1 violates the
            // unique key. A race between two SetCurrentAcademicYear calls
            // therefore surfaces as a constraint error, never as two silent
            // "current" years.
            $table->tinyInteger('current_key')
                ->nullable()
                ->storedAs('CASE WHEN `is_current` = 1 THEN 1 END');
            $table->unique('current_key', 'uq_academic_years_current');

            $table->index('starts_on');
        });

        // 00-core 8: ends_on must fall after starts_on. The gapless-contiguity
        // invariant across YEARS is multi-row and lives in CreateAcademicYear
        // under lock; this single-row sanity check belongs to the database.
        DB::statement(
            'ALTER TABLE academic_years ADD CONSTRAINT chk_academic_years_dates CHECK (starts_on < ends_on)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_years');
    }
};
