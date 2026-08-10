<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Three subsystems, three tables:
 *
 *  - `notifications` - the platform's own in-app + push notification engine.
 *    Not Laravel's stock `database` notification channel (which this app
 *    does not use): a real table with `read_at`, a `kind` for filtering, and
 *    an optional polymorphic subject so a notification can deep-link to the
 *    record it is about.
 *
 *  - `push_subscriptions` - one row per browser/device a user has granted
 *    Web Push permission on. `endpoint` is the unique key a browser push
 *    service gives back; `p256dh`/`auth` are the keys SendPushNotification
 *    encrypts the payload against (RFC 8291).
 *
 *  - `form_drafts` - the autosave + hold mechanism behind every modal form.
 *    `status` distinguishes an in-progress autosave (`draft`, silent,
 *    overwritten on every keystroke-debounce) from a deliberate `held` one
 *    (the user clicked Hold, and it now belongs on their "unfinished work"
 *    list and can trigger a notification if it sits untouched). `form_key`
 *    plus the polymorphic `subject_type`/`subject_id` is what lets a draft
 *    resume the RIGHT record - editing invoice #40 must never resume as
 *    invoice #12's stale draft.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 60);
            $table->string('title', 255);
            $table->text('body')->nullable();
            $table->string('url', 500)->nullable();

            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->dateTime('read_at')->nullable();
            $table->dateTime('pushed_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'read_at'], 'ix_notifications_user_unread');
            $table->index(['subject_type', 'subject_id'], 'ix_notifications_subject');
        });

        Schema::create('push_subscriptions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('endpoint', 500);
            $table->string('p256dh', 255);
            $table->string('auth', 255);
            $table->string('user_agent', 255)->nullable();

            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('last_failed_at')->nullable();
            $table->string('last_failure_reason', 255)->nullable();

            $table->timestamps();

            // A browser sends the SAME endpoint again on re-subscribe; this
            // is what makes SubscribeToPush idempotent rather than piling up
            // duplicate rows for one device.
            $table->unique('endpoint', 'uq_push_subscriptions_endpoint');
            $table->index('user_id', 'ix_push_subscriptions_user');
        });

        Schema::create('form_drafts', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('form_key', 120);

            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->json('payload');
            $table->string('status', 10)->default('draft');

            $table->dateTime('held_at')->nullable();
            $table->string('hold_label', 255)->nullable();

            $table->timestamps();

            // One live draft per user per form per subject. MySQL treats
            // every NULL in a UNIQUE key as distinct from every other NULL,
            // so a brand-new record's draft (subject_type AND subject_id
            // both NULL) would never collide with itself under a plain
            // UNIQUE - two "new student" drafts for the same user could pile
            // up forever. Both columns are coalesced into one deterministic
            // generated column, the same NULL-in-UNIQUE sentinel pattern
            // this codebase already uses for budget_lines.analytic_key.
            $table->string('subject_key', 140)->storedAs(
                "CONCAT(COALESCE(`subject_type`, ''), ':', COALESCE(`subject_id`, 0))"
            );

            $table->unique(
                ['user_id', 'form_key', 'subject_key'],
                'uq_form_drafts_user_form_subject'
            );

            $table->index(['user_id', 'status'], 'ix_form_drafts_user_status');
        });

        DB::statement(
            "ALTER TABLE form_drafts ADD CONSTRAINT ck_form_drafts_status "
            ."CHECK (status IN ('draft','held'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('form_drafts');
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('notifications');
    }
};
