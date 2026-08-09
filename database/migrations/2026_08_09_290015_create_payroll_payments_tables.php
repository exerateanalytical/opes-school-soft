<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment / disbursement of an approved payroll run (docs/specs/05-hr-payroll.md
 * 8.8 - v1's "on payment: clear the payables" had no operational trigger and
 * no entity).
 *
 * COORDINATION NOTE (docs/plans/phase-11.md agent scopes): `payroll_runs`,
 * `payroll_items` and `payroll_item_snapshots` belong to the F3 run-engine
 * package (2026_08_09_2900{11,12,13}). The guarded stubs below exist ONLY for
 * the parallel-build window in which this package migrates ahead of F3: in
 * the merged tree F3's files run first (lower sequence numbers) and the
 * `Schema::hasTable` guards never fire. The stub columns are the 8.1/10.2
 * subset this package reads, under the spec's column names, so code written
 * against them is code written against the real tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_runs')) {
            Schema::create('payroll_runs', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->date('payroll_month');
                $table->string('run_type', 24)->default('regular');
                $table->string('status', 16)->default('draft');
                $table->unsignedBigInteger('employer_profile_id')->nullable();
                $table->unsignedBigInteger('journal_entry_id')->nullable();
                $table->unsignedBigInteger('calculated_by')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('payroll_items')) {
            Schema::create('payroll_items', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('payroll_run_id')->index();
                $table->unsignedBigInteger('staff_member_id')->index();
                $table->unsignedBigInteger('staff_contract_id')->nullable();
                $table->decimal('days_worked', 5, 2)->nullable();
                $table->decimal('days_in_period', 5, 2)->nullable();
                $table->bigInteger('gross')->default(0);
                $table->bigInteger('sbt')->default(0);
                $table->bigInteger('cnps_capped_base')->default(0);
                $table->bigInteger('cnps_uncapped_base')->default(0);
                $table->bigInteger('irpp_amount')->default(0);
                $table->bigInteger('net')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('payroll_item_snapshots')) {
            Schema::create('payroll_item_snapshots', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('payroll_item_id')->unique();
                $table->unsignedInteger('snapshot_version')->default(1);
                $table->json('payload');
                $table->char('payload_hash', 64);
                $table->timestamp('created_at')->useCurrent();
            });
        }

        Schema::create('payroll_payments', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('payroll_run_id')->index();

            // The 04-fees v1 instrument set, as `supplier_payments` records it.
            $table->string('payment_method', 30);

            $table->unsignedBigInteger('treasury_account_id');
            $table->foreign('treasury_account_id')->references('id')->on('chart_of_accounts')->restrictOnDelete();

            $table->date('value_date');

            $table->bigInteger('total_amount');

            // No FK: the disbursement artefact lands in the Documents module
            // (Phase 13) and is referenced by id only, like every *_document_id
            // in this phase.
            $table->unsignedBigInteger('disbursement_file_id')->nullable();

            $table->enum('status', ['prepared', 'exported', 'confirmed', 'partially_failed'])->default('prepared');

            $table->unsignedBigInteger('exported_by')->nullable();
            $table->foreign('exported_by')->references('id')->on('users')->restrictOnDelete();
            $table->timestamp('exported_at')->nullable();

            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->restrictOnDelete();

            // 00-core 6.2 rule 7: double disbursement is structurally refused.
            $table->string('idempotency_key', 64)->unique();

            $table->unsignedBigInteger('created_by');
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();

            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payroll_payments ADD CONSTRAINT ck_ppay_amount CHECK (total_amount >= 0)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE payroll_payments ADD CONSTRAINT ck_ppay_method CHECK (
                payment_method IN ('cash', 'mobile_money', 'bank')
            )
        SQL);

        Schema::create('payroll_payment_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('payroll_payment_id');
            $table->foreign('payroll_payment_id')->references('id')->on('payroll_payments')->restrictOnDelete();

            $table->unsignedBigInteger('payroll_item_id')->index();

            $table->unsignedBigInteger('staff_member_id');
            $table->foreign('staff_member_id')->references('id')->on('staff_members')->restrictOnDelete();

            $table->bigInteger('amount');

            // Ciphertext copied from the staff record at prepare time
            // (00-core 9.5); the model's `encrypted` cast decrypts it, and
            // ONLY the export path reads it.
            $table->text('beneficiary_account')->nullable();

            $table->enum('status', ['pending', 'exported', 'confirmed', 'failed'])->default('pending');
            $table->string('failure_reason', 255)->nullable();

            // 8.8: a failed line is re-exportable; a live (non-failed) line
            // per payroll item is UNIQUE across ALL payments - the
            // double-disbursement guard, in the 00-core 10.1 generated-column
            // idiom.
            $table->unsignedBigInteger('live_item_key')->nullable()
                ->storedAs("CASE WHEN `status` <> 'failed' THEN `payroll_item_id` END");

            $table->timestamps();

            $table->unique('live_item_key', 'uq_ppl_live_item');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payroll_payment_lines ADD CONSTRAINT ck_ppl_amount CHECK (amount > 0)
        SQL);

        // The real FKs to the F3 tables attach only when those tables exist
        // (always true in the merged tree - see the coordination note above).
        if (Schema::hasTable('payroll_runs')) {
            Schema::table('payroll_payments', function (Blueprint $table): void {
                $table->foreign('payroll_run_id')->references('id')->on('payroll_runs')->restrictOnDelete();
            });
        }

        if (Schema::hasTable('payroll_items')) {
            Schema::table('payroll_payment_lines', function (Blueprint $table): void {
                $table->foreign('payroll_item_id')->references('id')->on('payroll_items')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_payment_lines');
        Schema::dropIfExists('payroll_payments');
    }
};
