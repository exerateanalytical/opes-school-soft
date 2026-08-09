<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The payroll component catalogue (docs/specs/05-hr-payroll.md 5.2) and its
 * system set (5.3), seeded with `calculation_order` encoding the dependency
 * arithmetic of 7: earnings run below 200, the bases are materialised at the
 * order-200 barrier, statutory deductions run above it, CAC (410) strictly
 * after IRPP (400) because its basis IS the rounded monthly IRPP amount.
 *
 * The seed carries NO amounts and NO accounts: statutory components find
 * their value through `statutory_rate_code` -> the (empty, unverified)
 * statutory_rates shells, and `expense_account_id`/`liability_account_id`
 * stay NULL until the accountant maps them. An enabled component whose rate
 * is unresolved FAILS PREFLIGHT - that is the product working, not broken.
 *
 * `is_system` rows cannot be deleted or reordered (5.2); SavePayrollComponent
 * enforces it, and every PayrollLine FK is RESTRICT anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_components', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('code', 24)->collation('utf8mb4_0900_as_cs')->unique();
            $table->string('name', 120);
            $table->string('name_fr', 120)->nullable();

            $table->enum('type', ['earning', 'employee_deduction', 'employer_charge', 'informational']);
            $table->enum('calculation', ['fixed', 'percentage', 'hourly', 'table', 'formula', 'statutory']);
            $table->enum('basis', [
                'basic', 'gross', 'sbt', 'taxable', 'cnps_capped', 'cnps_uncapped', 'irpp_amount', 'net',
            ])->nullable();

            // H: how PVID finds its rate - by code, resolved period-END
            // dated through StatutoryRateResolver, never a stored amount.
            $table->string('statutory_rate_code', 16)->nullable();

            // 5.4 whitelisted grammar ONLY; parsed at save, never eval().
            $table->text('formula_expression')->nullable();

            $table->unsignedInteger('calculation_order');
            $table->json('depends_on');

            $table->boolean('is_taxable')->default(false);      // enters SBT
            $table->boolean('is_cnps_liable')->default(false);  // enters the CNPS bases
            $table->boolean('is_prorated')->default(false);     // subject to 8.5 proration
            $table->boolean('subject_to_deduction_cap')->default(false);

            $table->unsignedBigInteger('expense_account_id')->nullable();
            $table->foreign('expense_account_id')->references('id')->on('chart_of_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('liability_account_id')->nullable();
            $table->foreign('liability_account_id')->references('id')->on('chart_of_accounts')->restrictOnDelete();

            $table->enum('analytic_axis_behaviour', ['follow_staff_allocation', 'fixed_value', 'none'])
                ->default('none');

            $table->string('print_group', 24)->nullable();
            $table->unsignedInteger('print_order')->default(0);

            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_system')->default(false);

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payroll_components ADD CONSTRAINT ck_pc_statutory_code CHECK (
                calculation <> 'statutory' OR statutory_rate_code IS NOT NULL
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE payroll_components ADD CONSTRAINT ck_pc_formula_expression CHECK (
                calculation <> 'formula' OR formula_expression IS NOT NULL
            )
        SQL);

        $this->seedSystemComponents();
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_components');
    }

    private function seedSystemComponents(): void
    {
        $now = now();

        $component = static fn (array $row): array => array_merge([
            'name_fr' => null,
            'basis' => null,
            'statutory_rate_code' => null,
            'formula_expression' => null,
            'depends_on' => '[]',
            'is_taxable' => false,
            'is_cnps_liable' => false,
            'is_prorated' => false,
            'subject_to_deduction_cap' => false,
            'expense_account_id' => null,
            'liability_account_id' => null,
            'analytic_axis_behaviour' => 'none',
            'print_group' => null,
            'print_order' => 0,
            'is_enabled' => true,
            'is_system' => true,
            'effective_from' => '2000-01-01',
            'effective_to' => null,
            'version' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], $row);

        DB::table('payroll_components')->insert([
            // ---- earnings (below the order-200 bases barrier) ----
            $component([
                'code' => 'BASIC', 'name' => 'Basic salary', 'name_fr' => 'Salaire de base',
                'type' => 'earning', 'calculation' => 'fixed', 'calculation_order' => 100,
                'is_taxable' => true, 'is_cnps_liable' => true, 'is_prorated' => true,
                'analytic_axis_behaviour' => 'follow_staff_allocation',
                'print_group' => 'earnings', 'print_order' => 10,
            ]),
            $component([
                'code' => 'HOURLY', 'name' => 'Hourly teaching pay', 'name_fr' => 'Vacations horaires',
                'type' => 'earning', 'calculation' => 'hourly', 'calculation_order' => 110,
                'is_taxable' => true, 'is_cnps_liable' => true,
                // Hourly earnings are inherently proportional - never
                // additionally prorated (8.5).
                'analytic_axis_behaviour' => 'follow_staff_allocation',
                'print_group' => 'earnings', 'print_order' => 20,
            ]),
            $component([
                // The premium TRANCHES are NEEDS VERIFICATION (2.4) - the
                // table ships empty and enabling this without it fails
                // preflight. Disabled until then.
                'code' => 'OVERTIME', 'name' => 'Overtime', 'name_fr' => 'Heures supplementaires',
                'type' => 'earning', 'calculation' => 'table', 'basis' => 'basic',
                'calculation_order' => 120, 'depends_on' => '["BASIC"]',
                'is_taxable' => true, 'is_cnps_liable' => true, 'is_enabled' => false,
                'analytic_axis_behaviour' => 'follow_staff_allocation',
                'print_group' => 'earnings', 'print_order' => 30,
            ]),
            $component([
                'code' => 'SENIORITY', 'name' => 'Seniority bonus', 'name_fr' => 'Prime d\'anciennete',
                'type' => 'earning', 'calculation' => 'percentage', 'basis' => 'basic',
                'calculation_order' => 130, 'depends_on' => '["BASIC"]',
                'is_taxable' => true, 'is_cnps_liable' => true, 'is_prorated' => true,
                'analytic_axis_behaviour' => 'follow_staff_allocation',
                'print_group' => 'earnings', 'print_order' => 40,
            ]),
            $component([
                'code' => 'ARREARS', 'name' => 'Arrears (rappel)', 'name_fr' => 'Rappel de salaire',
                'type' => 'earning', 'calculation' => 'fixed', 'calculation_order' => 160,
                'is_taxable' => true, 'is_cnps_liable' => true,
                'analytic_axis_behaviour' => 'follow_staff_allocation',
                'print_group' => 'earnings', 'print_order' => 50,
            ]),
            $component([
                'code' => 'THIRTEENTH', 'name' => 'Thirteenth month', 'name_fr' => 'Treizieme mois',
                'type' => 'earning', 'calculation' => 'formula', 'formula_expression' => 'basic',
                'calculation_order' => 170, 'depends_on' => '["BASIC"]',
                'is_taxable' => true, 'is_cnps_liable' => true, 'is_enabled' => false,
                'analytic_axis_behaviour' => 'follow_staff_allocation',
                'print_group' => 'earnings', 'print_order' => 60,
            ]),
            $component([
                // >= 1/16 of the preceding 12 months' remuneration is a
                // REFERENCE value (2.3): the divisor is configuration the
                // school confirms, so the shipped formula is the identity
                // placeholder and the component is disabled.
                'code' => 'ALLOCATION_CONGE', 'name' => 'Leave allowance', 'name_fr' => 'Allocation de conge',
                'type' => 'earning', 'calculation' => 'formula', 'formula_expression' => 'basic',
                'calculation_order' => 180, 'depends_on' => '["BASIC"]',
                'is_taxable' => true, 'is_cnps_liable' => true, 'is_enabled' => false,
                'analytic_axis_behaviour' => 'follow_staff_allocation',
                'print_group' => 'earnings', 'print_order' => 70,
            ]),

            // ---- statutory deductions and charges (above the barrier) ----
            $component([
                'code' => 'PVID_EE', 'name' => 'CNPS pension, employee share', 'name_fr' => 'PVID part salariale',
                'type' => 'employee_deduction', 'calculation' => 'statutory', 'basis' => 'cnps_capped',
                'statutory_rate_code' => 'PVID', 'calculation_order' => 300,
                'print_group' => 'deductions', 'print_order' => 10,
            ]),
            $component([
                'code' => 'PVID_ER', 'name' => 'CNPS pension, employer share', 'name_fr' => 'PVID part patronale',
                'type' => 'employer_charge', 'calculation' => 'statutory', 'basis' => 'cnps_capped',
                'statutory_rate_code' => 'PVID', 'calculation_order' => 310,
                'analytic_axis_behaviour' => 'follow_staff_allocation',
                'print_group' => 'employer', 'print_order' => 10,
            ]),
            $component([
                'code' => 'PF', 'name' => 'CNPS family allowances', 'name_fr' => 'Prestations familiales',
                'type' => 'employer_charge', 'calculation' => 'statutory', 'basis' => 'cnps_capped',
                'statutory_rate_code' => 'PF', 'calculation_order' => 320,
                'analytic_axis_behaviour' => 'follow_staff_allocation',
                'print_group' => 'employer', 'print_order' => 20,
            ]),
            $component([
                // basis cnps_UNCAPPED - the N1 fix, in the seed itself.
                'code' => 'RP', 'name' => 'CNPS occupational risk', 'name_fr' => 'Risques professionnels',
                'type' => 'employer_charge', 'calculation' => 'statutory', 'basis' => 'cnps_uncapped',
                'statutory_rate_code' => 'RP', 'calculation_order' => 330,
                'analytic_axis_behaviour' => 'follow_staff_allocation',
                'print_group' => 'employer', 'print_order' => 30,
            ]),
            $component([
                'code' => 'IRPP', 'name' => 'Salary income tax (IRPP)', 'name_fr' => 'IRPP',
                'type' => 'employee_deduction', 'calculation' => 'statutory', 'basis' => 'sbt',
                'statutory_rate_code' => 'IRPP', 'calculation_order' => 400,
                'depends_on' => '["PVID_EE"]',
                'print_group' => 'deductions', 'print_order' => 20,
            ]),
            $component([
                // basis irpp_amount, order strictly after IRPP: CAC is
                // computed on the ROUNDED monthly IRPP actually withheld
                // (6.3) - this edge did not exist in v1 (H5).
                'code' => 'CAC', 'name' => 'Centimes additionnels communaux', 'name_fr' => 'CAC',
                'type' => 'employee_deduction', 'calculation' => 'statutory', 'basis' => 'irpp_amount',
                'statutory_rate_code' => 'CAC', 'calculation_order' => 410,
                'depends_on' => '["IRPP"]',
                'print_group' => 'deductions', 'print_order' => 30,
            ]),
            $component([
                'code' => 'CFC_EE', 'name' => 'Credit Foncier, employee share', 'name_fr' => 'CFC part salariale',
                'type' => 'employee_deduction', 'calculation' => 'statutory', 'basis' => 'gross',
                'statutory_rate_code' => 'CFC', 'calculation_order' => 420,
                'print_group' => 'deductions', 'print_order' => 40,
            ]),
            $component([
                'code' => 'CFC_ER', 'name' => 'Credit Foncier, employer share', 'name_fr' => 'CFC part patronale',
                'type' => 'employer_charge', 'calculation' => 'statutory', 'basis' => 'gross',
                'statutory_rate_code' => 'CFC', 'calculation_order' => 430,
                'analytic_axis_behaviour' => 'follow_staff_allocation',
                'print_group' => 'employer', 'print_order' => 40,
            ]),
            $component([
                'code' => 'FNE', 'name' => 'Fonds National de l\'Emploi', 'name_fr' => 'FNE',
                'type' => 'employer_charge', 'calculation' => 'statutory', 'basis' => 'gross',
                'statutory_rate_code' => 'FNE', 'calculation_order' => 440,
                'analytic_axis_behaviour' => 'follow_staff_allocation',
                'print_group' => 'employer', 'print_order' => 50,
            ]),
            $component([
                // Enabled WITH an empty band table on purpose (4.5): a
                // school that silently pays no RAV is not compliant either.
                // Preflight check 5 blocks the run until bands exist.
                'code' => 'RAV', 'name' => 'Redevance audio-visuelle', 'name_fr' => 'RAV',
                'type' => 'employee_deduction', 'calculation' => 'statutory', 'basis' => 'gross',
                'statutory_rate_code' => 'RAV', 'calculation_order' => 450,
                'print_group' => 'deductions', 'print_order' => 50,
            ]),
            $component([
                // Bands key on BASIC, not gross (2.2).
                'code' => 'TDL', 'name' => 'Taxe de developpement local', 'name_fr' => 'TDL',
                'type' => 'employee_deduction', 'calculation' => 'statutory', 'basis' => 'basic',
                'statutory_rate_code' => 'TDL', 'calculation_order' => 460,
                'print_group' => 'deductions', 'print_order' => 60,
            ]),
            $component([
                // Informational: never posted, never deducted. The formula
                // states the 7.2 rule 4 invariant literally; the engine
                // asserts gross - deductions = net exactly on every item,
                // and `total_employee_deductions` is the engine-materialised
                // aggregate (a PayrollItem column, 10.2) - no employer
                // charge can appear here by construction.
                'code' => 'NET', 'name' => 'Net pay', 'name_fr' => 'Net a payer',
                'type' => 'informational', 'calculation' => 'formula',
                'formula_expression' => 'gross - total_employee_deductions',
                'calculation_order' => 900,
                'print_group' => 'net', 'print_order' => 10,
            ]),
        ]);
    }
};
