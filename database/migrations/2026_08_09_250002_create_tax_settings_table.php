<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/03-tax-procurement.md §5.4 / §6.3 / §12 items 8 & 15 - the two
 * tax-engine switches whose legally correct value is NEEDS VERIFICATION and
 * therefore ships UNSET and BLOCKING (00-core §16):
 *
 * - `withholding_recognition`: is withholding recognised on invoice or on
 *   payment? Drives which date selects the WithholdingRule version (§6.3).
 * - `prorata_rounding`: exact basis points, or rounded up to the next whole
 *   percent (the common francophone rule)? Drives ComputeVatProrata.
 *
 * NULL means "the accountant has not decided"; every Action that needs the
 * value refuses with a configure-with-your-accountant error rather than
 * assuming. Singleton, same CHECK (id = 1) discipline as fiscal_identities.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_settings', function (Blueprint $table): void {
            // Plain PK, not auto-increment: MySQL refuses a CHECK on an
            // auto-increment column, and the singleton CHECK is the point.
            $table->unsignedBigInteger('id')->primary();

            // on_invoice | on_payment. NULL = unconfigured (blocking).
            $table->string('withholding_recognition', 20)->nullable();

            // exact_bp | up_to_whole_percent. NULL = unconfigured (blocking).
            $table->string('prorata_rounding', 30)->nullable();

            $table->foreignId('confirmed_by')->nullable()
                ->constrained('users')->restrictOnDelete();
            $table->dateTime('confirmed_at')->nullable();

            $table->timestamps();
        });

        DB::statement(
            'ALTER TABLE tax_settings ADD CONSTRAINT chk_tax_settings_singleton CHECK (id = 1)'
        );
        DB::statement(
            'ALTER TABLE tax_settings ADD CONSTRAINT chk_tax_settings_recognition '
            ."CHECK (withholding_recognition IS NULL OR withholding_recognition IN ('on_invoice','on_payment'))"
        );
        DB::statement(
            'ALTER TABLE tax_settings ADD CONSTRAINT chk_tax_settings_prorata_rounding '
            ."CHECK (prorata_rounding IS NULL OR prorata_rounding IN ('exact_bp','up_to_whole_percent'))"
        );

        // NOTHING SEEDED - both values are NEEDS VERIFICATION.
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_settings');
    }
};
