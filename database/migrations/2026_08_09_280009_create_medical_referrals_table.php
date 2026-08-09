<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // docs/plans/phase-10.md §3 row 9 (series renumbered to 2800xx per
        // docs/plans/OVERNIGHT-RUN.md). A referral out of the school sick bay
        // to an external facility, always anchored to the consultation that
        // prompted it. A referral is OPEN until followed_up_at is set
        // (CloseReferral); no separate status column - the timestamp is the
        // state, so the two can never disagree.
        Schema::create('medical_referrals', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('consultation_id')->constrained('medical_consultations')->restrictOnDelete();

            // Facility / practitioner the child was sent to. Not clinical
            // narrative, so plain text - the WHY is.
            $table->string('referred_to', 160);

            // 00-core 9.5 encrypted at the model, StudentMedicalRecord.detail
            // pattern; TEXT for the ciphertext envelope.
            $table->text('reason');

            $table->date('referred_on');

            // Set by CloseReferral when the follow-up outcome is recorded.
            $table->timestamp('followed_up_at')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('referred_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();

            // The dashboard's "open referrals" count scans this.
            $table->index(['followed_up_at', 'referred_on'], 'idx_medical_referrals_open');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_referrals');
    }
};
