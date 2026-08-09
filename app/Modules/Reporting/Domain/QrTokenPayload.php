<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

use InvalidArgumentException;
use JsonException;

/**
 * docs/specs/10-documents.md 17.1 - the compact map a verification QR
 * carries. EXACTLY these six fields and nothing else:
 *
 *   i  instance UUID (which school issued it)
 *   t  document template code (CERT-COMP)
 *   s  document serial (HA/2026/COM/000123)
 *   h  first 16 bytes of IssuedDocument.content_hash (32 hex chars)
 *   d  issue date (Y-m-d)
 *   k  signing key id
 *
 * "No student name, no matricule, no marks, no dates of birth" - the
 * constructor is the whole field list, so PII has no slot to hide in, and
 * fromJson() rejects any map with extra keys so a doctored token cannot
 * smuggle fields past the verifier either.
 */
final readonly class QrTokenPayload
{
    public function __construct(
        public string $instanceUuid,
        public string $templateCode,
        public string $serial,
        public string $contentHashPrefix,
        public string $issueDate,
        public string $keyId,
    ) {
        if (preg_match('/^[0-9a-f]{32}$/', $contentHashPrefix) !== 1) {
            throw new InvalidArgumentException(
                'contentHashPrefix must be the first 16 bytes of the SHA-256 '
                .'content hash - 32 lowercase hex characters.'
            );
        }
    }

    public static function forContentHash(
        string $instanceUuid,
        string $templateCode,
        string $serial,
        string $contentHash,
        string $issueDate,
        string $keyId,
    ): self {
        return new self(
            $instanceUuid,
            $templateCode,
            $serial,
            substr(strtolower($contentHash), 0, 32),
            $issueDate,
            $keyId,
        );
    }

    /**
     * The exact byte sequence that gets signed: fixed key order, unescaped
     * slashes (serials contain "/"). Deterministic by construction - the
     * same payload always signs the same bytes.
     */
    public function toJson(): string
    {
        return json_encode([
            'i' => $this->instanceUuid,
            't' => $this->templateCode,
            's' => $this->serial,
            'h' => $this->contentHashPrefix,
            'd' => $this->issueDate,
            'k' => $this->keyId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Fails closed: anything that is not a JSON object holding EXACTLY the
     * six string fields - no more, no fewer - is null, not an exception and
     * never a partially-trusted payload.
     */
    public static function fromJson(string $json): ?self
    {
        try {
            $decoded = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        $keys = array_keys($decoded);
        sort($keys);

        if ($keys !== ['d', 'h', 'i', 'k', 's', 't']) {
            return null;
        }

        foreach ($decoded as $value) {
            if (! is_string($value)) {
                return null;
            }
        }

        if (preg_match('/^[0-9a-f]{32}$/', $decoded['h']) !== 1) {
            return null;
        }

        return new self(
            $decoded['i'], $decoded['t'], $decoded['s'],
            $decoded['h'], $decoded['d'], $decoded['k'],
        );
    }
}
