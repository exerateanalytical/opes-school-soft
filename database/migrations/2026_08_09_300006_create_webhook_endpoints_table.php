<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 (docs/plans/phase-12-13.md 12.4): outbound webhook endpoints.
 *
 * Deny-by-default: an endpoint receives ONLY the events named in its `events`
 * allow-list. There is no "subscribe to everything" flag - a new event added
 * in a later phase reaches nobody until an administrator opts an endpoint in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('name', 120);
            $table->string('url', 500);

            // The HMAC-SHA256 signing secret, ENCRYPTED at rest via the
            // model's `encrypted` cast - hence TEXT, the ciphertext envelope
            // is far longer than the secret.
            $table->text('secret');

            // Allow-listed event names, e.g. ["invoice.issued",
            // "payment.recorded", "results.published"].
            $table->json('events');

            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('created_by');

            $table->timestamps();

            $table->foreign('created_by', 'fk_webhook_endpoints_created_by')
                ->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
    }
};
