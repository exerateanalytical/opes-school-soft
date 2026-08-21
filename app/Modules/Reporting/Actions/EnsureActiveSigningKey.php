<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Reporting\Models\DocumentSigningKey;
use App\Support\Audit\Actor;
use App\Support\Crypto\OpensslConfig;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * docs/specs/10-documents.md 17.1 - "the key pair is generated per instance
 * at setup". This is the setup: the first token ever signed provisions the
 * first ECDSA P-256 keypair, inside a transaction that re-checks under lock
 * so two racing first-signings agree on ONE key.
 *
 * Ungated: it is called from inside the signing flow, which sits behind the
 * caller's own authorization (RenderDocument authorizes before it renders).
 * Provisioning is audited so the key's birth is on the record.
 */
final class EnsureActiveSigningKey
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(?Actor $actor = null): DocumentSigningKey
    {
        $active = DocumentSigningKey::active();

        if ($active !== null) {
            return $active;
        }

        return DB::transaction(function () use ($actor): DocumentSigningKey {
            /** @var DocumentSigningKey|null $locked */
            $locked = DocumentSigningKey::query()
                ->where('is_active', true)
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            if ($locked !== null) {
                return $locked;
            }

            $key = $this->generate($actor ?? Actor::system());

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Reporting',
                auditableType: DocumentSigningKey::class,
                auditableId: (int) $key->getKey(),
                after: ['key_id' => $key->key_id, 'algorithm' => $key->algorithm],
                actor: $actor ?? Actor::system(),
            );

            return $key;
        });
    }

    /**
     * Generate and persist a fresh active P-256 keypair. Shared with
     * RotateDocumentSigningKey so there is exactly one generation routine.
     */
    public function generate(Actor $actor): DocumentSigningKey
    {
        $resource = openssl_pkey_new(OpensslConfig::options([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]));

        if ($resource === false) {
            throw new RuntimeException('openssl_pkey_new failed to generate a P-256 keypair.');
        }

        $privatePem = '';

        if (! openssl_pkey_export($resource, $privatePem, null, OpensslConfig::options())) {
            throw new RuntimeException('openssl_pkey_export failed for the new signing key.');
        }

        $details = openssl_pkey_get_details($resource);

        if ($details === false || ! isset($details['key']) || ! is_string($details['key'])) {
            throw new RuntimeException('openssl_pkey_get_details failed for the new signing key.');
        }

        return DocumentSigningKey::query()->create([
            // The token's `k` field: short, unique, meaningless - it names a
            // row, not a secret.
            'key_id' => 'opesk-'.bin2hex(random_bytes(8)),
            'private_key' => $privatePem,
            'public_key' => $details['key'],
            'algorithm' => DocumentSigningKey::ALGORITHM_ES256,
            'is_active' => true,
            'activated_at' => now(),
            'retired_at' => null,
            'created_by' => $actor->id,
        ]);
    }
}
