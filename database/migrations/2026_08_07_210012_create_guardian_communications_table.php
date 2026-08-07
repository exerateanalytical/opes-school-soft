<?php

declare(strict_types=1);

use App\Modules\Guardians\Domain\CommunicationChannel;
use App\Modules\Guardians\Domain\CommunicationDirection;
use App\Modules\Guardians\Domain\DeliveryStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/07-students.md 7.8 - the per-guardian message log. Schema only in
 * Phase 2.
 *
 * Written by the Communication module, "owned for display here". That split is
 * why there is no Action in this module that inserts a row: the table belongs
 * to the guardian profile, the writes belong to whoever sent the message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guardian_communications', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('guardian_id');
            $table->unsignedBigInteger('student_id')->nullable();

            $table->enum('channel', CommunicationChannel::values());
            $table->enum('direction', CommunicationDirection::values());

            $table->string('subject', 255)->nullable();
            $table->text('body')->nullable();

            $table->timestamp('sent_at')->nullable();

            // `queued` is the NORMAL steady state on a LAN deployment with no
            // connectivity, not a fault - 7.8 requires the UI to say so rather
            // than render a wall of red. Hence the default, and hence `failed`
            // being a separate case with its own reason column.
            $table->enum('delivery_status', DeliveryStatus::values())->default(DeliveryStatus::Queued->value);
            $table->string('provider_reference', 160)->nullable();
            $table->string('failure_reason', 255)->nullable();

            // Polymorphic by design: the related object is an invoice, a report
            // card or a discipline case, each owned by a different module, so a
            // real FK would be a cross-module coupling 00-core 6.2 forbids.
            $table->string('related_type', 160)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();

            $table->unsignedBigInteger('actor_id')->nullable();

            $table->timestamps();

            $table->index(['guardian_id', 'sent_at'], 'idx_gc_guardian_sent');
            $table->index(['student_id', 'sent_at'], 'idx_gc_student_sent');
            // Serves both the "stuck in the outbox" screen and the 12-month
            // retention sweep (08-operations), which scans by status and age.
            $table->index(['delivery_status', 'created_at'], 'idx_gc_status_created');
            $table->index(['related_type', 'related_id'], 'idx_gc_related');

            $table->foreign('guardian_id', 'fk_gc_guardian')
                ->references('id')->on('guardians')
                ->restrictOnDelete()->restrictOnUpdate();

            $table->foreign('student_id', 'fk_gc_student')
                ->references('id')->on('students')
                ->restrictOnDelete()->restrictOnUpdate();

            $table->foreign('actor_id', 'fk_gc_actor')
                ->references('id')->on('users')
                ->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guardian_communications');
    }
};
