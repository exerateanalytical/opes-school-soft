<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel Sanctum's published migration, renamed into the Phase 12 series
 * (docs/plans/phase-12-13.md 12.1). The column set is Sanctum's verbatim -
 * `Laravel\Sanctum\PersonalAccessToken` reads these exact columns, so house
 * restyling here would be a fork of a vendor contract, not a style choice.
 *
 * `token` stores a SHA-256 hex digest of the plaintext token (Sanctum hashes
 * before storing; the plaintext is shown once at creation and never persisted).
 * Token abilities are permission-enum values, mirrored onto the API routes'
 * `can:` gates by the Phase 12 API agent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
