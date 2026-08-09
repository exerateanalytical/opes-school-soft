<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/06-assets-stores.md §2.4 - asset_maintenance_requests.
 *
 * Accounting rule: maintenance is expensed unless it extends useful life or
 * capacity, in which case it is a capitalisation. Closing a request as done
 * requires the operator's EXPLICIT expense-vs-capitalise choice plus a
 * justification (never inferred from amount) - the CHECK below makes a
 * choiceless 'done' unrepresentable. The money itself flows through the
 * Phase 5 supplier-invoice path; this row is the operational record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_maintenance_requests', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // NULL: a request may precede identification of the asset.
            $table->foreignId('asset_id')->nullable()
                ->constrained('assets')->restrictOnDelete();

            // The mockup links maintenance from the Inventory screen; F3's
            // items table (270012) has not landed when this runs - no FK.
            $table->unsignedBigInteger('inventory_item_id')->nullable();

            $table->string('title', 160);
            $table->text('description')->nullable();

            $table->foreignId('reported_by')
                ->constrained('users')->restrictOnDelete();
            $table->dateTime('reported_at');

            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            $table->enum('status', ['open', 'assigned', 'in_progress', 'done', 'cancelled'])->default('open');

            $table->foreignId('assigned_to_staff_id')->nullable()
                ->constrained('staff_members')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()
                ->constrained('suppliers')->restrictOnDelete();

            $table->bigInteger('estimated_cost')->nullable();
            $table->bigInteger('actual_cost')->nullable();

            // The explicit operator choice at close (§2.4 accounting rule).
            $table->enum('resolution', ['expense', 'capitalise'])->nullable();
            $table->text('resolution_justification')->nullable();

            $table->dateTime('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()
                ->constrained('users')->restrictOnDelete();

            $table->foreignId('supplier_invoice_id')->nullable()
                ->constrained('supplier_invoices')->restrictOnDelete();

            $table->string('idempotency_key', 100)->nullable()->unique('uq_maintenance_idem');

            $table->timestamps();

            $table->index(['status', 'priority'], 'ix_maintenance_status');
        });

        // A closed-as-done request carries its explicit accounting choice.
        DB::statement(
            'ALTER TABLE asset_maintenance_requests ADD CONSTRAINT chk_maintenance_resolution CHECK ( '
            ."status <> 'done' OR (resolution IS NOT NULL AND closed_at IS NOT NULL) )"
        );

        DB::statement(
            'ALTER TABLE asset_maintenance_requests ADD CONSTRAINT chk_maintenance_costs CHECK ( '
            .'(estimated_cost IS NULL OR estimated_cost >= 0) AND (actual_cost IS NULL OR actual_cost >= 0) )'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_maintenance_requests');
    }
};
