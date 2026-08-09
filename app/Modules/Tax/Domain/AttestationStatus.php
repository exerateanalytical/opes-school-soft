<?php

declare(strict_types=1);

namespace App\Modules\Tax\Domain;

/**
 * docs/specs/03-tax-procurement.md §6.6. An `issued` attestation is
 * IMMUTABLE - corrections issue a replacement and set the original to
 * `replaced`; voiding the underlying payment forces `cancelled` in the
 * same transaction. An attestation for a payment that never happened is a
 * false tax document.
 */
enum AttestationStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Cancelled = 'cancelled';
    case Replaced = 'replaced';
}
