<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The school as an EMPLOYER (docs/specs/05-hr-payroll.md 3.1) - distinct from
 * SchoolProfile (identity/branding) and from the fiscal identity owned by
 * 03-tax-procurement.md.
 *
 * Effective-dated because a CNPS regime reclassification or risk-class change
 * applies FROM a date and must never rewrite prior payslips. The regime and
 * risk class drive every employer contribution the school pays (defects N2,
 * H2), which is why the CNPS notification letter that evidences them is a
 * NOT NULL reference and why the first-run wizard step is blocking.
 *
 * `proration_basis` and `ceiling_prorates_partial_month` are deliberately
 * NULL until the customer decides (2.4): calendar days, a 30-day month and
 * working days give three different answers, and a run containing any partial
 * month fails preflight while they are NULL. There is no default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employer_profiles', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('cnps_employer_number', 32)->collation('utf8mb4_0900_as_cs');
            $table->string('dipe_number', 32)->collation('utf8mb4_0900_as_cs');

            // Mirrors SchoolProfile.niu; the configure Action validates them
            // equal at save.
            $table->string('niu', 32)->collation('utf8mb4_0900_as_cs');

            // DGE / CIME / CDI - drives the DSF deadline (03-tax).
            $table->string('dgi_centre', 64)->nullable();

            $table->unsignedBigInteger('tdl_commune_id');
            $table->foreign('tdl_commune_id')->references('id')->on('communes')->restrictOnDelete();

            // Drives which PF rate row resolves (defect N2: 3.70% for
            // personnel de l'enseignement prive, not the 7% regime general).
            $table->enum('cnps_regime', ['general', 'agricole', 'enseignement_prive']);

            // CNPS classifies the EMPLOYER's establishment (H2); a per-staff
            // override exists on the contract, permission-gated.
            $table->string('rp_risk_class', 8);

            // NOT NULL and no FK: the notification letter that evidences the
            // two columns above is mandatory, but the `documents` table
            // belongs to Phase 13 - the constraint is added there.
            $table->unsignedBigInteger('cnps_notification_document_id');
            $table->string('cnps_notification_reference', 64);

            // NULL until configured; blocks any run containing a partial
            // month (preflight check 3).
            $table->enum('proration_basis', ['calendar_days', 'thirty_day_month', 'working_days'])->nullable();
            $table->boolean('ceiling_prorates_partial_month')->nullable();

            // 6.5: YTD-cumulative is the default engine mode (H10);
            // annualised exists for the flat-pay equivalence property.
            $table->enum('irpp_mode', ['ytd_cumulative', 'annualised'])->default('ytd_cumulative');

            $table->date('effective_from')->unique();
            // Exclusive, per every effective-dated table in this codebase.
            $table->date('effective_to')->nullable();

            $table->unsignedBigInteger('created_by');
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();

            $table->timestamps();
        });

        DB::statement(
            'ALTER TABLE employer_profiles ADD CONSTRAINT ck_employer_profiles_dates CHECK (effective_to IS NULL OR effective_to > effective_from)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('employer_profiles');
    }
};
