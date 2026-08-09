<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * The closed set of statutory deduction codes (docs/specs/05-hr-payroll.md
 * 2.1-2.2, 4.2). PVID/PF/RP are the three CNPS branches; the rest are the
 * non-CNPS statutory deductions. There is no "other" - a new levy is a
 * schema change, reviewed, not a free-text row.
 */
enum StatutoryRateCode: string
{
    case Pvid = 'PVID';
    case Pf = 'PF';
    case Rp = 'RP';
    case Irpp = 'IRPP';
    case Cac = 'CAC';
    case Cfc = 'CFC';
    case Fne = 'FNE';
    case Rav = 'RAV';
    case Tdl = 'TDL';

    /**
     * Employer-borne-only codes: an employee rate on these is a defect the
     * schema rejects (4.2 constraints 6-7).
     */
    public function isEmployerOnly(): bool
    {
        return match ($this) {
            self::Pf, self::Rp, self::Fne => true,
            default => false,
        };
    }
}
