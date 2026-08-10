<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/02-accounting.md §2 + §11.3 - name the real place fee money
 * landed, the same way Procurement already does.
 *
 * `supplier_payments.treasury_account_id` (Phase 5) already points at a
 * `chart_of_accounts` class-5 row, so a supplier payment always says which
 * float it left. Fee `payments` never had the equivalent column, so every
 * receipt - cash, MTN MoMo, Orange Money, bank transfer - could only be
 * posted through one hardcoded account. That is the exact v1 defect
 * §11.3 documents: "cash in hand" silently absorbed bank and e-money, and
 * the MoMo float could never reconcile against the operator statement.
 *
 * This column is deliberately the SAME shape and the SAME target table as
 * the Procurement one (FK -> chart_of_accounts, not a new registry table):
 * in SYSCOHADA the treasury account IS the class-5 ledger account, and the
 * chart already carries `is_reconcilable`, `parent_id` and `depth`, so a
 * school distinguishes MTN from Orange by opening 5521/5522 sub-accounts
 * rather than by a parallel table that would fragment the model.
 *
 * NULLABLE, because rows written before this migration cannot know
 * retroactively which float they landed in - inventing one would corrupt
 * the very separation this column exists to create. New writes populate it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('treasury_account_id')->nullable()->after('payment_method')
                ->constrained('chart_of_accounts')->restrictOnDelete();

            $table->index('treasury_account_id', 'ix_payments_treasury');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign(['treasury_account_id']);
            $table->dropIndex('ix_payments_treasury');
            $table->dropColumn('treasury_account_id');
        });
    }
};
