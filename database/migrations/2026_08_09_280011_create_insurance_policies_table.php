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
        // docs/plans/phase-10.md §3 row 11 (series renumbered to 2800xx per
        // docs/plans/OVERNIGHT-RUN.md); design doc §14 "Student insurance".
        // One table covers BOTH student group policies and asset policies
        // (cover_type discriminates) - the design doc says so explicitly.
        Schema::create('insurance_policies', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('provider', 160);

            // The insurer's reference. 00-core 4: identifier collation.
            $table->string('policy_no', 60)->collation('utf8mb4_0900_as_cs')->unique('uq_insurance_policies_policy_no');

            $table->enum('cover_type', ['student', 'asset']);

            // XAF per insured student (whole francs, the vehicle-log
            // cost_amount convention). NOT NULL iff cover_type = student
            // (CHECK below); an asset policy has no per-head premium.
            // NO billing happens here: the premium bills through the
            // linked FeeItem like any other fee (design §14) - a second
            // posting path would be a review-blocking defect.
            $table->bigInteger('premium_per_student')->nullable();

            $table->date('coverage_start');
            $table->date('coverage_end');

            $table->foreignId('academic_year_id')
                ->constrained('academic_years')
                ->restrictOnDelete();

            // Link to the Phase 9 asset register for asset cover.
            // Deliberately a bare nullable bigint with NO foreign key
            // constraint (phase-10 plan §1): the plan was cut while Phase 9
            // was unbuilt, and the FK is added by a follow-up migration once
            // both phases are merged, so neither phase's migrations
            // order-depend on the other's (vehicles.asset_id precedent).
            $table->unsignedBigInteger('asset_id')->nullable();

            // The FeeItem that bills the premium (design §14: "The premium
            // is a FeeItem, so it bills and posts like any other fee").
            // Nullable: an asset policy never bills students, and a student
            // policy may be configured before its fee item exists.
            $table->foreignId('fee_item_id')
                ->nullable()
                ->constrained('fee_items')
                ->restrictOnDelete();

            $table->enum('status', ['active', 'expired', 'cancelled'])
                ->default('active');

            $table->timestamps();

            $table->index(['academic_year_id', 'cover_type', 'status'], 'idx_insurance_policies_year_cover_status');
        });

        // A backwards coverage period would make "is this incident covered"
        // unanswerable.
        DB::statement(
            'ALTER TABLE insurance_policies ADD CONSTRAINT chk_insurance_policies_period '
            .'CHECK (coverage_end >= coverage_start)'
        );

        // A student policy without a premium cannot bill; an asset policy
        // with a per-head premium is a data-entry error.
        DB::statement(
            'ALTER TABLE insurance_policies ADD CONSTRAINT chk_insurance_policies_premium '
            ."CHECK ( (cover_type = 'student' AND premium_per_student IS NOT NULL AND premium_per_student >= 0 AND asset_id IS NULL) "
            ."OR (cover_type = 'asset' AND premium_per_student IS NULL AND fee_item_id IS NULL) )"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_policies');
    }
};
