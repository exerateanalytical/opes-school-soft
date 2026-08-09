<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * The TWO CNPS bases (docs/specs/05-hr-payroll.md 2.1, 6.1 - the N1 fix):
 *
 *   cnps_capped_base   = min(SBC, ceiling)   -- PVID and PF
 *   cnps_uncapped_base = SBC                 -- Risques Professionnels
 *
 * ONE ceiling, applying to PVID and PF only; RP is uncapped, always. A NULL
 * ceiling means UNCAPPED (4.2) - the ceiling VALUE never lives in code, it
 * arrives from the resolved PVID rate row.
 */
final class CnpsBases
{
    public static function cappedBase(int $sbc, ?int $ceilingAmount): int
    {
        return $ceilingAmount === null ? $sbc : min($sbc, $ceilingAmount);
    }

    public static function uncappedBase(int $sbc): int
    {
        return $sbc;
    }

    private function __construct()
    {
    }
}
