<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `whatsapp_delivery_logs` - one row per ATTEMPT to hand a message to Meta.
 *
 * The reason this table exists is a dispute, not a metric. "Nobody told me my
 * son was sent home" / "we never got the fee notice" is a conversation a
 * school has with a parent, sometimes in front of a board, and the outbox row
 * alone cannot settle it: the outbox says what the school DECIDED to send,
 * this says what Meta accepted and under which message id. The provider
 * message id is the part that matters - it is the only handle that can be
 * quoted back to Meta support.
 *
 * Attempts are recorded whatever the outcome, including refusals that never
 * left the building (an unconfigured channel, an unusable number). A log that
 * only recorded successes would make an entirely unwired instance look like
 * one that simply had nothing to say.
 *
 * `guardian_id` is intentionally NOT a foreign key: it is the same
 * subject_type/subject_id free-tag convention `outbox_messages` uses
 * (00-core 6.2 - Communication does not reach into Guardians' tables), and it
 * must survive the guardian record being reorganised. Deleting a guardian
 * must not delete the proof that they were notified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_delivery_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // Nullable: staff and ad-hoc test sends have no guardian.
            $table->unsignedBigInteger('guardian_id')->nullable();

            // Set when the send originated from the outbox, null for a direct
            // send (the admin screen's test message). No FK for the same
            // cross-module reason as guardian_id.
            $table->unsignedBigInteger('outbox_message_id')->nullable();

            // What we actually asked Meta to deliver to, E.164 WITHOUT the
            // leading `+`, exactly as it went on the wire - not the raw
            // string on the guardian record, which may since have been
            // edited. 24 matches guardians.phone.
            $table->string('recipient_phone', 24);

            // Meta has exactly two shapes that matter to a school.
            $table->enum('message_type', ['text', 'template']);

            // The approved template name and language, null for a text send.
            $table->string('template_name', 120)->nullable();
            $table->string('template_language', 16)->nullable();

            // Meta's `messages[0].id` (`wamid.HBg...`). Null when the attempt
            // never reached Meta or Meta refused it.
            $table->string('provider_message_id', 128)->nullable();

            // `refused` is the state that stops this log from lying: the
            // system declined BEFORE any network call (channel off, no
            // credentials, unusable number), so nothing was sent and no
            // credit was spent, which is a different fact from `failed`.
            $table->enum('status', ['sent', 'failed', 'refused']);

            // Meta's numeric error code, kept separate from the prose because
            // it is the searchable part (131047 = outside the 24h window and
            // no template, 190 = expired token).
            $table->integer('error_code')->nullable();
            $table->string('error_message', 500)->nullable();

            // The HTTP status Meta returned; null if the call never happened.
            $table->unsignedSmallInteger('http_status')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->index(['guardian_id', 'created_at'], 'idx_wa_logs_guardian_created');
            $table->index(['status', 'created_at'], 'idx_wa_logs_status_created');
            $table->index('provider_message_id', 'idx_wa_logs_provider_message_id');
            $table->index('outbox_message_id', 'idx_wa_logs_outbox_message');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_delivery_logs');
    }
};
