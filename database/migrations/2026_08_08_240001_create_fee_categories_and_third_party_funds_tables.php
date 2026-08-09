<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 04-fees.md §2.1. Groups fee items for the "Income by Category"
        // dashboard panel and the Fee Statement grouping.
        Schema::create('fee_categories', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // 00-core 4: identifier collation - accent/case sensitive.
            $table->string('code', 30)->collation('utf8mb4_0900_as_cs')->unique('uq_fee_categories_code');

            $table->string('name', 120);
            $table->string('name_fr', 120);
            $table->smallInteger('display_order')->default(0);

            // Archive flag, not SoftDeletes (00-core 10.5).
            $table->boolean('is_archived')->default(false);

            $table->timestamps();
        });

        // 04-fees.md §2.3, C5. APEE and exam-board money is collected AS AN
        // AGENT: booking it in class 7 overstates turnover and taxes money
        // that is not the school's. The liability account (class 47) SHIPS
        // UNSEEDED - the exact SYSCOHADA subdivision is NEEDS VERIFICATION
        // (§23 item 1), so the accountant selects it in the setup wizard and
        // agent fee items are blocked until then (00-core §16 gate).
        Schema::create('third_party_funds', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('code', 30)->collation('utf8mb4_0900_as_cs')->unique('uq_third_party_funds_code');

            $table->string('name', 160);
            $table->string('name_fr', 160);

            $table->enum('beneficiary_type', ['apee', 'exam_board', 'ministry', 'insurer', 'other']);
            $table->string('beneficiary_name', 200);
            $table->string('beneficiary_niu', 30)->nullable();

            // Must be a class 47 account - enforced by an Action-level
            // validator reading `code LIKE '47%'` (§2.3), because a CHECK
            // cannot subquery in MySQL 8.
            $table->foreignId('liability_account_id')
                ->constrained('chart_of_accounts')
                ->restrictOnDelete();

            $table->enum('remittance_frequency', ['on_demand', 'monthly', 'termly', 'annual']);
            $table->unsignedTinyInteger('remittance_due_day')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('third_party_funds');
        Schema::dropIfExists('fee_categories');
    }
};
