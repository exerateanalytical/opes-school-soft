<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/02-accounting.md §11.1 - `PostingRuleLine`. Ordered lines under
 * a PostingRule header; `iterates_over` is what makes N credit lines
 * possible, `is_balancing` (at most one per rule, enforced in
 * SavePostingRule) is what makes a rounding residual impossible
 * (00-core §7.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posting_rule_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('posting_rule_id')
                ->constrained('posting_rules')->restrictOnDelete();

            $table->unsignedSmallInteger('sequence');

            $table->enum('account_source', ['literal', 'payload_path', 'setting']);
            $table->string('account_code', 20)->nullable();   // when literal
            $table->string('account_path', 200)->nullable();  // when payload_path / setting

            $table->enum('sign', ['debit', 'credit', 'signed']);
            $table->text('amount_expression');

            $table->boolean('is_balancing')->default(false);

            $table->string('partner_source', 200)->nullable();
            $table->string('analytic_source', 200)->nullable();
            $table->string('tax_code_source', 200)->nullable();
            $table->string('due_date_source', 200)->nullable();

            // A payload collection path - one physical journal line per element.
            $table->string('iterates_over', 200)->nullable();

            $table->string('label_expression', 255);

            // A zero line violates L1, so zero-valued lines are dropped by default.
            $table->boolean('skip_if_zero')->default(true);

            $table->timestamps();

            $table->unique(['posting_rule_id', 'sequence'], 'uq_prl_sequence');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posting_rule_lines');
    }
};
