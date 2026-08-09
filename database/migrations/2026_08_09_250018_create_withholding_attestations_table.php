<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/03-tax-procurement.md §6.6 - attestation de retenue à la
 * source, series `ATT/2026/000123`.
 *
 * Exactly ONE of supplier_payment_id / supplier_invoice_id is non-null,
 * per the TaxSettings.withholding_recognition basis - enforced by CHECK.
 *
 * `supplier_payment_id` is created here WITHOUT its FK: `supplier_payments`
 * (Block D, 250020) carries a later timestamp, so F4's 250022 adds the
 * constraint once the target exists. Do not "fix" this by reordering -
 * migration numbers are pre-assigned across five parallel work packages.
 * Same story for `tax_declaration_id` → `tax_declarations` (Block E,
 * 250025).
 *
 * Amounts are SNAPSHOTTED - never recomputed at print time - and an
 * `issued` attestation is immutable: corrections issue a replacement
 * (`replaced_by_attestation_id`, UNIQUE self-FK - a chain, never an
 * in-place edit). Deletion is RESTRICT, never (§9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withholding_attestations', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('attestation_no', 30)->collation('utf8mb4_0900_as_cs')->unique('uq_withholding_attestations_no');

            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();

            // FK added by F4's 250022 (see class docblock).
            $table->unsignedBigInteger('supplier_payment_id')->nullable();
            $table->foreignId('supplier_invoice_id')->nullable()->constrained('supplier_invoices')->restrictOnDelete();

            $table->foreignId('withholding_rule_id')->constrained('withholding_rules')->restrictOnDelete();

            $table->unsignedSmallInteger('period_month');
            $table->unsignedSmallInteger('period_year');

            // Snapshotted; rate in App\Support\Rate scale (100 000 = 100%).
            $table->bigInteger('base_amount');
            $table->bigInteger('rate_bp_applied');
            $table->bigInteger('withheld_amount');

            // FK added when tax_declarations lands (Block E).
            $table->unsignedBigInteger('tax_declaration_id')->nullable();

            $table->string('status', 20)->default('draft');

            $table->dateTime('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancellation_reason', 255)->nullable();

            $table->foreignId('replaced_by_attestation_id')->nullable()
                ->unique('uq_withholding_attestations_replaced_by')
                ->constrained('withholding_attestations')->restrictOnDelete();

            // SHA-256 of the issued PDF (00-core §13/§14).
            $table->char('document_hash', 64)->nullable();

            $table->dateTime('delivered_at')->nullable();
            $table->string('delivery_method', 10)->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('supplier_payment_id', 'ix_wa_payment');
            $table->index(['supplier_id', 'status'], 'ix_wa_supplier');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE withholding_attestations
              ADD CONSTRAINT ck_wa_source_xor CHECK (
                (supplier_payment_id IS NULL) <> (supplier_invoice_id IS NULL)
              ),
              ADD CONSTRAINT ck_wa_status CHECK (
                status IN ('draft', 'issued', 'cancelled', 'replaced')
              ),
              ADD CONSTRAINT ck_wa_delivery CHECK (
                delivery_method IS NULL OR delivery_method IN ('hand', 'email', 'post')
              ),
              ADD CONSTRAINT ck_wa_period CHECK (
                period_month BETWEEN 1 AND 12
              )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('withholding_attestations');
    }
};
