<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leave as a LEDGER (docs/specs/05-hr-payroll.md 12, fixing H8: v1's mutable
 * `balance` column was unauditable and could not answer "what was the balance
 * on 30 June"). Signed deltas, never a mutable quantity - the same fix
 * 06-assets-stores mandates for stock.
 *
 * `leave_types` is seeded WITHOUT `statutory_days` and WITHOUT
 * `monthly_accrual_days`: the 1.5 j.o./month accrual rate and the statutory
 * entitlements are 2.3 REFERENCE VALUES, never seed data (0). The accrual
 * Action refuses until an operator configures the rate against a verified
 * source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('code', 32)->unique();
            $table->string('name', 100);
            $table->string('name_fr', 100);

            $table->boolean('is_paid');
            $table->enum('payer', ['employer', 'cnps', 'unpaid']);

            // Does time SPENT on this leave itself accrue annual leave?
            $table->boolean('accrues_leave');

            // Drives the monthly accrual (12.3): months on a FALSE type
            // accrue nothing.
            $table->boolean('counts_as_effective_service');

            // NULL where unverified; never guessed.
            $table->unsignedInteger('statutory_days')->nullable();

            // The 12.3 accrual rate, per month of effective service. Ships
            // NULL (reference value 1.5 j.o. is NOT seed data, 2.3).
            $table->decimal('monthly_accrual_days', 4, 2)->nullable();

            $table->boolean('requires_medical_certificate')->default(false);
            $table->unsignedInteger('max_consecutive_days')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE leave_types ADD CONSTRAINT ck_lt_unpaid_payer CHECK (
                (payer = 'unpaid') = (is_paid = FALSE)
            )
        SQL);

        DB::table('leave_types')->insert(array_map(
            static fn (array $row): array => $row + [
                'statutory_days' => null,
                'monthly_accrual_days' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                [
                    'code' => 'conge_annuel', 'name' => 'Annual leave', 'name_fr' => 'Congé annuel',
                    'is_paid' => true, 'payer' => 'employer',
                    'accrues_leave' => true, 'counts_as_effective_service' => true,
                    'requires_medical_certificate' => false, 'max_consecutive_days' => null,
                ],
                [
                    'code' => 'conge_maternite', 'name' => 'Maternity leave', 'name_fr' => 'Congé de maternité',
                    'is_paid' => true, 'payer' => 'cnps',
                    'accrues_leave' => true, 'counts_as_effective_service' => true,
                    'requires_medical_certificate' => true, 'max_consecutive_days' => null,
                ],
                [
                    'code' => 'conge_maladie', 'name' => 'Sick leave', 'name_fr' => 'Congé de maladie',
                    'is_paid' => true, 'payer' => 'employer',
                    'accrues_leave' => true, 'counts_as_effective_service' => true,
                    'requires_medical_certificate' => true, 'max_consecutive_days' => null,
                ],
                [
                    'code' => 'permission_exceptionnelle', 'name' => 'Exceptional permission', 'name_fr' => 'Permission exceptionnelle',
                    'is_paid' => true, 'payer' => 'employer',
                    'accrues_leave' => true, 'counts_as_effective_service' => true,
                    'requires_medical_certificate' => false, 'max_consecutive_days' => null,
                ],
                [
                    'code' => 'sans_solde', 'name' => 'Unpaid leave', 'name_fr' => 'Congé sans solde',
                    'is_paid' => false, 'payer' => 'unpaid',
                    'accrues_leave' => false, 'counts_as_effective_service' => false,
                    'requires_medical_certificate' => false, 'max_consecutive_days' => null,
                ],
            ],
        ));

        // The APPEND-ONLY signed-delta ledger. Balance is always
        // SELECT SUM(delta_days); nothing is ever edited or deleted.
        Schema::create('leave_accruals', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('staff_contract_id');
            $table->foreign('staff_contract_id')->references('id')->on('staff_contracts')->restrictOnDelete();

            $table->unsignedBigInteger('leave_type_id');
            $table->foreign('leave_type_id')->references('id')->on('leave_types')->restrictOnDelete();

            $table->enum('entry_type', [
                'accrual', 'taken', 'adjustment', 'encashed', 'carried_forward', 'forfeited', 'opening',
            ]);

            // + accrual, − taken.
            $table->decimal('delta_days', 6, 2);

            $table->date('effective_on');

            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->string('reason', 255)->nullable();

            // NULL for scheduled system accruals (Actor::system() has no id).
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();

            $table->timestamp('created_at')->useCurrent();

            // 12.3 idempotency: ONE monthly accrual row per contract/type,
            // stated in the schema via the 00-core 10.1 generated-column idiom.
            $table->string('accrual_month_key', 64)->nullable()->storedAs(
                "CASE WHEN `entry_type` = 'accrual'"
                ." THEN CONCAT_WS('|', `staff_contract_id`, `leave_type_id`, `effective_on`) END"
            );

            $table->unique('accrual_month_key', 'uq_leave_accrual_month');
            $table->index(['staff_contract_id', 'leave_type_id'], 'idx_leave_accruals_balance');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE leave_accruals ADD CONSTRAINT ck_la_nonzero CHECK (delta_days <> 0)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE leave_accruals ADD CONSTRAINT ck_la_signs CHECK (
                (entry_type <> 'taken' OR delta_days < 0)
                AND (entry_type <> 'accrual' OR delta_days > 0)
            )
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_leave_accruals_immutable
            BEFORE UPDATE ON leave_accruals
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'leave_accruals is an append-only ledger (05-hr-payroll 12.2): corrections are new adjustment rows, never edits.';
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_leave_accruals_no_delete
            BEFORE DELETE ON leave_accruals
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'leave_accruals rows are never deleted (05-hr-payroll 12.2): write a compensating adjustment instead.';
            END
        SQL);

        Schema::create('leave_requests', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('staff_contract_id');
            $table->foreign('staff_contract_id')->references('id')->on('staff_contracts')->restrictOnDelete();

            $table->unsignedBigInteger('leave_type_id');
            $table->foreign('leave_type_id')->references('id')->on('leave_types')->restrictOnDelete();

            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('working_days', 5, 2);

            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected', 'cancelled', 'taken'])
                ->default('draft');

            // The spec's `approver_staff_id`, realised against `users`:
            // approvals are performed by authenticated accounts everywhere in
            // this codebase (00-core 14), and the dossier resolves the person
            // through the account link.
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->foreign('approved_by')->references('id')->on('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();

            $table->unsignedBigInteger('medical_certificate_document_id')->nullable();

            // Who covers the classes.
            $table->unsignedBigInteger('replacement_staff_contract_id')->nullable();
            $table->foreign('replacement_staff_contract_id')->references('id')->on('staff_contracts')->restrictOnDelete();

            $table->unsignedInteger('version')->default(0);
            $table->timestamps();

            $table->index(['staff_contract_id', 'status'], 'idx_leave_requests_contract');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE leave_requests ADD CONSTRAINT ck_lr_dates CHECK (ends_on >= starts_on)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE leave_requests ADD CONSTRAINT ck_lr_working_days CHECK (working_days > 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_leave_accruals_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_leave_accruals_immutable');
        Schema::dropIfExists('leave_accruals');
        Schema::dropIfExists('leave_types');
    }
};
