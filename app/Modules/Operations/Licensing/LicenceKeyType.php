<?php

declare(strict_types=1);

namespace App\Modules\Operations\Licensing;

/**
 * The two DELIBERATELY SPLIT verification keys of
 * docs/specs/08-operations.md §4.1: a compromise of the internet-facing
 * activation server must not be able to forge offline licence files, and
 * vice versa. Do not collapse them "for simplicity" - the split is the
 * entire point.
 *
 * Only PUBLIC halves are ever configured here; no private key of any kind
 * lives in this repository (tests generate throwaway pairs in memory).
 */
enum LicenceKeyType: string
{
    /** Offline .opeslic files and update artifacts - ECDSA P-256 / SHA-256. */
    case File = 'file';

    /** Activation-server responses - RSA-2048, PKCS#1 v1.5, SHA-256. */
    case Activation = 'activation';

    /**
     * The embedded public key (PEM), from config/opes.php. Null when the
     * key is not configured - verification then fails closed.
     */
    public function publicKeyPem(): ?string
    {
        $key = config(match ($this) {
            self::File => 'opes.licensing.licence_file_public_key',
            self::Activation => 'opes.licensing.activation_public_key',
        });

        return is_string($key) && trim($key) !== '' ? $key : null;
    }
}
