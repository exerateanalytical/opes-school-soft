<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Compensation is a HISTORY, not a scalar (docs/specs/05-hr-payroll.md 5.1,
 * fixing C7): a March raise must not change a January payslip, and a
 * retroactive grant generates ARREARS lines in the month of payment instead
 * of rewriting approved snapshots.
 *
 * `hourly_rates` (5.5, fixing C6) resolves staff -> grade -> class_level,
 * most specific wins; XOR-checked scope columns make "two scopes at once"
 * unrepresentable.
 *
 * `payroll_component_tests` (5.4): every formula component stores at least
 * one named input vector -> expected integer output, executed in CI and
 * re-executed at save. A formula with no passing test cannot be enabled -
 * preflight check 7 holds the same line at run time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_compensations', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('staff_contract_id');
            $table->foreign('staff_contract_id')->references('id')->on('staff_contracts')->restrictOnDelete();

            $table->string('component_code', 24)->collation('utf8mb4_0900_as_cs');
            $table->foreign('component_code')->references('code')->on('payroll_components')->restrictOnDelete();

            // SIGNED whole FCFA or basis points (Rate::SCALE) - XOR-checked.
            $table->bigInteger('amount')->nullable();
            $table->bigInteger('rate_bp')->nullable();

            $table->date('effective_from');
            // Exclusive, like every effective-dated table here.
            $table->date('effective_to')->nullable();

            // < effective_from means the intervening approved months get
            // ARREARS lines computed against their snapshots (5.1).
            $table->date('retroactive_from')->nullable();

            $table->unsignedBigInteger('granted_by');
            $table->foreign('granted_by')->references('id')->on('users')->restrictOnDelete();
            $table->string('grant_reason', 255);

            // No FK: the `documents` table belongs to Phase 13.
            $table->unsignedBigInteger('document_id')->nullable();

            $table->unsignedInteger('version')->default(0);
            $table->timestamps();

            $table->unique(
                ['staff_contract_id', 'component_code', 'effective_from'],
                'uq_staff_compensations_grant',
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE staff_compensations ADD CONSTRAINT ck_sc_amount_xor_rate CHECK (
                (amount IS NOT NULL) XOR (rate_bp IS NOT NULL)
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE staff_compensations ADD CONSTRAINT ck_sc_dates CHECK (
                effective_to IS NULL OR effective_to > effective_from
            )
        SQL);

        Schema::create('hourly_rates', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->enum('scope', ['staff', 'grade', 'class_level']);

            $table->unsignedBigInteger('staff_contract_id')->nullable();
            $table->foreign('staff_contract_id')->references('id')->on('staff_contracts')->restrictOnDelete();

            $table->unsignedBigInteger('salary_grade_id')->nullable();
            $table->foreign('salary_grade_id')->references('id')->on('salary_grades')->restrictOnDelete();

            // Academics-owned table; the FK is schema-level integrity, all
            // reads stay DB::table per the module boundary.
            $table->unsignedBigInteger('class_level_id')->nullable();
            $table->foreign('class_level_id')->references('id')->on('class_levels')->restrictOnDelete();

            // Optional per-subject differentiation.
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreign('subject_id')->references('id')->on('subjects')->restrictOnDelete();

            // SIGNED whole FCFA per validated hour.
            $table->bigInteger('rate_per_hour');

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->timestamps();

            $table->unique(
                ['scope', 'staff_contract_id', 'salary_grade_id', 'class_level_id', 'subject_id', 'effective_from'],
                'uq_hourly_rates_scope',
            );
        });

        // Exactly one scope column non-null, and it must match `scope`.
        DB::statement(<<<'SQL'
            ALTER TABLE hourly_rates ADD CONSTRAINT ck_hr_scope_xor CHECK (
                (scope = 'staff'       AND staff_contract_id IS NOT NULL AND salary_grade_id IS NULL     AND class_level_id IS NULL)
             OR (scope = 'grade'       AND staff_contract_id IS NULL     AND salary_grade_id IS NOT NULL AND class_level_id IS NULL)
             OR (scope = 'class_level' AND staff_contract_id IS NULL     AND salary_grade_id IS NULL     AND class_level_id IS NOT NULL)
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE hourly_rates ADD CONSTRAINT ck_hr_dates CHECK (
                effective_to IS NULL OR effective_to > effective_from
            )
        SQL);

        Schema::create('payroll_component_tests', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('payroll_component_id');
            $table->foreign('payroll_component_id')->references('id')->on('payroll_components')->restrictOnDelete();

            $table->string('name', 120);

            // Named input vector: {"variable": integer, ...}.
            $table->json('inputs');
            $table->bigInteger('expected');

            $table->timestamps();

            $table->unique(['payroll_component_id', 'name'], 'uq_pct_name');
        });

        $this->seedNetFormulaTest();
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_component_tests');
        Schema::dropIfExists('hourly_rates');
        Schema::dropIfExists('staff_compensations');
    }

    /**
     * NET is the one system component shipped enabled with
     * `calculation = formula` (290008), so 5.4's "a formula with no passing
     * test cannot be enabled" requires its stored test to ship alongside it.
     * The vector exercises the invariant statement, not any statutory value.
     */
    private function seedNetFormulaTest(): void
    {
        $netId = DB::table('payroll_components')->where('code', 'NET')->value('id');

        if ($netId === null) {
            return;
        }

        $now = now();

        DB::table('payroll_component_tests')->insert([
            'payroll_component_id' => $netId,
            'name' => 'net equals gross minus employee deductions',
            'inputs' => json_encode(['gross' => 100_000, 'total_employee_deductions' => 12_345], JSON_THROW_ON_ERROR),
            'expected' => 87_655,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
