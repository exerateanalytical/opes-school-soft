<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/10-documents.md 17.1 - the per-instance ECDSA P-256 keypair
 * that signs QR verification tokens (OPES1.<payload>.<sig>).
 *
 * Generated at setup with PHP's openssl_* - the same primitive family the
 * licensing stack uses, so there is one crypto stack, not two. The PUBLIC
 * key is printed on the recovery sheet and published in the About window; a
 * verifier needs nothing else to validate offline.
 *
 * Multiple rows because keys ROTATE: the token's `k` field names the key id
 * it was signed with, and old documents must keep verifying against retired
 * keys forever - retirement stops SIGNING, never verification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_signing_keys', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // The token's `k` field. Case-sensitive identifier (00-core 4).
            $table->string('key_id', 40)->collation('utf8mb4_0900_as_cs')->unique();

            // PEM private key, ENCRYPTED at rest via the model's `encrypted`
            // cast - hence TEXT, the ciphertext envelope dwarfs the PEM.
            $table->text('private_key');

            // PEM public key, plaintext by design: it is published.
            $table->text('public_key');

            // Fixed to the 17.1 primitive; a column rather than a constant
            // so a future algorithm migration is data, not archaeology.
            $table->string('algorithm', 20)->default('ES256');

            // Exactly one active key signs new tokens; enforced in the
            // Action (activate-new deactivates-old in one transaction), not
            // by a partial unique index MySQL cannot express.
            $table->boolean('is_active')->default(true);

            $table->dateTime('activated_at');
            $table->dateTime('retired_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->foreign('created_by', 'fk_signing_keys_created_by')
                ->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_signing_keys');
    }
};
