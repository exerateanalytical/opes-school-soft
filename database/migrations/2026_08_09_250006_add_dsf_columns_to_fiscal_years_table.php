<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/03-tax-procurement.md §7.5 - completes the DSF column set on
 * fiscal_years. `dsf_filed_at` and `dsf_reference` already exist (created
 * ahead of need by 2026_08_07_230005); this adds:
 *
 * - `dsf_declaration_id`: plain BIGINT here, NOT an FK - tax_declarations
 *   is created by migration 250025 (Agent F5) whose timestamp sorts LATER,
 *   so the constraint is added there-after in 250026. Do not "fix" the
 *   ordering (phase-05 plan, risk 1 discipline).
 * - `dsf_filed_by`: who recorded the filing. RESTRICT like every actor FK.
 *
 * Once dsf_filed_at is set, ReopenFiscalYear must refuse unconditionally
 * (§11.10) - that guard is Agent F5's single cross-scope touch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table): void {
            $table->unsignedBigInteger('dsf_declaration_id')->nullable()->after('dsf_reference');
            $table->foreignId('dsf_filed_by')->nullable()->after('dsf_declaration_id')
                ->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('dsf_filed_by');
            $table->dropColumn('dsf_declaration_id');
        });
    }
};
