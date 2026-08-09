<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // docs/specs/06-assets-stores.md §7.8 - ONE JournalEntry per issue
        // header, not per line: a 12-line issue is one piece.
        Schema::create('stock_issues', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('issue_no', 30)->collation('utf8mb4_0900_as_cs')
                ->unique('uq_stock_issues_no');

            $table->foreignId('store_location_id')
                ->constrained('store_locations')
                ->restrictOnDelete();

            $table->foreignId('issued_to_staff_id')->nullable()
                ->constrained('staff_members')
                ->restrictOnDelete();

            $table->foreignId('store_requisition_id')->nullable()
                ->constrained('store_requisitions')
                ->restrictOnDelete();

            $table->date('issued_on');

            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')
                ->restrictOnDelete();

            $table->foreignId('fiscal_year_id')
                ->constrained('fiscal_years')
                ->restrictOnDelete();
            $table->foreignId('academic_year_id')
                ->constrained('academic_years')
                ->restrictOnDelete();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('idempotency_key', 64)->collation('utf8mb4_0900_as_cs')
                ->nullable()->unique('uq_stock_issues_idem');

            $table->text('notes')->nullable();

            $table->timestamps();
        });

        Schema::create('stock_issue_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('stock_issue_id')
                ->constrained('stock_issues')
                ->restrictOnDelete();

            $table->foreignId('item_id')
                ->constrained('items')
                ->restrictOnDelete();

            $table->decimal('quantity', 14, 3);

            // §7.1 issue_cost snapshot - authoritative for this line.
            $table->bigInteger('issue_cost');

            $table->foreignId('stock_movement_id')->nullable()
                ->constrained('stock_movements')
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique(['stock_issue_id', 'item_id'], 'uq_stock_issue_lines_item');
        });

        // §7.9 - two movements per line (transfer_out, transfer_in) at the
        // SENDING location's derived cost. A transfer between two locations
        // mapping to the same stock account is NOT a ledger event; the
        // Action posts only on a stock-account difference.
        Schema::create('stock_transfers', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('transfer_no', 30)->collation('utf8mb4_0900_as_cs')
                ->unique('uq_stock_transfers_no');

            $table->foreignId('from_location_id')
                ->constrained('store_locations')
                ->restrictOnDelete();
            $table->foreignId('to_location_id')
                ->constrained('store_locations')
                ->restrictOnDelete();

            $table->enum('status', ['draft', 'in_transit', 'received', 'cancelled'])
                ->default('received');

            $table->date('transferred_on');

            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')
                ->restrictOnDelete();

            $table->foreignId('fiscal_year_id')
                ->constrained('fiscal_years')
                ->restrictOnDelete();
            $table->foreignId('academic_year_id')
                ->constrained('academic_years')
                ->restrictOnDelete();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('idempotency_key', 64)->collation('utf8mb4_0900_as_cs')
                ->nullable()->unique('uq_stock_transfers_idem');

            $table->text('notes')->nullable();

            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE stock_transfers
            ADD CONSTRAINT chk_stock_transfers_distinct CHECK (from_location_id <> to_location_id)
        SQL);

        Schema::create('stock_transfer_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('stock_transfer_id')
                ->constrained('stock_transfers')
                ->restrictOnDelete();

            $table->foreignId('item_id')
                ->constrained('items')
                ->restrictOnDelete();

            $table->decimal('quantity', 14, 3);

            // Sending location's derived cost, snapshotted.
            $table->bigInteger('transfer_cost');

            $table->foreignId('out_movement_id')->nullable()
                ->constrained('stock_movements')
                ->restrictOnDelete();
            $table->foreignId('in_movement_id')->nullable()
                ->constrained('stock_movements')
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique(['stock_transfer_id', 'item_id'], 'uq_stock_transfer_lines_item');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stock_issue_lines');
        Schema::dropIfExists('stock_issues');
    }
};
