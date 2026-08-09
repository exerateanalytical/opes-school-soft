<?php

declare(strict_types=1);

namespace App\Modules\HR\Domain;

/**
 * docs/specs/05-hr-payroll.md 3.4. Deliberately split from WorkingTime: a CDD
 * may be full-time and a CDI may be hourly - v1 conflated the two axes.
 */
enum ContractType: string
{
    case Cdi = 'cdi';
    case Cdd = 'cdd';
    case Temporaire = 'temporaire';
    case Occasionnel = 'occasionnel';
    case Saisonnier = 'saisonnier';
    case Apprentissage = 'apprentissage';
    case Stage = 'stage';

    /**
     * The statutory CDD ceiling: max 2 years total across the renewal chain,
     * renewable once (2.3). Crossing either converts to CDI by operation of
     * law - a standard labour-inspection finding, which is why the limit is
     * enforced at save (CddLimitExceeded), never silently allowed.
     */
    public const CDD_MAX_YEARS = 2;

    public const CDD_MAX_RENEWALS = 1;

    public function isFixedTerm(): bool
    {
        return $this === self::Cdd;
    }
}
