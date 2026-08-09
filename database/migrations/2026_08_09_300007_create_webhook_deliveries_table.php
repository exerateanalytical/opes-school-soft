<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 (docs/plans/phase-12-13.md 12.4): one row per webhook delivery
 * attempt sequence - the retry ledger the queued dispatcher works from.
 *
 * Exponential retry: `next_retry_at` is set on failure and the worker picks
 * up rows where it has passed; `exhausted` means the retry budget is spent
 * and a human must intervene (or resend manually).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('webhook_endpoint_id');

            $table->string('event', 80);
            $table->json('payload');

            $table->unsignedSmallInteger('attempts')->default(0);

            $table->enum('status', ['pending', 'delivered', 'failed', 'exhausted'])
                ->default('pending');

            // Last response observed, for the admin screen's diagnosis
            // column; body truncated by the dispatcher before writing.
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->text('response_body')->nullable();

            $table->dateTime('next_retry_at')->nullable();
            $table->dateTime('delivered_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'next_retry_at'], 'idx_webhook_deliveries_retry');
            $table->index('webhook_endpoint_id', 'idx_webhook_deliveries_endpoint');

            // RESTRICT: deleting an endpoint with delivery history would
            // erase the audit trail of what left the building; endpoints are
            // deactivated instead.
            $table->foreign('webhook_endpoint_id', 'fk_webhook_deliveries_endpoint')
                ->references('id')->on('webhook_endpoints')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
