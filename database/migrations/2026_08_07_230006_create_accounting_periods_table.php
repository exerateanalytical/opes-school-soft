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
        Schema::create('accounting_periods', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->restrictOnDelete();

            // 02-accounting §5.1: a calendar month. `period_month` is the
            // first day of the month, kept alongside starts_on/ends_on so a
            // month can be looked up without recomputing it.
            $table->date('period_month');
            $table->date('starts_on');
            $table->date('ends_on');

            $table->enum('status', ['open', 'soft_locked', 'hard_locked'])->default('open');

            $table->timestamp('soft_locked_at')->nullable();
            $table->foreignId('soft_locked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('hard_locked_at')->nullable();
            $table->foreignId('hard_locked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('unlock_reason', 500)->nullable();

            // AUDCIF Art. 22, §5.3: the forced quarterly closure.
            $table->boolean('is_quarter_end')->default(false);
            $table->date('forced_closure_due_on')->nullable();

            $table->timestamps();

            // §5.1: "Periods are generated for all 12 months when a
            // FiscalYear is created, so no month is ever missing."
            $table->unique(['fiscal_year_id', 'period_month']);
            $table->index('status');
        });

        DB::statement(
            'ALTER TABLE accounting_periods ADD CONSTRAINT chk_accounting_periods_dates CHECK (starts_on < ends_on)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
    }
};
