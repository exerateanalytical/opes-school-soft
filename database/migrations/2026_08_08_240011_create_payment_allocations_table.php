<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/04-fees.md §11.6 `payment_allocations` - line-level (A2),
 * append-only: a void/bounce sets `reversed_*`, never deletes (§11.4/§11.5),
 * so the history of what a payment was once believed to cover survives.
 *
 * The invoice FKs are added only when the invoice tables (built in a
 * parallel work package, migrations 240005+) exist at migrate time; the
 * columns are always present so allocation rows written before the FK
 * existed are identical in shape to ones written after.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->unsignedBigInteger('invoice_line_id')->nullable();
            $table->bigInteger('amount');
            $table->dateTime('allocated_at');
            $table->foreignId('allocated_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reversal_reason', 400)->nullable();
            $table->timestamps();

            $table->index('invoice_id');
            $table->index('invoice_line_id');
        });

        DB::statement(
            'ALTER TABLE payment_allocations ADD CONSTRAINT chk_pa_amount CHECK (amount > 0)'
        );

        // §11.6: one LIVE allocation row per (payment, line) - a top-up
        // increases the existing row - via the generated-column pattern so
        // reversed rows do not block a re-allocation.
        DB::statement(
            'ALTER TABLE payment_allocations ADD COLUMN live_line_key BIGINT UNSIGNED'
            .' GENERATED ALWAYS AS (CASE WHEN reversed_at IS NULL THEN invoice_line_id ELSE NULL END) STORED'
        );
        DB::statement(
            'ALTER TABLE payment_allocations ADD UNIQUE INDEX uq_pa_live_line (payment_id, live_line_key)'
        );

        if (Schema::hasTable('invoices')) {
            Schema::table('payment_allocations', function (Blueprint $table): void {
                $table->foreign('invoice_id')->references('id')->on('invoices')->restrictOnDelete();
            });
        }

        if (Schema::hasTable('invoice_lines')) {
            Schema::table('payment_allocations', function (Blueprint $table): void {
                $table->foreign('invoice_line_id')->references('id')->on('invoice_lines')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
