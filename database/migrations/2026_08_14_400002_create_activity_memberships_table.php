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
        Schema::create('activity_memberships', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('activity_id')->constrained('activities')->restrictOnDelete();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();

            $table->enum('role', ['member', 'captain', 'leader'])->default('member');

            $table->date('starts_on');
            // NULL while active; set when the membership ends (or when
            // CloseActivity ends every live membership in one transaction).
            $table->date('ends_on')->nullable();

            $table->enum('status', ['active', 'ended'])->default('active');

            // ── Guardian consent, excursions only (gap-analysis row 15,
            //    held as columns on the membership for the MVP). NULL on
            //    clubs/sports/events; 'pending' from enrolment on an
            //    excursion until RecordConsent stores the decision, the
            //    deciding guardian, who keyed it and when. ────────────────
            $table->enum('consent_status', ['pending', 'granted', 'declined'])->nullable();
            $table->foreignId('consent_guardian_id')->nullable()->constrained('guardians')->restrictOnDelete();
            $table->foreignId('consent_recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('consent_recorded_at')->nullable();
            $table->string('consent_note', 500)->nullable();

            $table->foreignId('enrolled_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();

            // One ACTIVE membership per student per activity, enforced by
            // the schema rather than trusted to Action discipline - the
            // 00-core 10.1 NULL-unique generated-column stand-in for a
            // partial index (same trick as transport_allocations.active_key).
            // The column carries student_id only while the row is active,
            // so UNIQUE(activity_id, active_key) permits any number of
            // ended rows and at most one live one per pair.
            $table->unsignedBigInteger('active_key')
                ->nullable()
                ->storedAs("CASE WHEN `status` = 'active' THEN `student_id` END");

            $table->unique(['activity_id', 'active_key'], 'uq_activity_memberships_active');

            $table->index(['activity_id', 'status'], 'idx_activity_memberships_activity_status');
            $table->index(['student_id', 'status'], 'idx_activity_memberships_student_status');
        });

        DB::statement(
            'ALTER TABLE activity_memberships ADD CONSTRAINT chk_activity_memberships_range '
            .'CHECK (ends_on IS NULL OR ends_on >= starts_on)'
        );

        DB::statement(
            'ALTER TABLE activity_memberships ADD CONSTRAINT chk_activity_memberships_ended_dated '
            ."CHECK (status <> 'ended' OR ends_on IS NOT NULL)"
        );

        // A consent DECISION without its provenance would be unauditable:
        // granted/declined must carry the guardian who decided and the
        // timestamp it was recorded.
        DB::statement(
            'ALTER TABLE activity_memberships ADD CONSTRAINT chk_activity_memberships_consent_provenance '
            ."CHECK (consent_status IS NULL OR consent_status = 'pending' "
            .'OR (consent_guardian_id IS NOT NULL AND consent_recorded_at IS NOT NULL))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_memberships');
    }
};
