<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/03-tax-procurement.md §4.7 - SupplierPayment header, series
 * `PF/2026/000123`.
 *
 * Money is BIGINT SIGNED whole FCFA (00-core §7.1); the CHECK holds the
 * §4.7 identity `net_amount = gross_amount - withholding_amount` at the
 * database - the exact "netting a single 1 365 000 credit with no 447 leg"
 * error §6.4 warns about becomes unrepresentable, because the withheld
 * portion is carried explicitly and the disbursed amount must differ from
 * gross by exactly that portion.
 *
 * `payment_method` is the 04-fees v1 method set (cash / mobile_money /
 * bank - the spec's `PaymentMethod` "table" is an enum in that module; a
 * second definition is not created here). `fee_amount` is the operator
 * commission (6317); `fee_bearer` says whose money it was.
 *
 * `batch_id` is created WITHOUT its FK - `supplier_payment_batches` lands
 * in 250022 (pre-assigned filename order); the constraint is added there.
 *
 * Deletion (§9): RESTRICT - a payment is NEVER deleted, only voided
 * through `supplier_payment_voids` (250022). Enforced by trigger here and
 * by the model observer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payments', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('payment_no', 30)->collation('utf8mb4_0900_as_cs')->unique('uq_supplier_payments_no');

            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();

            // business_date(), never UTC now() (00-core §7.5).
            $table->date('payment_date');

            $table->string('payment_method', 20);

            // Derived from the payment method by the recording screen; the
            // ledger credit side of the settlement entry.
            $table->foreignId('treasury_account_id')->constrained('chart_of_accounts')->restrictOnDelete();

            // Cheque number, transfer ref, MoMo transaction id - mandatory
            // when the method says so (validated in the Action).
            $table->string('reference', 120)->nullable();

            // Σ allocated invoice amounts (TTC).
            $table->bigInteger('gross_amount')->default(0);
            // Recognised here when withholding_recognition = 'on_payment'.
            $table->bigInteger('withholding_amount')->default(0);
            // Operator commission to 6317 when the school bears it.
            $table->bigInteger('fee_amount')->default(0);
            $table->string('fee_bearer', 10)->default('school');
            $table->foreignId('fee_expense_account_id')->nullable()->constrained('chart_of_accounts')->restrictOnDelete();
            // Actually disbursed to the supplier.
            $table->bigInteger('net_amount')->default(0);

            $table->string('status', 10)->default('draft');
            // Cheques mirror the 04-fees model; the v1 methods are all
            // immediate instruments and clear at pay time.
            $table->string('clearing_state', 15)->default('not_applicable');

            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('paid_at')->nullable();

            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();

            // FK added in 250022 once supplier_payment_batches exists.
            $table->unsignedBigInteger('batch_id')->nullable();

            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->restrictOnDelete();
            $table->foreignId('accounting_period_id')->constrained('accounting_periods')->restrictOnDelete();

            $table->string('notes', 255)->nullable();

            $table->unsignedInteger('version')->default(0);
            $table->char('idempotency_key', 36)->nullable()->unique('uq_supplier_payments_idem');

            $table->timestamps();

            $table->index(['supplier_id', 'status'], 'ix_supplier_payments_supplier');
            $table->index(['status', 'payment_date'], 'ix_supplier_payments_status');
            $table->index('batch_id', 'ix_supplier_payments_batch');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE supplier_payments
              ADD CONSTRAINT ck_supplier_payments_status CHECK (
                status IN ('draft', 'approved', 'paid', 'voided')
              ),
              ADD CONSTRAINT ck_supplier_payments_clearing CHECK (
                clearing_state IN ('not_applicable', 'pending', 'cleared', 'bounced')
              ),
              ADD CONSTRAINT ck_supplier_payments_method CHECK (
                payment_method IN ('cash', 'mobile_money', 'bank')
              ),
              ADD CONSTRAINT ck_supplier_payments_fee_bearer CHECK (
                fee_bearer IN ('school', 'supplier')
              ),
              ADD CONSTRAINT ck_supplier_payments_net CHECK (
                net_amount = gross_amount - withholding_amount
              ),
              ADD CONSTRAINT ck_supplier_payments_amounts CHECK (
                gross_amount >= 0 AND withholding_amount >= 0 AND fee_amount >= 0
              )
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_supplier_payments_never_delete
            BEFORE DELETE ON supplier_payments
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A supplier payment is never deleted; void it instead (03-tax-procurement 9)';
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_supplier_payments_never_delete');
        Schema::dropIfExists('supplier_payments');
    }
};
