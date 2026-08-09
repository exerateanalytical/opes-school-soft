<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

/**
 * docs/specs/10-documents.md 17.2 - the four-state verification result "and
 * nothing else". Every failure mode (bad token, unknown key, bad signature,
 * foreign instance, unknown serial, hash mismatch) collapses into NotFound
 * so the surface cannot be used to distinguish "wrong signature" from "no
 * such serial" and enumerate the series.
 */
enum VerificationStatus: string
{
    case Valid = 'valid';
    case Revoked = 'revoked';
    case Superseded = 'superseded';
    case NotFound = 'not_found';
}
