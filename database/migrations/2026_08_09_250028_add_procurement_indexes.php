<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/plans/phase-05.md migration 250028 - cross-cutting reporting
 * indexes for the payables/withholding screens:
 *
 * - `supplier_invoices(due_date, status)`: the aged-payables and
 *   due-this-week axes (§4.9 report on the due_date axis).
 * - `withholding_attestations(period_year, period_month, status)`: the
 *   monthly withholding declaration's reconciliation sweep (§7.3).
 *
 * The plan also listed `supplier_invoices(supplier_id, status)`, but F3's
 * 250015 already created it (`ix_supplier_invoices_supplier`) - repeating
 * it here would be a duplicate index, so it is deliberately omitted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table): void {
            $table->index(['due_date', 'status'], 'ix_supplier_invoices_due');
        });

        Schema::table('withholding_attestations', function (Blueprint $table): void {
            $table->index(['period_year', 'period_month', 'status'], 'ix_wa_period');
        });
    }

    public function down(): void
    {
        Schema::table('withholding_attestations', function (Blueprint $table): void {
            $table->dropIndex('ix_wa_period');
        });

        Schema::table('supplier_invoices', function (Blueprint $table): void {
            $table->dropIndex('ix_supplier_invoices_due');
        });
    }
};
