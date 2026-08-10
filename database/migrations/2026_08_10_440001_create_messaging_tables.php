<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * In-platform messaging: teacher <-> parent, teacher <-> teacher, and staff
 * announcements to a class/section.
 *
 * This is deliberately NOT `outbox_messages`. That table is a one-way
 * SMS/email dispatch queue (channel, recipient, sent_at) with no concept of
 * a reply or a participant list - it delivers a document OUT of the
 * product. This is a real two-way conversation that stays INSIDE it: no
 * SMTP, no SMS gateway, nothing to configure to start using it.
 *
 * `message_threads` is polymorphic on `subject_type`/`subject_id` so a
 * thread can be free-standing (a general conversation between two users) or
 * anchored to something - a student, so a thread naturally shows on that
 * student's guardian portal and staff record alike.
 *
 * `message_thread_participants` carries `last_read_message_id` PER
 * PARTICIPANT, which is what makes an unread badge possible without
 * scanning every message on every render.
 *
 * A message ID watermark, not a `last_read_at` TIMESTAMP: MySQL DATETIME is
 * second-granular, and a reply landing in the same second as the read stamp
 * that preceded it compares EQUAL, not greater - so `created_at > cutoff`
 * silently reports 0 unread for a message that just arrived. `messages.id`
 * is a strictly increasing auto-increment PK with no such collision.
 * `last_read_at` is kept alongside purely for display ("read 2 minutes
 * ago"), never for the unread computation.
 *
 * Table order below is `messages` BEFORE `message_thread_participants`,
 * because the participant row's read-watermark FK needs `messages` to
 * already exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_threads', function (Blueprint $table): void {
            $table->id();

            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->string('title', 255);
            $table->string('kind', 20)->default('conversation');

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('last_message_at')->nullable();
            $table->boolean('is_archived')->default(false);

            $table->timestamps();

            $table->index(['subject_type', 'subject_id'], 'ix_message_threads_subject');
            $table->index('last_message_at', 'ix_message_threads_last_message');
        });

        DB::statement(
            "ALTER TABLE message_threads ADD CONSTRAINT ck_message_threads_kind "
            ."CHECK (kind IN ('conversation','announcement'))"
        );

        Schema::create('messages', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('message_thread_id')->constrained('message_threads')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();

            $table->text('body');
            $table->boolean('is_system')->default(false);

            $table->timestamps();

            $table->index(['message_thread_id', 'created_at'], 'ix_messages_thread_created');
        });

        Schema::create('message_thread_participants', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('message_thread_id')->constrained('message_threads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            $table->dateTime('last_read_at')->nullable();
            $table->foreignId('last_read_message_id')->nullable()
                ->constrained('messages')->nullOnDelete();
            $table->boolean('is_muted')->default(false);
            $table->dateTime('added_at');
            $table->dateTime('removed_at')->nullable();

            $table->timestamps();

            $table->unique(['message_thread_id', 'user_id'], 'uq_message_participants_thread_user');
            $table->index('user_id', 'ix_message_participants_user');
        });

        Schema::create('announcement_recipients', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('message_thread_id')->constrained('message_threads')->cascadeOnDelete();

            // A scope, not a user list: "class group 4" or "school section 2"
            // or "everyone". Individual recipients are resolved into
            // message_thread_participants at send time, so an announcement to
            // 400 parents does not require 400 rows here.
            $table->string('scope_type', 40);
            $table->unsignedBigInteger('scope_id')->nullable();

            $table->timestamps();

            $table->index(['scope_type', 'scope_id'], 'ix_announcement_recipients_scope');
        });

        DB::statement(
            "ALTER TABLE announcement_recipients ADD CONSTRAINT ck_announcement_recipients_scope "
            ."CHECK (scope_type IN ('class_group','school_section','all_guardians','all_staff'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_recipients');
        Schema::dropIfExists('message_thread_participants');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('message_threads');
    }
};
