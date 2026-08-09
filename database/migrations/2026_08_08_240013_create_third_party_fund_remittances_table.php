<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/04-fees.md §2.3, C5 - the remittance leg of agent-collected
 * money. The school holds APEE / exam-board money in class 47 (never class
 * 7); this table records each hand-over so the standing statement - opening
 * held, collected, remitted, closing held - can tie exactly to the 47xx GL
 * balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('third_party_fund_remittances', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('third_party_fund_id')
                ->constrained('third_party_funds')
                ->restrictOnDelete();

            $table->date('period_start');
            $table->date('period_end');

            // Σ receipts allocated to agent items in the window. SIGNED
            // BIGINT per Money's contract.
            $table->bigInteger('amount_collected');
            $table->bigInteger('amount_remitted');

            $table->date('remitted_on')->nullable();
            $table->string('method', 40)->nullable();
            $table->string('reference', 120)->nullable();

            $table->enum('status', ['draft', 'remitted', 'cancelled'])->default('draft');

            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();

            $table->foreignId('approved_by')->nullable()
                ->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();

            $table->timestamps();

            // UNIQUE(fund, period) where status <> cancelled -
            // generated-column pattern (00-core §10.1): MySQL treats every
            // NULL as distinct, so cancelled rows drop out of the key.
            $table->unsignedBigInteger('active_fund_key')->nullable()
                ->storedAs("CASE WHEN `status` <> 'cancelled' THEN `third_party_fund_id` END");

            $table->unique(
                ['active_fund_key', 'period_start', 'period_end'],
                'uq_tpf_remittances_active_period'
            );
        });

        DB::statement(
            'ALTER TABLE third_party_fund_remittances ADD CONSTRAINT chk_tpf_remittances_period '
            .'CHECK (period_end >= period_start)'
        );
        DB::statement(
            'ALTER TABLE third_party_fund_remittances ADD CONSTRAINT chk_tpf_remittances_amounts '
            .'CHECK (amount_collected >= 0 AND amount_remitted >= 0)'
        );
        // A remitted row must say when.
        DB::statement(
            'ALTER TABLE third_party_fund_remittances ADD CONSTRAINT chk_tpf_remittances_remitted_on '
            ."CHECK (status <> 'remitted' OR remitted_on IS NOT NULL)"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('third_party_fund_remittances');
    }
};
