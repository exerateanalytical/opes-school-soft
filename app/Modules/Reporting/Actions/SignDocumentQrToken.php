<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Modules\Reporting\Domain\QrToken;
use App\Modules\Reporting\Domain\QrTokenPayload;
use App\Support\Audit\Actor;

/**
 * Build the OPES1.<payload>.<sig> verification token for a document being
 * issued (docs/specs/10-documents.md 17.1). Called from the render pipeline
 * for templates with carries_qr - RenderDocument has already authorized by
 * the time a serial exists, so this action carries no gate of its own.
 *
 * The payload is the 17.1 six-field map and NOTHING else: no student name,
 * no matricule, no marks, no dates of birth. QrTokenPayload's constructor
 * is the whole field list, so this action could not leak PII into the QR
 * even by mistake.
 */
final class SignDocumentQrToken
{
    public function __construct(
        private readonly ResolveInstanceUuid $instance,
        private readonly EnsureActiveSigningKey $keys,
    ) {
    }

    /**
     * @param  string  $templateCode  e.g. CERT-COMP
     * @param  string  $serial  e.g. HA/2026/COM/000123
     * @param  string  $contentHash  full SHA-256 hex of the rendered bytes
     * @param  string  $issueDate  Y-m-d
     */
    public function handle(
        string $templateCode,
        string $serial,
        string $contentHash,
        string $issueDate,
        ?Actor $actor = null,
    ): string {
        $key = $this->keys->handle($actor);

        $payload = QrTokenPayload::forContentHash(
            instanceUuid: $this->instance->handle(),
            templateCode: $templateCode,
            serial: $serial,
            contentHash: $contentHash,
            issueDate: $issueDate,
            keyId: $key->key_id,
        );

        return QrToken::sign($payload, $key->private_key);
    }
}
