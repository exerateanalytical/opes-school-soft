<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/04-fees.md §11.7 - attach a collection to the shift that took it.
 *
 * `expected_cash` at close is *opening_float + the session's own collections*.
 * That sentence is only computable if each payment says which session it
 * belongs to, so this is the column the whole close-out depends on.
 *
 * NULLABLE, and deliberately so - exactly the reasoning 320001 gave for
 * `treasury_account_id`: the payments already in the books predate sessions.
 * Back-filling them with an invented session id would manufacture a shift
 * that never happened and would make every historical close-out sheet a lie.
 * A null here reads as "collected before cash-desk sessions existed", which
 * is the truth.
 *
 * The §17.2 requirement ("collect is blocked for cash-method payments until
 * a session exists") is therefore enforced at the Cashier screen, NOT by a
 * NOT NULL constraint: RecordPayment is also called by the demo seeder, the
 * guardian portal and the test suite, and none of those has a till.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('cash_desk_session_id')->nullable()->after('treasury_account_id')
                ->constrained('cash_desk_sessions')->restrictOnDelete();

            $table->index('cash_desk_session_id', 'ix_payments_cash_desk_session');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign(['cash_desk_session_id']);
            $table->dropIndex('ix_payments_cash_desk_session');
            $table->dropColumn('cash_desk_session_id');
        });
    }
};
