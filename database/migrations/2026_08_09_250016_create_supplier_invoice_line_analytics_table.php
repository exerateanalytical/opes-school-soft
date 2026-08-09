<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/03-tax-procurement.md §4.5 - the analytic split of a supplier
 * invoice line: pivot rows summing to `amount_ht` (02-accounting H),
 * mirroring `journal_entry_line_analytics` / `purchase_order_line_analytics`.
 *
 * `amount` carries the line-HT share; `share_bp` is 00-core §7.2 basis
 * points (1_000_000 = 100%). Conservation (Σ amount = line.amount_ht) is
 * enforced in-Action via Money::allocate, as with the other pivots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_invoice_line_analytics', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('supplier_invoice_line_id')->constrained('supplier_invoice_lines')->cascadeOnDelete();
            $table->foreignId('analytic_axis_id')->constrained('analytic_axes')->restrictOnDelete();
            $table->foreignId('analytic_value_id')->constrained('analytic_values')->restrictOnDelete();

            $table->bigInteger('amount');
            $table->bigInteger('share_bp');

            $table->timestamps();

            $table->unique(
                ['supplier_invoice_line_id', 'analytic_axis_id', 'analytic_value_id'],
                'uq_sila',
            );
            $table->index(['analytic_axis_id', 'analytic_value_id'], 'ix_sila_value');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoice_line_analytics');
    }
};
