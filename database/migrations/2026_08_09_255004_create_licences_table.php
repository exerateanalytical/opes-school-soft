<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/08-operations.md §4.2-§4.3 - the locally cached licence. The
 * payload+signature pair is stored verbatim and RE-VERIFIED offline on every
 * status check; the row is a cache, never an authority. No network call is
 * ever made from a status check.
 *
 * `next_check_after` and `grace_days` are parsed and stored, and used for
 * exactly one thing: deciding whether an OPPORTUNISTIC re-check (Licence
 * panel only) is worth attempting. Passing either date never changes whether
 * the licence is valid (§4.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licences', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // The canonical-JSON licence payload, exactly as signed (§4.3).
            $table->json('payload');
            // Base64 signature: ECDSA P-256/SHA-256 for source=file,
            // RSA-2048 PKCS#1 v1.5/SHA-256 for source=activation (§4.1).
            $table->text('signature');

            // SHA-256("opes-machine-fingerprint-v1|" + source), lowercase
            // hex. Empty-string (never random) when no source is readable;
            // NULL for file licences, which are not machine-bound (§4.2).
            $table->string('fingerprint', 64)->nullable();

            $table->enum('source', ['file', 'activation']);

            $table->date('expires_at')->nullable();
            $table->dateTime('next_check_after')->nullable();
            $table->unsignedSmallInteger('grace_days')->nullable();

            // Set only by the opportunistic re-check on a signed `revoked`
            // answer, or by DeactivateLicence (§4.3).
            $table->dateTime('revoked_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licences');
    }
};
