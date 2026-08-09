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
        // 04-fees.md §2.2 - the billable thing. RESTRICT on delete
        // (00-core 10.5): financial history references it forever.
        Schema::create('fee_items', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('code', 30)->collation('utf8mb4_0900_as_cs')->unique('uq_fee_items_code');

            $table->string('name', 160);
            $table->string('name_fr', 160);

            $table->foreignId('fee_category_id')
                ->constrained('fee_categories')
                ->restrictOnDelete();

            // C5. NOT NULL, no default - the developer must choose whether
            // this is the school's own revenue or money held for a third
            // party. Getting it wrong misstates turnover and the tax base.
            $table->enum('collection_basis', ['own_revenue', 'agent_for_third_party']);

            // NOT NULL iff collection_basis = agent_for_third_party (CHECK
            // below).
            $table->foreignId('third_party_fund_id')
                ->nullable()
                ->constrained('third_party_funds')
                ->restrictOnDelete();

            // NOT NULL iff collection_basis = own_revenue (CHECK below).
            $table->foreignId('revenue_account_id')
                ->nullable()
                ->constrained('chart_of_accounts')
                ->restrictOnDelete();

            // C4 - revenue cut-off.
            $table->enum('recognition_method', ['on_issue', 'straight_line_over_period', 'on_collection'])
                ->default('on_issue');

            // Deliberately WITHOUT an FK constraint: tax_codes belongs to
            // 03-tax-procurement, whose table has not landed yet. The FK is
            // wired when that phase's migration ships (subject_allocations
            // precedent for effective_*_period_id).
            $table->unsignedBigInteger('tax_code_id')->nullable();

            $table->boolean('is_refundable')->default(false);
            $table->boolean('is_mandatory')->default(true);

            $table->enum('default_recurrence', ['per_year', 'per_term', 'per_month', 'one_off']);

            $table->text('asset_or_service_note')->nullable();
            $table->boolean('is_archived')->default(false);

            $table->timestamps();
        });

        // §2.2 CHECK: the basis and its account are declared together or the
        // posting engine has nowhere to put the money.
        DB::statement(
            'ALTER TABLE fee_items ADD CONSTRAINT chk_fee_items_basis CHECK ( '
            ."(collection_basis = 'own_revenue' AND revenue_account_id IS NOT NULL AND third_party_fund_id IS NULL) "
            ."OR (collection_basis = 'agent_for_third_party' AND third_party_fund_id IS NOT NULL AND revenue_account_id IS NULL) )"
        );

        // §2.2.1 (H). Conjunctive: ALL rows must match for the item to apply.
        Schema::create('fee_item_audience_criteria', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // CASCADE: pure child of a config row, no financial history.
            $table->foreignId('fee_item_id')
                ->constrained('fee_items')
                ->cascadeOnDelete();

            $table->enum('dimension', [
                'enrollment_status', 'gender', 'boarding_status', 'transport_status',
                'stream', 'class_level', 'school_section', 'nationality', 'house',
            ]);
            $table->enum('operator', ['in', 'not_in']);
            $table->json('values_json');

            $table->timestamps();
        });

        // Same shape; DISJUNCTIVE: any matching row excludes the student.
        Schema::create('fee_item_exclusion_criteria', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('fee_item_id')
                ->constrained('fee_items')
                ->cascadeOnDelete();

            $table->enum('dimension', [
                'enrollment_status', 'gender', 'boarding_status', 'transport_status',
                'stream', 'class_level', 'school_section', 'nationality', 'house',
            ]);
            $table->enum('operator', ['in', 'not_in']);
            $table->json('values_json');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_item_exclusion_criteria');
        Schema::dropIfExists('fee_item_audience_criteria');
        Schema::dropIfExists('fee_items');
    }
};
