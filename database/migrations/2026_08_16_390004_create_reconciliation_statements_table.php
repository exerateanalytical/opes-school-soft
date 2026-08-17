<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/02-accounting.md §13.3/§14 - the état de rapprochement,
 * registered as a hashed, immutable document the moment its session closes.
 *
 * Modelled on `statutory_books` (2026_08_10_410001): render → hash → store →
 * record, with the same `supersedes_id` chain so a regenerated statement
 * never overwrites the one it replaces.
 *
 * NOT a row in `statutory_books` itself: that table's own CHECK constraint
 * enumerates AUDCIF Art. 19's four named books (livre-journal, grand livre,
 * balance générale, livre d'inventaire) and requires a `fiscal_year_id`.
 * An état de rapprochement is neither of those four books, and it belongs to
 * a `reconciliation_sessions` row - one float, one month - not to a fiscal
 * year row in the same sense a statutory book does. A dedicated table with
 * its own shape is what actually fits, so this exists instead of a widened
 * CHECK on someone else's register.
 *
 * `reconciliation_session_id` is NOT unique: a session generates exactly one
 * statement AT CLOSE TIME under the current Action, but the chain design
 * (like `statutory_books`) allows a later regeneration to supersede an
 * earlier one without a schema change, and RESTRICT on both FKs means
 * neither a session nor a superseded statement can be pulled out from under
 * what points at it.
 *
 * Retention: Immutable10Year (§15, AUDCIF Art. 24) below, same as every
 * other accounting record - no SoftDeletes, no cascade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_statements', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('reconciliation_session_id')
                ->constrained('reconciliation_sessions')->restrictOnDelete();

            $table->dateTime('generated_at');
            $table->foreignId('generated_by')->constrained('users')->restrictOnDelete();

            $table->string('file_path', 500);
            $table->char('sha256', 64);

            $table->foreignId('supersedes_id')->nullable()
                ->constrained('reconciliation_statements')->restrictOnDelete();

            $table->timestamps();

            $table->index('reconciliation_session_id', 'ix_reconciliation_statements_session');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_statements');
    }
};
