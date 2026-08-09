<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/03-tax-procurement.md §3.1 - the supplier master record.
 *
 * The payables fraud vector this table exists to close is the duplicate
 * vendor (§3.2): `niu` is UNIQUE-where-not-null at the DATABASE, and the
 * encrypted bank / mobile-money identifiers each carry a deterministic
 * blind-index companion (00-core §9.5, the Guardian::blindIndexFor pattern)
 * so an exact account-number duplicate is detectable without ever indexing
 * plaintext.
 *
 * Deletion is RESTRICT (§9): a supplier is archived (`is_archived`), never
 * deleted - SoftDeletes would permanently burn the unique `code`.
 *
 * `withholding_profile_id` targets F1's `withholding_profiles` (250003); the
 * FK is added only when that table exists at migrate time (parallel work
 * packages - see 250007 for the full rationale). On the integrated database
 * the constraint is always present.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // FRN/000123, allocated from the row-locked `FRN` sequence inside
            // the saving transaction (00-core §12, gaps permitted) - never
            // max()+1.
            $table->string('code', 20)->collation('utf8mb4_0900_as_cs')->unique('uq_suppliers_code');
            $table->string('name', 200);
            $table->string('legal_form', 40)->nullable();
            $table->string('supplier_type', 20);

            // NIU: UNIQUE where not null - MySQL treats every NULL as
            // distinct, which is intended: a market trader has none. Absence
            // or inactivity changes the withholding rate (§6).
            $table->char('niu', 14)->collation('utf8mb4_0900_as_cs')->nullable()->unique('uq_suppliers_niu');
            $table->string('niu_status', 20)->default('unknown');
            $table->boolean('is_niu_verified')->default(false);
            $table->dateTime('niu_verified_at')->nullable();
            $table->foreignId('niu_verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('niu_verification_evidence', 255)->nullable();

            $table->string('regime_fiscal', 20)->nullable();
            $table->string('tax_centre_name', 160)->nullable();
            $table->string('rccm_number', 40)->collation('utf8mb4_0900_as_cs')->nullable();
            $table->boolean('has_contributor_card')->default(false);

            $table->unsignedBigInteger('withholding_profile_id')->nullable();
            $table->boolean('is_withholding_exempt')->default(false);
            $table->string('withholding_exemption_ref', 120)->nullable();
            $table->date('withholding_exemption_expires_on')->nullable();

            $table->foreignId('default_tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $table->foreignId('default_expense_account_id')->nullable()->constrained('chart_of_accounts')->restrictOnDelete();
            // Must be a collective account: 401 operating / 481 investment
            // (§3.3). A default only - the authoritative choice is per
            // document.
            $table->foreignId('payable_account_id')->constrained('chart_of_accounts')->restrictOnDelete();

            $table->smallInteger('payment_terms_days')->default(0);
            $table->char('currency', 3)->default('XAF');

            $table->string('contact_name', 120)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('phone_alt', 40)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('website', 160)->nullable();
            $table->string('address_line1', 200)->nullable();
            $table->string('address_line2', 200)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('region', 120)->nullable();
            $table->string('country', 2)->default('CM');

            // Encrypted at rest (00-core §9.5) - TEXT because Laravel's
            // `encrypted` cast produces a base64 payload far longer than the
            // plaintext. Non-deterministic, therefore unsearchable - which is
            // exactly why the _bidx companions exist beside them.
            $table->text('bank_name')->nullable();
            $table->text('bank_branch')->nullable();
            $table->text('bank_account_rib')->nullable();
            $table->char('bank_account_rib_bidx', 64)->nullable();

            $table->string('mobile_money_operator', 30)->nullable();
            $table->text('mobile_money_number')->nullable();
            $table->char('mobile_money_number_bidx', 64)->nullable();

            $table->unsignedBigInteger('category_id')->nullable();
            $table->foreign('category_id', 'fk_suppliers_category')
                ->references('id')->on('supplier_categories')->restrictOnDelete();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);
            $table->string('blocked_reason', 255)->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('name', 'ix_suppliers_name');
            $table->index(['is_active', 'is_archived'], 'ix_suppliers_active');
            $table->index('category_id', 'ix_suppliers_category');
            $table->index('bank_account_rib_bidx', 'ix_suppliers_rib_bidx');
            $table->index('mobile_money_number_bidx', 'ix_suppliers_momo_bidx');
            $table->index('withholding_profile_id', 'ix_suppliers_wh_profile');
        });

        if (Schema::hasTable('withholding_profiles')) {
            Schema::table('suppliers', function (Blueprint $table): void {
                $table->foreign('withholding_profile_id', 'fk_suppliers_wh_profile')
                    ->references('id')->on('withholding_profiles')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
