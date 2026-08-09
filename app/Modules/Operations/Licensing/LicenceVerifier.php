<?php

declare(strict_types=1);

namespace App\Modules\Operations\Licensing;

use OpenSSLAsymmetricKey;

/**
 * Offline signature verification for both licence routes
 * (docs/specs/08-operations.md §4.1/§4.3). ECDSA P-256/SHA-256 for licence
 * FILES, RSA-2048 PKCS#1 v1.5/SHA-256 for ACTIVATION responses - both go
 * through openssl_verify over the CanonicalJson form of the payload.
 *
 * "The network is how a licence arrives; the signature is what makes it
 * trustworthy. There is no 'but it came from our server' exemption." Every
 * failure path returns false (fails closed): missing key, unparseable key,
 * un-decodable signature, wrong key type, wrong bytes.
 */
final class LicenceVerifier
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function verifyPayload(array $payload, string $signatureBase64, LicenceKeyType $keyType): bool
    {
        return $this->verify(CanonicalJson::encode($payload), $signatureBase64, $keyType);
    }

    public function verify(string $canonicalJson, string $signatureBase64, LicenceKeyType $keyType): bool
    {
        $key = $this->publicKey($keyType);

        if ($key === null) {
            return false;
        }

        // strict mode: a signature that is not clean base64 is not a
        // signature, and base64_decode's forgiving mode would happily
        // "decode" it into something verify then rejects less legibly.
        $signature = base64_decode($signatureBase64, true);

        if ($signature === false || $signature === '') {
            return false;
        }

        return openssl_verify($canonicalJson, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    private function publicKey(LicenceKeyType $keyType): ?OpenSSLAsymmetricKey
    {
        $pem = $keyType->publicKeyPem();

        if ($pem === null) {
            return null;
        }

        $key = openssl_pkey_get_public($pem);

        return $key === false ? null : $key;
    }
}
