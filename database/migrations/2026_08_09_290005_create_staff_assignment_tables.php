<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assignment, appraisal, discipline and analytic cost allocation
 * (docs/specs/05-hr-payroll.md 3.7).
 *
 * Assignments and appraisals key on the CONTRACT, not the person: a teacher
 * who converts from vacataire to permanent mid-year keeps their history on
 * the old contract. Discipline keys on BOTH, for the same reason student
 * discipline does - the sanction ladder is a property of the person, the
 * year filter a property of the contract.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_assignments', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('staff_contract_id');
            $table->foreign('staff_contract_id')->references('id')->on('staff_contracts')->restrictOnDelete();

            $table->unsignedBigInteger('class_group_id');
            $table->foreign('class_group_id')->references('id')->on('class_groups')->restrictOnDelete();

            $table->unsignedBigInteger('subject_id');
            $table->foreign('subject_id')->references('id')->on('subjects')->restrictOnDelete();

            $table->unsignedBigInteger('academic_year_id');
            $table->foreign('academic_year_id')->references('id')->on('academic_years')->restrictOnDelete();

            $table->timestamps();

            $table->unique(
                ['staff_contract_id', 'class_group_id', 'subject_id', 'academic_year_id'],
                'uq_staff_assignment'
            );
        });

        Schema::create('staff_appraisals', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('staff_contract_id');
            $table->foreign('staff_contract_id')->references('id')->on('staff_contracts')->restrictOnDelete();

            // e.g. `2026-T1`. One appraisal per contract per period.
            $table->string('period', 32);

            $table->decimal('score', 5, 2)->nullable();

            $table->unsignedBigInteger('reviewer_staff_id');
            $table->foreign('reviewer_staff_id')->references('id')->on('staff_members')->restrictOnDelete();

            $table->enum('status', ['draft', 'submitted', 'acknowledged'])->default('draft');
            $table->timestamp('acknowledged_at')->nullable();

            $table->timestamps();

            $table->unique(['staff_contract_id', 'period'], 'uq_staff_appraisal_period');
        });

        Schema::create('staff_appraisal_criteria', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('staff_appraisal_id');
            $table->foreign('staff_appraisal_id')->references('id')->on('staff_appraisals')->restrictOnDelete();

            $table->string('criterion', 150);
            $table->decimal('score', 5, 2)->nullable();
            $table->string('comment', 255)->nullable();

            $table->timestamps();

            $table->unique(['staff_appraisal_id', 'criterion'], 'uq_appraisal_criterion');
        });

        Schema::create('staff_discipline_cases', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('staff_member_id');
            $table->foreign('staff_member_id')->references('id')->on('staff_members')->restrictOnDelete();

            $table->unsignedBigInteger('staff_contract_id');
            $table->foreign('staff_contract_id')->references('id')->on('staff_contracts')->restrictOnDelete();

            $table->string('case_ref', 32)->collation('utf8mb4_0900_as_cs')->unique();
            $table->date('opened_on');
            $table->string('sanction', 150)->nullable();

            // No FK: `documents` belongs to Phase 13.
            $table->unsignedBigInteger('document_id')->nullable();

            $table->date('closed_on')->nullable();

            $table->timestamps();
        });

        // 3.7: analytic split of one contract's employment cost. Invariant -
        // the percentages over any effective date sum to exactly 100%
        // (Rate::SCALE basis points), asserted in SaveCostAllocation.
        // Statutory amounts are computed employer-wide FIRST (H3); allocation
        // happens downstream via Money's largest-remainder Allocator so the
        // parts sum exactly to the computed cost.
        Schema::create('staff_cost_allocations', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('staff_contract_id');
            $table->foreign('staff_contract_id')->references('id')->on('staff_contracts')->restrictOnDelete();

            $table->unsignedBigInteger('analytic_value_id');
            $table->foreign('analytic_value_id')->references('id')->on('analytic_values')->restrictOnDelete();

            // Basis points at App\Support\Rate\Rate::SCALE (100 000 = 100%).
            $table->unsignedInteger('percentage_bp');

            $table->date('effective_from');
            // Exclusive.
            $table->date('effective_to')->nullable();

            $table->unsignedBigInteger('created_by');
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();

            $table->timestamps();

            $table->unique(
                ['staff_contract_id', 'analytic_value_id', 'effective_from'],
                'uq_staff_cost_allocation'
            );
        });

        DB::statement(
            'ALTER TABLE staff_cost_allocations ADD CONSTRAINT ck_cost_alloc_bp CHECK (percentage_bp > 0 AND percentage_bp <= 100000)'
        );
        DB::statement(
            'ALTER TABLE staff_cost_allocations ADD CONSTRAINT ck_cost_alloc_dates CHECK (effective_to IS NULL OR effective_to > effective_from)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_cost_allocations');
        Schema::dropIfExists('staff_discipline_cases');
        Schema::dropIfExists('staff_appraisal_criteria');
        Schema::dropIfExists('staff_appraisals');
        Schema::dropIfExists('staff_assignments');
    }
};
