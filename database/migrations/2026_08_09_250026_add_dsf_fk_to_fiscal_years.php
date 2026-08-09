<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/03-tax-procurement.md §7.5 - completes migration 250006:
 * `fiscal_years.dsf_declaration_id` was created there as a plain BIGINT
 * because `tax_declarations` (250025) did not exist yet at that timestamp.
 * Now it does, so the FK RESTRICT + UNIQUE the spec asks for land here.
 * Do not "fix" the ordering by merging the two migrations (phase-05 plan,
 * risk 1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table): void {
            $table->unique('dsf_declaration_id', 'uq_fiscal_years_dsf_declaration');
            $table->foreign('dsf_declaration_id', 'fk_fiscal_years_dsf_declaration')
                ->references('id')->on('tax_declarations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table): void {
            $table->dropForeign('fk_fiscal_years_dsf_declaration');
            $table->dropUnique('uq_fiscal_years_dsf_declaration');
        });
    }
};
