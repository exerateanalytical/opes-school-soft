<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/02-accounting.md §12.2 - JournalEntryLineAnalytic, the pivot.
 *
 * Attaches dimension members to a line WITHOUT touching the line itself:
 * like lettering, analytics may be allocated after posting. L3's line-lock
 * trigger guards `journal_entry_lines` - the line's own financial columns
 * stay frozen - and deliberately does not extend to this child table.
 *
 * `amount` is BIGINT SIGNED, carrying the sign of (debit - credit) so the
 * §12.4 AnalyticGeneralReconciliation ("Σ amount signed" vs GL
 * "Σ(debit - credit)") is a straight SUM. AN-1's magnitude equality then
 * holds as |Σ amount| = debit + credit, with uniform sign per line.
 *
 * AN-1/AN-2 are enforced in-Action (AllocateLineAnalytics, by construction
 * via Money's largest-remainder Allocator) and re-asserted by
 * VerifyAnalyticAllocations (the nightly job's body).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entry_line_analytics', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('journal_entry_line_id')->constrained('journal_entry_lines')->restrictOnDelete();
            $table->foreignId('analytic_axis_id')->constrained('analytic_axes')->restrictOnDelete();
            $table->foreignId('analytic_value_id')->constrained('analytic_values')->restrictOnDelete();

            $table->bigInteger('amount');

            // Basis points per 00-core §7.2: 1_000_000 = 100%.
            $table->bigInteger('share_bp');

            $table->timestamps();

            $table->unique(
                ['journal_entry_line_id', 'analytic_axis_id', 'analytic_value_id'],
                'uq_jela',
            );
            $table->index(['analytic_axis_id', 'analytic_value_id'], 'ix_jela_value');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_line_analytics');
    }
};
