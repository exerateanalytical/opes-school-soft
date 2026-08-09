<?php

declare(strict_types=1);

namespace App\Modules\HR\Domain;

use RuntimeException;

/**
 * A CDD chain has hit the statutory ceiling: more than one renewal, or more
 * than two years of total elapsed duration (docs/specs/05-hr-payroll.md 3.4).
 *
 * A chain that crosses the limit converts to CDI BY OPERATION OF LAW. The
 * system does not silently allow the over-limit CDD - it is a standard
 * labour-inspection finding - and offers a single remediation: set
 * `converted_to_cdi_on` on the expiring contract and open a CDI.
 */
final class CddLimitExceeded extends RuntimeException
{
    public static function renewals(int $renewalCount): self
    {
        return new self(sprintf(
            'A CDD may be renewed at most %d time(s); this chain would reach renewal %d. '
            .'The contract converts to CDI by operation of law: set converted_to_cdi_on and open a CDI contract.',
            ContractType::CDD_MAX_RENEWALS,
            $renewalCount,
        ));
    }

    public static function duration(string $chainStartsOn, string $endsOn): self
    {
        return new self(sprintf(
            'A CDD chain may not exceed %d years of total elapsed duration; %s to %s crosses the limit. '
            .'The contract converts to CDI by operation of law: set converted_to_cdi_on and open a CDI contract.',
            ContractType::CDD_MAX_YEARS,
            $chainStartsOn,
            $endsOn,
        ));
    }
}
