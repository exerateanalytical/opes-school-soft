<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/03-tax-procurement.md §4.7 - SupplierPaymentAllocation: which
 * invoices a payment settles, and by how much.
 *
 * `amount` is the TTC portion of the invoice this payment settles (under
 * on_payment recognition it INCLUDES the withheld portion - the payable is
 * debited gross, split between treasury and 447); `withholding_amount` is
 * the slice of `amount` settled by withholding rather than cash.
 *
 * UNIQUE(supplier_payment_id, supplier_invoice_id) per §4.7. A void never
 * deletes an allocation - it stamps `reversed_*`, mirroring 04-fees §11.5
 * (the rows must survive for the statement to show both halves).
 *
 * `lettering_id`/`letter_code` record the C10 lettering group when the
 * settlement fully letters the payable (§4.7's `letter_code` column).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payment_allocations', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('supplier_payment_id')->constrained('supplier_payments')->restrictOnDelete();
            $table->foreignId('supplier_invoice_id')->constrained('supplier_invoices')->restrictOnDelete();

            $table->bigInteger('amount');
            $table->bigInteger('withholding_amount')->default(0);

            $table->foreignId('lettering_id')->nullable()->constrained('letterings')->restrictOnDelete();
            $table->string('letter_code', 4)->nullable();

            $table->dateTime('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reversal_reason', 120)->nullable();

            $table->timestamps();

            $table->unique(['supplier_payment_id', 'supplier_invoice_id'], 'uq_spa_payment_invoice');
            $table->index('supplier_invoice_id', 'ix_spa_invoice');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE supplier_payment_allocations
              ADD CONSTRAINT ck_spa_amounts CHECK (
                amount > 0 AND withholding_amount >= 0 AND withholding_amount <= amount
              )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_allocations');
    }
};
