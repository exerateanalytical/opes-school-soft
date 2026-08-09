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
        // docs/plans/phase-10.md §3 row 13 (series renumbered to 2800xx per
        // docs/plans/OVERNIGHT-RUN.md). Claim SETTLEMENT is a paper fact
        // only in Phase 10: the cash receipt for a settled claim is
        // deferred to the treasury phase (tracked debt, plan §7) - no
        // ledger write happens here, and none may be added outside
        // Accounting\Actions\PostFromEvent.
        Schema::create('insurance_claims', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('policy_id')
                ->constrained('insurance_policies')
                ->restrictOnDelete();

            // NULL for an asset-cover claim; set for a student claim so the
            // certificate holder is on record.
            $table->foreignId('student_insurance_id')
                ->nullable()
                ->constrained('student_insurances')
                ->restrictOnDelete();

            $table->date('incident_date');
            $table->text('description');

            // XAF whole francs (vehicle-log cost_amount convention).
            $table->bigInteger('amount_claimed');
            $table->bigInteger('amount_settled')->nullable();

            $table->enum('status', ['draft', 'submitted', 'settled', 'rejected'])
                ->default('draft');

            $table->date('settled_on')->nullable();

            $table->foreignId('recorded_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            $table->index(['policy_id', 'status'], 'idx_insurance_claims_policy_status');
        });

        DB::statement(
            'ALTER TABLE insurance_claims ADD CONSTRAINT chk_insurance_claims_amounts '
            .'CHECK (amount_claimed > 0 AND (amount_settled IS NULL OR amount_settled >= 0))'
        );

        // A settled claim must say how much and when; a rejected one is
        // dated too (settled_on doubles as the decision date). Anything not
        // yet decided carries neither.
        DB::statement(
            'ALTER TABLE insurance_claims ADD CONSTRAINT chk_insurance_claims_settlement '
            ."CHECK ( (status = 'settled' AND amount_settled IS NOT NULL AND settled_on IS NOT NULL) "
            ."OR (status = 'rejected' AND amount_settled IS NULL AND settled_on IS NOT NULL) "
            ."OR (status IN ('draft', 'submitted') AND amount_settled IS NULL AND settled_on IS NULL) )"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_claims');
    }
};
