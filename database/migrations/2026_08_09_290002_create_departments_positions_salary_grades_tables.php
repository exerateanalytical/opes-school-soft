<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 HR reference data (docs/specs/05-hr-payroll.md 3.4, 2.4).
 *
 * `departments` already exists (Academics created it in Phase 2 for subject
 * grouping and it doubles as the HR department table - one school, one
 * department list). This migration only adds the head-of-department FK that
 * Phase 2 deferred "until staff lands".
 *
 * `collective_agreements` ships EMPTY per 2.4: which national convention
 * covers the customer school is NEEDS VERIFICATION, and seeding a guess would
 * put a wrong classification on every payslip. Same for salary-grade amounts:
 * the grade rows carry NO salary figure - pay lives on `staff_compensations`
 * (5.1), and any scale seeded here would be an unverified statutory table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            // RESTRICT, not SET NULL: staff members are archived, never
            // deleted (00-core 10.5), so the referenced row cannot vanish.
            $table->foreign('head_staff_id')
                ->references('id')->on('staff_members')
                ->restrictOnDelete();
        });

        Schema::create('positions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('code', 20)->collation('utf8mb4_0900_as_cs')->unique();
            $table->string('name', 100);
            $table->string('name_fr', 100)->nullable();

            // Teaching positions gate `staff_assignments` (3.7): only a
            // contract on a teaching position may be assigned to a class.
            $table->boolean('is_teaching')->default(false);

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('collective_agreements', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('code', 20)->collation('utf8mb4_0900_as_cs')->unique();
            $table->string('name', 150);
            $table->string('reference', 100)->nullable();

            // No FK: the `documents` table belongs to Phase 13.
            $table->unsignedBigInteger('source_document_id')->nullable();

            // FALSE until the customer confirms which convention applies
            // (2.4). An unverified agreement must not classify anybody.
            $table->boolean('is_verified')->default(false);

            $table->timestamps();
        });

        Schema::create('salary_grades', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('code', 20)->collation('utf8mb4_0900_as_cs')->unique();
            $table->string('name', 100);
            $table->string('name_fr', 100)->nullable();

            // Printed on the payslip via the contract (14.1).
            $table->string('category', 16)->nullable();
            $table->string('echelon', 16)->nullable();

            $table->unsignedBigInteger('collective_agreement_id')->nullable();
            $table->foreign('collective_agreement_id')
                ->references('id')->on('collective_agreements')
                ->restrictOnDelete();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('communes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name', 100);
            $table->string('region', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Commune names repeat across regions (there are two Bafias);
            // the pair is what identifies the TDL payee.
            $table->unique(['name', 'region']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communes');
        Schema::dropIfExists('salary_grades');
        Schema::dropIfExists('collective_agreements');
        Schema::dropIfExists('positions');

        Schema::table('departments', function (Blueprint $table): void {
            $table->dropForeign(['head_staff_id']);
        });
    }
};
