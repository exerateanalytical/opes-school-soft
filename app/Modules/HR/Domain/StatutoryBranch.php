<?php

declare(strict_types=1);

namespace App\Modules\HR\Domain;

/**
 * The statutory branches a contract exemption may suppress
 * (docs/specs/05-hr-payroll.md 3.5).
 */
enum StatutoryBranch: string
{
    case Pvid = 'PVID';
    case Pf = 'PF';
    case Rp = 'RP';
    case Irpp = 'IRPP';
    case Cfc = 'CFC';
    case Fne = 'FNE';
    case Rav = 'RAV';
    case Tdl = 'TDL';
}
