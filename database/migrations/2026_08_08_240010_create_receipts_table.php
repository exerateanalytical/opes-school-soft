<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The issuance record of the printed receipt document (docs/specs/04-fees.md
 * §14, 00-core §14 DocumentPrintLog discipline, scoped to Fees).
 *
 * `payments.receipt_no` is the legal number; a `receipts` row is one
 * ISSUANCE of the document carrying it. `ReissueReceipt` appends a new row
 * with the next `copy_no` (marked DUPLICATA on print) - the original row is
 * never edited, and voiding the payment flags every issuance so a reprint
 * of a voided receipt is blocked and every printed copy is discoverable
 * (§11.5 void cascade step 7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            // Snapshot of payments.receipt_no at issuance - the document is
            // authoritative about the number it was printed with.
            $table->string('receipt_no', 40)->collation('utf8mb4_0900_as_cs');
            // 1 = original; 2+ = reissued duplicates.
            $table->unsignedSmallInteger('copy_no');
            $table->string('reissue_reason', 400)->nullable();
            $table->boolean('is_voided')->default(false);
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('issued_at');
            $table->timestamps();

            $table->unique(['payment_id', 'copy_no']);
            $table->index('receipt_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
