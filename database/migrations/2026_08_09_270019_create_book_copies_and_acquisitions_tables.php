<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/06-assets-stores.md §10.2 (book_copies) and §10.8
 * (book_acquisitions).
 *
 * Copies are the unit of circulation; "Available" on the screen is
 * COUNT(copies WHERE status='available'), derived, never a stored counter.
 *
 * Acquisitions: under the default `expensed` policy the batch posts NOTHING
 * here (the purchase expense is the Phase 5 supplier invoice's leg);
 * `acquisition_cost` per copy is retained for insurance / replacement-cost
 * purposes only. The `capitalised` policy is HARD-GATED on V17 (the
 * SYSCOHADA fonds documentaire account is unverified), so `asset_id` and
 * `journal_entry_id` ship nullable and stay NULL until it is confirmed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_acquisitions', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('reference', 60)->collation('utf8mb4_0900_as_cs')
                ->unique('uq_book_acquisitions_reference');

            $table->foreignId('supplier_id')->nullable()
                ->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('supplier_invoice_id')->nullable()
                ->constrained('supplier_invoices')->restrictOnDelete();

            $table->date('acquired_on');
            $table->enum('source', ['purchase', 'donation', 'transfer']);

            $table->bigInteger('total_cost')->default(0);
            $table->unsignedSmallInteger('copy_count')->default(0);

            // NULL under the `expensed` policy - see class docblock.
            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('asset_id')->nullable()
                ->constrained('assets')->restrictOnDelete();

            $table->text('notes')->nullable();

            $table->foreignId('recorded_by')
                ->constrained('users')->restrictOnDelete();

            $table->string('idempotency_key', 100)->nullable()
                ->unique('uq_book_acquisitions_idem');

            $table->timestamps();
        });

        DB::statement(
            'ALTER TABLE book_acquisitions ADD CONSTRAINT chk_book_acquisitions_cost CHECK (total_cost >= 0)'
        );

        Schema::create('book_copies', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('book_id')
                ->constrained('books')->restrictOnDelete();

            $table->string('accession_no', 30)->collation('utf8mb4_0900_as_cs')
                ->unique('uq_book_copies_accession');
            $table->string('barcode', 64)->collation('utf8mb4_0900_as_cs')
                ->unique('uq_book_copies_barcode');

            $table->foreignId('shelf_location_id')
                ->constrained('shelf_locations')->restrictOnDelete();

            $table->foreignId('acquisition_id')->nullable()
                ->constrained('book_acquisitions')->restrictOnDelete();

            $table->date('acquired_on')->nullable();

            // Insurance / replacement-cost record only under `expensed`.
            $table->bigInteger('acquisition_cost')->default(0);

            $table->enum('condition', ['new', 'good', 'fair', 'poor'])->default('good');

            $table->enum('status', [
                'available', 'issued', 'reserved', 'lost', 'damaged',
                'withdrawn', 'in_repair',
            ])->default('available');

            $table->date('withdrawn_on')->nullable();
            $table->string('withdrawal_reason', 255)->nullable();

            $table->timestamps();

            $table->index(['book_id', 'status'], 'ix_book_copies_book_status');
        });

        DB::statement(
            'ALTER TABLE book_copies ADD CONSTRAINT chk_book_copies_cost CHECK (acquisition_cost >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('book_copies');
        Schema::dropIfExists('book_acquisitions');
    }
};
