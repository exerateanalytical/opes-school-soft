<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 (docs/plans/phase-12-13.md 12.1): the Communication outbox.
 *
 * 00-core: messaging "degrades to a queued outbox, never a blocking error" -
 * a school with no SMS gateway or no internet still records that a fee
 * reminder SHOULD go out, and a later-configured channel drains the queue.
 * Nothing in the product may fail because a message could not leave.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->enum('channel', ['sms', 'email', 'push', 'whatsapp']);

            // Phone number (E.164) or e-mail address, as the channel demands.
            $table->string('recipient', 160);

            // Who the message is ABOUT (a Guardian, a StaffMember) - not who
            // receives it; the recipient string above is the delivery truth.
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            // The template it was rendered from, when it was; ad-hoc messages
            // leave it null. RESTRICT - a template with sent history is not
            // deletable, it is deactivated.
            $table->unsignedBigInteger('message_template_id')->nullable();

            $table->enum('language', ['en', 'fr']);

            // Subject line (e-mail only) and the rendered body.
            $table->string('subject_line', 200)->nullable();
            $table->text('body');

            // Channel-specific extras (push data payload, template variable
            // values kept for audit).
            $table->json('payload')->nullable();

            // `disabled` = the channel is not configured on this instance;
            // the row exists so the office can see what WOULD have gone out
            // and resend once a gateway is set up.
            $table->enum('status', ['queued', 'sent', 'failed', 'disabled'])
                ->default('queued');

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('queued_at');
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->string('failure_reason', 255)->nullable();

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->index(['status', 'queued_at'], 'idx_outbox_status_queued');
            $table->index(['subject_type', 'subject_id'], 'idx_outbox_subject');

            // fk_outbox_template is added by 300005 - message_templates does
            // not exist yet at this point in the run and the filenames are
            // pre-assigned (parallel-agent convention), so the constraint
            // follows the table it references.
            $table->foreign('created_by', 'fk_outbox_created_by')
                ->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
