<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 (docs/plans/phase-12-13.md 12.2): admin-issued portal invitation
 * codes for guardians and staff.
 *
 * 00-core 9.3 forbids assuming SMTP exists: activation is a CODE the office
 * hands over (printed slip, WhatsApp, over the counter), not an e-mailed
 * link. Only the SHA-256 hash of the code is stored - the plaintext appears
 * once on the issuing screen and is never persisted, so a database read
 * cannot impersonate a guardian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_invitations', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // Morph target: App\Modules\Guardians\Models\Guardian or
            // App\Modules\HR\Models\StaffMember. A string pair rather than
            // two FKs because the invitation flow is identical for both and
            // the issuing Action validates existence before insert.
            $table->string('subject_type', 60);
            $table->unsignedBigInteger('subject_id');

            // SHA-256 hex of the invitation code. Unique: two live
            // invitations may never share a code, or activation would be
            // ambiguous about which subject it binds.
            $table->char('code_hash', 64)->unique();

            // Codes are short-lived by design (the issuing Action sets this);
            // an expired code fails activation with the same generic message
            // as a wrong one.
            $table->dateTime('expires_at');

            // Consumed exactly once. used_by_user_id is the account the
            // activation created or linked - kept so "which invitation made
            // this account" survives after the code hash stops mattering.
            $table->dateTime('used_at')->nullable();
            $table->unsignedBigInteger('used_by_user_id')->nullable();

            // Revocation short of expiry (guardian lost custody, staff left).
            $table->dateTime('revoked_at')->nullable();

            $table->unsignedBigInteger('issued_by');
            $table->dateTime('issued_at');

            $table->timestamps();

            $table->index(['subject_type', 'subject_id'], 'idx_portal_invitations_subject');

            $table->foreign('issued_by', 'fk_portal_invitations_issued_by')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('used_by_user_id', 'fk_portal_invitations_used_by')
                ->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_invitations');
    }
};
