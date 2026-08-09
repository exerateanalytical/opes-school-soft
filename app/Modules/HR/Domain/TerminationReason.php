<?php

declare(strict_types=1);

namespace App\Modules\HR\Domain;

/**
 * docs/specs/05-hr-payroll.md 13.1. The reason drives the settlement shape:
 * licenciement owes an indemnite, faute lourde forfeits notice, fin de CDD
 * owes the precarity end-of-contract treatment.
 */
enum TerminationReason: string
{
    case Resignation = 'resignation';
    case Licenciement = 'licenciement';
    case LicenciementFauteLourde = 'licenciement_faute_lourde';
    case FinCdd = 'fin_cdd';
    case Retirement = 'retirement';
    case Death = 'death';
    case Mutual = 'mutual';
}
