<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 (docs/plans/phase-12-13.md 12.1): bilingual message templates for
 * fee reminders, publication notices and the like.
 *
 * Both language bodies are mandatory: Cameroon is bilingual and the language
 * a message goes out in is the GUARDIAN's (guardians.language), decided at
 * send time - a template missing one language would silently anglicise half
 * the parent body.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // Case-sensitive identifier, 00-core 4: 'FEE-REMINDER' and
            // 'fee-reminder' must not collide into one counter of truth.
            $table->string('code', 40)->collation('utf8mb4_0900_as_cs')->unique();

            $table->string('name', 160);
            $table->string('name_fr', 160);

            $table->enum('channel', ['sms', 'email', 'push', 'whatsapp']);

            // Subject lines are e-mail only; SMS/push/WhatsApp ignore them.
            $table->string('subject_en', 200)->nullable();
            $table->string('subject_fr', 200)->nullable();

            $table->text('body_en');
            $table->text('body_fr');

            // The placeholder names the bodies may use ({student_name},
            // {amount_due}, ...) - declared so the renderer can validate a
            // template at save instead of failing at send.
            $table->json('variables')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });

        // The outbox's template FK, deferred from 300004: message_templates
        // did not exist yet when outbox_messages was created, and the
        // filenames are pre-assigned (parallel-agent convention).
        Schema::table('outbox_messages', function (Blueprint $table): void {
            $table->foreign('message_template_id', 'fk_outbox_template')
                ->references('id')->on('message_templates')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('outbox_messages', function (Blueprint $table): void {
            $table->dropForeign('fk_outbox_template');
        });

        Schema::dropIfExists('message_templates');
    }
};
