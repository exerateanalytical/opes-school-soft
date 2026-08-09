<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

use RuntimeException;

/**
 * docs/specs/10-documents.md 17.1 - the self-contained signed verification
 * token:
 *
 *   OPES1.<base64url(payload)>.<base64url(signature)>
 *
 * signature is ECDSA P-256 / SHA-256 (DER-encoded, as openssl emits it) over
 * the payload's exact JSON bytes - the same primitive family the licensing
 * stack already uses (00-core 17.1), so there is one crypto stack, not two.
 * A verifier needs nothing but the school's PUBLIC key: the token carries
 * everything else, which is what makes offline verification possible on a
 * LAN deployment with no endpoint at all.
 *
 * verify() FAILS CLOSED, mirroring Operations\Licensing\LicenceVerifier:
 * wrong prefix, un-decodable base64url, malformed payload, unparseable key,
 * wrong key, flipped byte - every failure path is null, never an exception a
 * screen could mistake for a server error and never a partially-verified
 * payload.
 */
final class QrToken
{
    public const PREFIX = 'OPES1';

    /** Sign a payload with a PEM EC private key (P-256). */
    public static function sign(QrTokenPayload $payload, string $privateKeyPem): string
    {
        $key = openssl_pkey_get_private($privateKeyPem);

        if ($key === false) {
            throw new RuntimeException('QrToken signing key is not a parseable private key.');
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_EC) {
            throw new RuntimeException('QrToken signing requires an EC (P-256) private key.');
        }

        $json = $payload->toJson();
        $signature = '';

        if (! openssl_sign($json, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('openssl_sign failed for the QR token payload.');
        }

        return self::PREFIX.'.'.self::base64UrlEncode($json).'.'.self::base64UrlEncode($signature);
    }

    /**
     * Structural decode WITHOUT signature verification - the verifier needs
     * the payload's `k` field to know WHICH public key to check against.
     * Null on any malformation.
     */
    public static function decode(string $token): ?QrTokenPayload
    {
        $parts = explode('.', trim($token));

        if (count($parts) !== 3 || $parts[0] !== self::PREFIX) {
            return null;
        }

        $json = self::base64UrlDecode($parts[1]);

        if ($json === null) {
            return null;
        }

        return QrTokenPayload::fromJson($json);
    }

    /**
     * Full verification against a PEM public key. Returns the payload ONLY
     * when the signature over the exact payload bytes checks out; null on
     * every failure path (fails closed).
     */
    public static function verify(string $token, string $publicKeyPem): ?QrTokenPayload
    {
        $parts = explode('.', trim($token));

        if (count($parts) !== 3 || $parts[0] !== self::PREFIX) {
            return null;
        }

        $json = self::base64UrlDecode($parts[1]);
        $signature = self::base64UrlDecode($parts[2]);

        if ($json === null || $signature === null || $signature === '') {
            return null;
        }

        $payload = QrTokenPayload::fromJson($json);

        if ($payload === null) {
            return null;
        }

        $key = openssl_pkey_get_public($publicKeyPem);

        if ($key === false) {
            return null;
        }

        // Signed bytes are the DECODED segment, not a re-encoding of the
        // parsed payload - so verification cannot drift from signing over
        // JSON formatting.
        if (openssl_verify($json, $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            return null;
        }

        return $payload;
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $encoded): ?string
    {
        if ($encoded === '' || preg_match('/^[A-Za-z0-9_-]+$/', $encoded) !== 1) {
            return null;
        }

        $padded = strtr($encoded, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);

        // strict mode, same reasoning as LicenceVerifier: a segment that is
        // not clean base64 is not a token segment.
        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }
}
