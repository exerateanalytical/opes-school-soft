<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hourly / vacataire time capture (docs/specs/05-hr-payroll.md 5.5, fixing
 * C6 - the product could not pay most teaching staff at a typical
 * Cameroonian private school without this).
 *
 * `hours_planned` (from the timetable) and `hours_taught` (reduced by staff
 * attendance) are PROPOSALS; only `hours_validated` ever reaches payroll,
 * and the run refuses any hourly staff member whose month is not fully
 * `validated` - no partial inclusion, no "assume planned".
 *
 * `timesheets` is the non-teaching analogue for hourly administrative staff.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teaching_hours_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('staff_contract_id');
            $table->foreign('staff_contract_id')->references('id')->on('staff_contracts')->restrictOnDelete();

            // First day of month - 00-core 5 vocabulary.
            $table->date('payroll_month');

            // Academics-owned tables: FKs for schema integrity, DB::table
            // for every read (module boundary).
            $table->unsignedBigInteger('class_group_id')->nullable();
            $table->foreign('class_group_id')->references('id')->on('class_groups')->restrictOnDelete();

            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreign('subject_id')->references('id')->on('subjects')->restrictOnDelete();

            $table->unsignedBigInteger('timetable_slot_id')->nullable();
            $table->foreign('timetable_slot_id')->references('id')->on('timetable_slots')->restrictOnDelete();

            $table->decimal('hours_planned', 6, 2)->default(0);
            $table->decimal('hours_taught', 6, 2)->default(0);
            $table->decimal('hours_validated', 6, 2)->nullable();

            $table->enum('status', ['draft', 'submitted', 'validated', 'rejected'])->default('draft');

            $table->unsignedBigInteger('validated_by')->nullable();
            $table->foreign('validated_by')->references('id')->on('users')->restrictOnDelete();
            $table->timestamp('validated_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['staff_contract_id', 'payroll_month', 'class_group_id', 'subject_id', 'timetable_slot_id'],
                'uq_thl_segment',
            );
        });

        // A row cannot claim `validated` without validated hours and a
        // validator - the payroll gate keys on this status.
        DB::statement(<<<'SQL'
            ALTER TABLE teaching_hours_logs ADD CONSTRAINT ck_thl_validated CHECK (
                status <> 'validated'
                OR (hours_validated IS NOT NULL AND validated_by IS NOT NULL)
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE teaching_hours_logs ADD CONSTRAINT ck_thl_hours_non_negative CHECK (
                hours_planned >= 0 AND hours_taught >= 0
                AND (hours_validated IS NULL OR hours_validated >= 0)
            )
        SQL);

        Schema::create('timesheets', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('staff_contract_id');
            $table->foreign('staff_contract_id')->references('id')->on('staff_contracts')->restrictOnDelete();

            $table->date('payroll_month');

            $table->decimal('hours_worked', 7, 2)->default(0);
            $table->decimal('hours_validated', 7, 2)->nullable();

            $table->enum('status', ['draft', 'submitted', 'validated', 'rejected'])->default('draft');

            $table->unsignedBigInteger('validated_by')->nullable();
            $table->foreign('validated_by')->references('id')->on('users')->restrictOnDelete();
            $table->timestamp('validated_at')->nullable();

            $table->timestamps();

            $table->unique(['staff_contract_id', 'payroll_month'], 'uq_timesheets_month');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE timesheets ADD CONSTRAINT ck_ts_validated CHECK (
                status <> 'validated'
                OR (hours_validated IS NOT NULL AND validated_by IS NOT NULL)
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE timesheets ADD CONSTRAINT ck_ts_hours_non_negative CHECK (
                hours_worked >= 0 AND (hours_validated IS NULL OR hours_validated >= 0)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('timesheets');
        Schema::dropIfExists('teaching_hours_logs');
    }
};
